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
			'search'        => '',
			'featured_only' => false,
			'limit'         => -1,
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

		if ( $args['featured_only'] && $opts['featured_field'] !== '' ) {
			$query_args['meta_query'] = [
				'relation' => 'OR',
				[
					'key'     => $opts['featured_field'],
					'value'   => '1',
					'compare' => '=',
				],
				[
					'key'     => $opts['featured_field'],
					'value'   => 'yes',
					'compare' => '=',
				],
				[
					'key'     => $opts['featured_field'],
					'value'   => 'true',
					'compare' => '=',
				],
			];
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

		$thumb = get_the_post_thumbnail_url( $post->ID, $opts['thumbnail_size'] ?: 'medium' );

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
			'featured'  => self::is_featured( $post->ID, $opts ),
		];
	}

	private static function is_featured( int $post_id, array $opts ): bool {
		if ( $opts['featured_field'] === '' ) {
			return false;
		}
		$val = get_post_meta( $post_id, $opts['featured_field'], true );
		if ( is_array( $val ) ) {
			$val = reset( $val );
		}
		$val = strtolower( (string) $val );
		return in_array( $val, [ '1', 'yes', 'true', 'on' ], true );
	}
}
