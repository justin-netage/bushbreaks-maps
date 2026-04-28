<?php
namespace Bushbreaks_Maps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads accommodations from the configured Pods custom post type.
 * Pods stores most simple fields as standard post meta, so a meta lookup
 * works whether or not the Pods plugin is active at runtime.
 */
class Repository {

	public static function query( array $args = [] ): array {
		$opts = Settings::all();

		$defaults = [
			'search' => '',
			'limit'  => -1,
		];
		$args = array_merge( $defaults, $args );

		$query_args = [
			'post_type'      => $opts['post_type'],
			'post_status'    => 'publish',
			'posts_per_page' => (int) $args['limit'],
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		];

		if ( $args['search'] !== '' ) {
			$query_args['s'] = $args['search'];
		}

		$query = new \WP_Query( $query_args );

		$results = [];
		foreach ( $query->posts as $post ) {
			$item = self::format_post( $post, $opts );
			if ( $item !== null ) {
				$results[] = $item;
			}
		}

		wp_reset_postdata();
		return $results;
	}

	private static function format_post( \WP_Post $post, array $opts ): ?array {
		$lat = get_post_meta( $post->ID, $opts['lat_field'], true );
		$lng = get_post_meta( $post->ID, $opts['lng_field'], true );

		$lat = is_numeric( $lat ) ? (float) $lat : null;
		$lng = is_numeric( $lng ) ? (float) $lng : null;

		$address = '';
		if ( $opts['address_field'] !== '' ) {
			$address = (string) get_post_meta( $post->ID, $opts['address_field'], true );
		}

		$size  = $opts['thumbnail_size'] ?: 'medium';
		$thumb = self::resolve_image( $post->ID, (string) ( $opts['image_field'] ?? '' ), $size );
		if ( $thumb === '' ) {
			$thumb = (string) get_the_post_thumbnail_url( $post->ID, $size );
		}

		$excerpt = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 28 );

		return [
			'id'        => $post->ID,
			'title'     => get_the_title( $post ),
			'permalink' => get_permalink( $post ),
			'lat'       => $lat,
			'lng'       => $lng,
			'address'   => $address,
			'thumbnail' => $thumb ?: '',
			'excerpt'   => $excerpt,
		];
	}

	/**
	 * Resolve a Pods image/file field to a URL. Handles attachment ID,
	 * array of attachment data (single or multiple), and plain URL strings.
	 */
	private static function resolve_image( int $post_id, string $field, string $size ): string {
		if ( $field === '' ) {
			return '';
		}

		$val = get_post_meta( $post_id, $field, true );
		if ( empty( $val ) ) {
			return '';
		}

		if ( is_string( $val ) ) {
			if ( ctype_digit( $val ) ) {
				$url = wp_get_attachment_image_url( (int) $val, $size );
				return $url ?: '';
			}
			if ( filter_var( $val, FILTER_VALIDATE_URL ) ) {
				return $val;
			}
			return '';
		}

		if ( is_numeric( $val ) ) {
			$url = wp_get_attachment_image_url( (int) $val, $size );
			return $url ?: '';
		}

		if ( is_array( $val ) ) {
			// Multiple-image field stores an array of attachment arrays — take the first.
			$first = reset( $val );
			if ( is_array( $first ) && ( isset( $first['ID'] ) || isset( $first['id'] ) || isset( $first['guid'] ) ) ) {
				$val = $first;
			}

			$id = $val['ID'] ?? $val['id'] ?? null;
			if ( is_numeric( $id ) ) {
				$url = wp_get_attachment_image_url( (int) $id, $size );
				if ( $url ) {
					return $url;
				}
			}
			if ( isset( $val['guid'] ) && is_string( $val['guid'] ) && filter_var( $val['guid'], FILTER_VALIDATE_URL ) ) {
				return $val['guid'];
			}
		}

		return '';
	}
}
