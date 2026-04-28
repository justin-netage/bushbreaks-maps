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

		$base_args = [
			'post_type'     => $opts['post_type'],
			'post_status'   => 'publish',
			'orderby'       => 'title',
			'order'         => 'ASC',
			'no_found_rows' => true,
		];

		if ( $args['search'] === '' ) {
			$query = new \WP_Query(
				array_merge(
					$base_args,
					[ 'posts_per_page' => (int) $args['limit'] ]
				)
			);
			$posts = $query->posts;
		} else {
			$ids = self::collect_search_ids( $args['search'], $opts, $base_args );
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
	 * tagged with destination terms whose name matches the query.
	 */
	private static function collect_search_ids( string $term, array $opts, array $base_args ): array {
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

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
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
			'title'     => get_the_title( $post ),
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
