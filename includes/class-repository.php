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
			'search'          => '',
			'limit'           => -1,
			'category_ids'    => [],
			'destination_ids' => [],
		];
		$args = array_merge( $defaults, $args );

		$base_args = [
			'post_type'     => $opts['post_type'],
			'post_status'   => 'publish',
			'orderby'       => 'title',
			'order'         => 'ASC',
			'no_found_rows' => true,
		];

		$has_search      = $args['search'] !== '';
		$has_category    = ! empty( $args['category_ids'] );
		$has_destination = ! empty( $args['destination_ids'] );

		if ( ! $has_search && ! $has_category && ! $has_destination ) {
			$query = new \WP_Query(
				array_merge(
					$base_args,
					[ 'posts_per_page' => (int) $args['limit'] ]
				)
			);
			$posts = $query->posts;
		} else {
			$ids = self::collect_search_ids(
				$args['search'],
				$opts,
				$base_args,
				(array) $args['category_ids'],
				(array) $args['destination_ids']
			);
			if ( empty( $ids ) ) {
				return [];
			}
			if ( $args['limit'] > 0 ) {
				$ids = array_slice( $ids, 0, (int) $args['limit'] );
			}
			$query = new \WP_Query(
				array_merge(
					$base_args,
					[
						'post__in'       => $ids,
						'posts_per_page' => count( $ids ),
					]
				)
			);
			$posts = $query->posts;
		}

		$results = [];
		foreach ( $posts as $post ) {
			$item = self::format_post( $post, $opts );
			if ( $item !== null && $item['lat'] !== null && $item['lng'] !== null ) {
				$results[] = $item;
			}
		}

		wp_reset_postdata();
		return $results;
	}

	/**
	 * Build the ID set for a search: title/content matches UNION posts
	 * tagged with destination terms whose name matches the query UNION
	 * posts whose Pods location field contains the query.
	 */
	private static function collect_search_ids( string $term, array $opts, array $base_args, array $category_ids = [], array $destination_ids = [] ): array {
		$ids = null; // null = no constraint applied yet

		if ( $term !== '' ) {
			$text_query = new \WP_Query(
				array_merge(
					$base_args,
					[
						'posts_per_page' => -1,
						'fields'         => 'ids',
						's'              => $term,
					]
				)
			);
			$ids = $text_query->posts;

			$taxonomy = (string) ( $opts['destination_taxonomy'] ?? '' );
			if ( $taxonomy !== '' && taxonomy_exists( $taxonomy ) ) {
				$matching_terms = get_terms(
					[
						'taxonomy'   => $taxonomy,
						'search'     => $term,
						'hide_empty' => false,
						'fields'     => 'ids',
					]
				);

				if ( ! is_wp_error( $matching_terms ) && ! empty( $matching_terms ) ) {
					$tax_query = new \WP_Query(
						array_merge(
							$base_args,
							[
								'posts_per_page' => -1,
								'fields'         => 'ids',
								'tax_query'      => [
									[
										'taxonomy' => $taxonomy,
										'field'    => 'term_id',
										'terms'    => $matching_terms,
									],
								],
							]
						)
					);
					$ids = array_merge( $ids, $tax_query->posts );
				}
			}

			$location_field = (string) ( $opts['location_field'] ?? '' );
			if ( $location_field !== '' ) {
				$location_query = new \WP_Query(
					array_merge(
						$base_args,
						[
							'posts_per_page' => -1,
							'fields'         => 'ids',
							'meta_query'     => [
								[
									'key'     => $location_field,
									'value'   => $term,
									'compare' => 'LIKE',
								],
							],
						]
					)
				);
				$ids = array_merge( $ids, $location_query->posts );
			}
		}

		// Intersect with destination filter (any-of semantics within filter)
		if ( ! empty( $destination_ids ) ) {
			$dest_taxonomy = (string) ( $opts['destination_taxonomy'] ?? '' );
			if ( $dest_taxonomy !== '' && taxonomy_exists( $dest_taxonomy ) ) {
				$dest_query = new \WP_Query(
					array_merge(
						$base_args,
						[
							'posts_per_page' => -1,
							'fields'         => 'ids',
							'tax_query'      => [
								[
									'taxonomy' => $dest_taxonomy,
									'field'    => 'term_id',
									'terms'    => array_map( 'intval', $destination_ids ),
								],
							],
						]
					)
				);
				$dest_post_ids = $dest_query->posts;

				if ( $ids === null ) {
					$ids = $dest_post_ids;
				} else {
					$ids = array_intersect( $ids, $dest_post_ids );
				}
			}
		}

		// Intersect with category filter (any-of semantics within filter)
		if ( ! empty( $category_ids ) ) {
			$cat_taxonomy = (string) ( $opts['category_taxonomy'] ?? '' );
			if ( $cat_taxonomy !== '' && taxonomy_exists( $cat_taxonomy ) ) {
				$cat_query = new \WP_Query(
					array_merge(
						$base_args,
						[
							'posts_per_page' => -1,
							'fields'         => 'ids',
							'tax_query'      => [
								[
									'taxonomy' => $cat_taxonomy,
									'field'    => 'term_id',
									'terms'    => array_map( 'intval', $category_ids ),
								],
							],
						]
					)
				);
				$cat_ids = $cat_query->posts;

				if ( $ids === null ) {
					$ids = $cat_ids;
				} else {
					$ids = array_intersect( $ids, $cat_ids );
				}
			}
		}

		return array_values( array_unique( array_map( 'intval', $ids ?: [] ) ) );
	}

	/**
	 * Map of term_id => direct count, restricted to the supplied post IDs.
	 * Used to filter filter dropdowns so terms whose only accommodations are
	 * missing coordinates (i.e. hidden from the map) don't appear either.
	 */
	public static function visible_term_counts( string $taxonomy, array $visible_post_ids ): array {
		if ( $taxonomy === '' || empty( $visible_post_ids ) ) {
			return [];
		}
		global $wpdb;

		$ids = array_map( 'intval', $visible_post_ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params = array_merge( [ $taxonomy ], $ids );

		$sql = $wpdb->prepare(
			"SELECT tt.term_id, COUNT(DISTINCT tr.object_id) AS cnt
			 FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 WHERE tt.taxonomy = %s
			   AND tr.object_id IN ($placeholders)
			 GROUP BY tt.term_id",
			$params
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$counts = [];
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['term_id'] ] = (int) $row['cnt'];
		}
		return $counts;
	}

	/**
	 * Roll direct term counts up the parent chain so a parent's effective
	 * count = own + sum(descendants). Used to keep provinces visible when
	 * any of their reserves has accommodations on the map.
	 */
	public static function pad_subtree_counts( array $terms, array $direct_counts ): array {
		$by_parent = [];
		foreach ( $terms as $t ) {
			$pid = (int) ( is_object( $t ) ? ( $t->parent ?? 0 ) : ( $t['parent'] ?? 0 ) );
			$by_parent[ $pid ][] = $t;
		}

		$padded = [];
		$compute = function ( $term_id ) use ( &$compute, $by_parent, $direct_counts, &$padded ) {
			if ( isset( $padded[ $term_id ] ) ) {
				return $padded[ $term_id ];
			}
			$sum = (int) ( $direct_counts[ $term_id ] ?? 0 );
			foreach ( $by_parent[ $term_id ] ?? [] as $child ) {
				$sum += $compute( (int) ( is_object( $child ) ? $child->term_id : $child['id'] ) );
			}
			$padded[ $term_id ] = $sum;
			return $sum;
		};

		foreach ( $terms as $t ) {
			$compute( (int) ( is_object( $t ) ? $t->term_id : $t['id'] ) );
		}

		return $padded;
	}

	/**
	 * List accommodations that have no usable latitude/longitude.
	 * Returns id, title, edit link, and the last sync status.
	 */
	public static function find_missing_coords(): array {
		$opts = Settings::all();

		$query = new \WP_Query(
			[
				'post_type'      => $opts['post_type'],
				'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		$missing = [];
		foreach ( $query->posts as $pid ) {
			$lat = get_post_meta( (int) $pid, $opts['lat_field'], true );
			$lng = get_post_meta( (int) $pid, $opts['lng_field'], true );
			if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
				$missing[] = [
					'id'        => (int) $pid,
					'title'     => get_the_title( $pid ),
					'status'    => (string) get_post_meta( (int) $pid, Coords_Sync::META_STATUS, true ),
					'edit_link' => get_edit_post_link( (int) $pid, 'raw' ),
				];
			}
		}

		wp_reset_postdata();
		return $missing;
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
			'title'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'permalink' => get_permalink( $post ),
			'lat'       => $lat,
			'lng'       => $lng,
			'address'   => $address,
			'thumbnail' => $thumb ?: '',
			'excerpt'   => $excerpt,
			'pricing'   => self::format_pricing( $post->ID, $opts ),
		];
	}

	private static function format_pricing( int $post_id, array $opts ): array {
		$currency       = (string) ( $opts['currency_symbol']         ?? '' );
		$normal_field   = (string) ( $opts['normal_price_field']      ?? '' );
		$special_field  = (string) ( $opts['special_price_field']     ?? '' );
		$desc_field     = (string) ( $opts['price_description_field'] ?? '' );
		$from_field     = (string) ( $opts['valid_from_field']        ?? '' );
		$until_field    = (string) ( $opts['valid_until_field']       ?? '' );

		$normal_raw  = $normal_field  !== '' ? get_post_meta( $post_id, $normal_field,  true ) : '';
		$special_raw = $special_field !== '' ? get_post_meta( $post_id, $special_field, true ) : '';
		$desc_raw    = $desc_field    !== '' ? get_post_meta( $post_id, $desc_field,    true ) : '';
		$from_raw    = $from_field    !== '' ? get_post_meta( $post_id, $from_field,    true ) : '';
		$until_raw   = $until_field   !== '' ? get_post_meta( $post_id, $until_field,   true ) : '';

		$normal  = self::parse_amount( $normal_raw );
		$special = self::parse_amount( $special_raw );
		$unit    = is_scalar( $desc_raw ) ? trim( (string) $desc_raw ) : '';
		$unit    = self::map_price_unit( $unit );

		$discount = null;
		if ( $normal !== null && $special !== null && $special < $normal ) {
			$discount = (int) round( ( ( $normal - $special ) / $normal ) * 100 );
		}

		$from  = self::format_date_safe( (string) $from_raw );
		$until = self::format_date_safe( (string) $until_raw );

		return [
			'normal'      => $normal  !== null ? self::format_money( $normal,  $currency ) : '',
			'special'     => $special !== null ? self::format_money( $special, $currency ) : '',
			'unit'        => ( $normal !== null || $special !== null ) ? $unit : '',
			'discount'    => $discount,
			'valid_label' => $special !== null ? self::valid_label( $from, $until ) : '',
		];
	}

	/**
	 * Parse a Pods currency value into a positive float, or null.
	 * Tolerates formatted strings such as "3,100", "R 3 100", "3,100.50".
	 */
	private static function parse_amount( $raw ): ?float {
		if ( $raw === null || $raw === '' || ! is_scalar( $raw ) ) {
			return null;
		}
		$clean = preg_replace( '/[^\d.\-]/', '', (string) $raw );
		if ( $clean === '' || ! is_numeric( $clean ) ) {
			return null;
		}
		$val = (float) $clean;
		return $val > 0 ? $val : null;
	}

	/**
	 * Display-friendly mapping for price_description abbreviations.
	 * Filterable so customers can extend without editing the plugin.
	 */
	private static function map_price_unit( string $raw ): string {
		if ( $raw === '' ) {
			return '';
		}
		$map = apply_filters(
			'bushbreaks_maps_price_unit_map',
			[
				'ppn' => 'pppn sharing',
			]
		);
		$key = strtolower( $raw );
		return isset( $map[ $key ] ) ? (string) $map[ $key ] : $raw;
	}

	private static function format_money( float $amount, string $currency ): string {
		$decimals  = ( floor( $amount ) === $amount ) ? 0 : 2;
		$formatted = number_format( $amount, $decimals, '.', ',' );
		$currency  = trim( $currency );
		return $currency !== '' ? $currency . ' ' . $formatted : $formatted;
	}

	private static function format_date_safe( string $value ): string {
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}

		$ts = false;
		foreach ( [ 'd-m-Y', 'Y-m-d', 'd/m/Y', 'Y-m-d H:i:s' ] as $fmt ) {
			$dt = \DateTime::createFromFormat( $fmt, $value );
			if ( $dt instanceof \DateTime ) {
				$ts = $dt->getTimestamp();
				break;
			}
		}
		if ( $ts === false ) {
			$ts = strtotime( $value );
		}
		if ( $ts === false ) {
			return '';
		}

		$format = (string) get_option( 'date_format', 'd M Y' );
		return date_i18n( $format, $ts );
	}

	private static function valid_label( string $from, string $until ): string {
		if ( $from !== '' && $until !== '' ) {
			/* translators: 1: start date, 2: end date */
			return sprintf( __( 'Valid %1$s – %2$s', 'bushbreaks-maps' ), $from, $until );
		}
		if ( $from !== '' ) {
			/* translators: %s: start date */
			return sprintf( __( 'Valid from %s', 'bushbreaks-maps' ), $from );
		}
		if ( $until !== '' ) {
			/* translators: %s: end date */
			return sprintf( __( 'Valid until %s', 'bushbreaks-maps' ), $until );
		}
		return '';
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
