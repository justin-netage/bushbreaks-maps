<?php
namespace Bushbreaks_Maps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks into save_post to keep lat/lng meta in sync with the
 * configured iframe / location fields. Caches by hashing the source,
 * so unchanged inputs skip work.
 */
class Coords_Sync {

	public const META_IFRAME_HASH   = '_bbm_iframe_hash';
	public const META_LOCATION_HASH = '_bbm_location_hash';
	public const META_SOURCE        = '_bbm_coord_source';
	public const META_STATUS        = '_bbm_coord_status';

	public function register(): void {
		add_action( 'save_post', [ $this, 'on_save_post' ], 50, 2 );
		add_action( 'wp_ajax_bushbreaks_maps_backfill', [ $this, 'ajax_backfill' ] );
	}

	public function on_save_post( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$opts = Settings::all();
		if ( $post->post_type !== $opts['post_type'] ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$this->resolve( $post_id, $opts );
	}

	/**
	 * Resolve coordinates for one post. Returns a status string:
	 * 'iframe' | 'geocode' | 'unchanged' | 'no-source' | 'iframe-parse-failed' | 'geocode-no-results'.
	 */
	public function resolve( int $post_id, array $opts ): string {
		$iframe_field   = $opts['iframe_field']   ?? '';
		$location_field = $opts['location_field'] ?? '';
		$lat_field      = $opts['lat_field'];
		$lng_field      = $opts['lng_field'];

		$iframe = $iframe_field !== '' ? trim( (string) get_post_meta( $post_id, $iframe_field, true ) ) : '';

		if ( $iframe !== '' ) {
			$iframe_hash        = md5( $iframe );
			$stored_iframe_hash = (string) get_post_meta( $post_id, self::META_IFRAME_HASH, true );
			$source             = (string) get_post_meta( $post_id, self::META_SOURCE, true );

			if ( $iframe_hash === $stored_iframe_hash && $source === 'iframe' ) {
				return 'unchanged';
			}

			$coords = Geocoder::parse_iframe( $iframe );
			if ( $coords ) {
				$this->store( $post_id, $coords, $lat_field, $lng_field );
				update_post_meta( $post_id, self::META_IFRAME_HASH, $iframe_hash );
				delete_post_meta( $post_id, self::META_LOCATION_HASH );
				update_post_meta( $post_id, self::META_SOURCE, 'iframe' );
				update_post_meta( $post_id, self::META_STATUS, 'ok' );
				return 'iframe';
			}

			update_post_meta( $post_id, self::META_STATUS, 'iframe-parse-failed' );
			// fall through to geocode
		}

		$location = $location_field !== '' ? trim( (string) get_post_meta( $post_id, $location_field, true ) ) : '';

		if ( $location === '' ) {
			if ( $iframe === '' ) {
				update_post_meta( $post_id, self::META_STATUS, 'no-source' );
				return 'no-source';
			}
			return 'iframe-parse-failed';
		}

		$location_hash        = md5( $location );
		$stored_location_hash = (string) get_post_meta( $post_id, self::META_LOCATION_HASH, true );
		$source               = (string) get_post_meta( $post_id, self::META_SOURCE, true );

		if ( $location_hash === $stored_location_hash && $source === 'geocode' ) {
			return 'unchanged';
		}

		$coords = Geocoder::geocode( $location );
		if ( ! $coords ) {
			update_post_meta( $post_id, self::META_STATUS, 'geocode-no-results' );
			return 'geocode-no-results';
		}

		$this->store( $post_id, $coords, $lat_field, $lng_field );
		update_post_meta( $post_id, self::META_LOCATION_HASH, $location_hash );
		delete_post_meta( $post_id, self::META_IFRAME_HASH );
		update_post_meta( $post_id, self::META_SOURCE, 'geocode' );
		update_post_meta( $post_id, self::META_STATUS, 'ok' );
		return 'geocode';
	}

	private function store( int $post_id, array $coords, string $lat_field, string $lng_field ): void {
		update_post_meta( $post_id, $lat_field, $coords['lat'] );
		update_post_meta( $post_id, $lng_field, $coords['lng'] );
	}

	public function ajax_backfill(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( 'bushbreaks_maps_backfill', 'nonce' );

		$opts   = Settings::all();
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$batch  = 5;

		$query = new \WP_Query(
			[
				'post_type'      => $opts['post_type'],
				'post_status'    => 'any',
				'posts_per_page' => $batch,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			]
		);

		$total              = (int) $query->found_posts;
		$processed          = [];
		$last_called_remote = false;

		foreach ( $query->posts as $pid ) {
			if ( $last_called_remote ) {
				usleep( 1100000 ); // ~1.1s — respect Nominatim's 1 req/sec policy
			}
			$result               = $this->resolve( (int) $pid, $opts );
			$last_called_remote   = in_array( $result, [ 'geocode', 'geocode-no-results' ], true );
			$processed[]          = [ 'id' => (int) $pid, 'result' => $result ];
		}

		wp_reset_postdata();

		$next = $offset + count( $processed );
		wp_send_json_success(
			[
				'total'     => $total,
				'next'      => $next,
				'done'      => count( $processed ) === 0 || $next >= $total,
				'processed' => $processed,
			]
		);
	}
}
