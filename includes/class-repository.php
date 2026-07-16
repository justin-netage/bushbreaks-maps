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

	// Meta's catalog/dynamic-ad placements crop every image to a single
	// aspect ratio; source photos of mismatched ratios each get cropped
	// differently, which is what looks "disproportionate" in Commerce
	// Manager and the ad carousel. Emitting one consistent, pre-cropped
	// square size in the feed removes that guesswork.
	private const FEED_IMAGE_SIZE = 'bushbreaks_feed';

	// Meta's own recommended minimum for catalog images. Anything smaller
	// gets upscaled to fill FEED_IMAGE_SIZE regardless — flagged separately
	// so an admin can swap in a better source photo instead.
	private const MIN_FEED_DIM = 500;

	public static function register(): void {
		add_action( 'init', [ __CLASS__, 'register_image_size' ] );
		add_action( 'wp_ajax_bushbreaks_maps_regen_feed_images', [ __CLASS__, 'ajax_regen_feed_images' ] );
	}

	public static function register_image_size(): void {
		add_image_size( self::FEED_IMAGE_SIZE, 1200, 1200, true );
	}

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
			$limit         = (int) $args['limit'];
			$featured_ids  = array_values(
				array_filter(
					array_map( 'intval', (array) ( $opts['featured_post_ids'] ?? [] ) ),
					function ( $id ) {
						return $id > 0;
					}
				)
			);

			$posts = [];

			if ( ! empty( $featured_ids ) ) {
				$featured_query = new \WP_Query(
					[
						'post_type'      => $base_args['post_type'],
						'post_status'    => $base_args['post_status'],
						'post__in'       => $featured_ids,
						'orderby'        => 'post__in',
						'posts_per_page' => count( $featured_ids ),
						'no_found_rows'  => true,
					]
				);
				$posts = $featured_query->posts;

				if ( $limit > 0 && count( $posts ) > $limit ) {
					$posts = array_slice( $posts, 0, $limit );
				}
			}

			$remaining = ( $limit > 0 ) ? ( $limit - count( $posts ) ) : -1;
			if ( $remaining !== 0 ) {
				$fill_args = array_merge(
					$base_args,
					[
						'posts_per_page' => $remaining > 0 ? $remaining : -1,
					]
				);
				if ( ! empty( $featured_ids ) ) {
					$fill_args['post__not_in'] = $featured_ids;
				}
				$fill_query = new \WP_Query( $fill_args );
				$posts      = array_merge( $posts, $fill_query->posts );
			}
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
	 * Build the ID set for a search: title matches UNION posts tagged
	 * with destination terms whose name matches the query UNION posts
	 * whose Pods location field contains the query.
	 */
	private static function collect_search_ids( string $term, array $opts, array $base_args, array $category_ids = [], array $destination_ids = [] ): array {
		$ids = null; // null = no constraint applied yet

		if ( $term !== '' ) {
			$tokens = self::tokenize_search( $term );
			if ( empty( $tokens ) ) {
				return [];
			}

			$post_query = new \WP_Query(
				array_merge(
					$base_args,
					[
						'posts_per_page'         => -1,
						'fields'                 => 'ids',
						'update_post_term_cache' => true,
						'update_post_meta_cache' => true,
					]
				)
			);

			$dest_taxonomy = (string) ( $opts['destination_taxonomy'] ?? '' );
			$loc_field     = (string) ( $opts['location_field'] ?? '' );

			$matches    = [];
			$haystacks  = [];
			foreach ( $post_query->posts as $post_id ) {
				$pid = (int) $post_id;
				$parts = [
					(string) get_the_title( $pid ),
				];
				if ( $loc_field !== '' ) {
					$parts[] = (string) get_post_meta( $pid, $loc_field, true );
				}
				if ( $dest_taxonomy !== '' && taxonomy_exists( $dest_taxonomy ) ) {
					$tlist = get_the_terms( $pid, $dest_taxonomy );
					if ( ! is_wp_error( $tlist ) && is_array( $tlist ) ) {
						foreach ( $tlist as $t ) {
							$parts[] = (string) $t->name;
						}
					}
				}
				$haystack = mb_strtolower(
					implode( ' ', array_filter( $parts, function ( $p ) { return $p !== ''; } ) ),
					'UTF-8'
				);
				$haystacks[ $pid ] = $haystack;

				$all_match = true;
				foreach ( $tokens as $tok ) {
					if ( mb_strpos( $haystack, $tok, 0, 'UTF-8' ) === false ) {
						$all_match = false;
						break;
					}
				}
				if ( $all_match ) {
					$matches[] = $pid;
				}
			}

			// Fuzzy fallback: if no exact-substring matches, try a Levenshtein
			// pass so common typos (e.g. "Pilanesbrug" -> "Pilanesberg") still
			// return results.
			if ( empty( $matches ) ) {
				foreach ( $haystacks as $pid => $haystack ) {
					if ( self::fuzzy_all_tokens_match( $tokens, $haystack ) ) {
						$matches[] = (int) $pid;
					}
				}
			}

			$ids = $matches;
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
	 * True when every token has at least one word in the haystack within
	 * Levenshtein tolerance. Each token must be 3+ chars to avoid noisy
	 * matches on prepositions and stop words.
	 */
	private static function fuzzy_all_tokens_match( array $tokens, string $haystack ): bool {
		if ( $haystack === '' ) {
			return false;
		}
		$words = preg_split( '/\s+/u', $haystack, -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $words ) ) {
			return false;
		}
		foreach ( $tokens as $tok ) {
			if ( mb_strlen( $tok, 'UTF-8' ) < 3 ) {
				return false;
			}
			$hit = false;
			foreach ( $words as $w ) {
				if ( self::within_distance( $tok, (string) $w ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Length-normalized Levenshtein. Tolerates ~30% character difference,
	 * which catches single-character typos in 4+ character words.
	 */
	private static function within_distance( string $a, string $b ): bool {
		$la = strlen( $a );
		$lb = strlen( $b );
		if ( $la === 0 || $lb === 0 || $la > 64 || $lb > 64 ) {
			return false;
		}
		if ( abs( $la - $lb ) > 3 ) {
			return false;
		}
		$dist = levenshtein( $a, $b );
		return ( $dist / max( $la, $lb ) ) <= 0.3;
	}

	private static function tokenize_search( string $term ): array {
		$term = mb_strtolower( trim( $term ), 'UTF-8' );
		if ( $term === '' ) {
			return [];
		}
		$tokens = preg_split( '/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY );
		$tokens = array_filter( (array) $tokens, function ( $t ) {
			return $t !== '';
		} );
		return array_values( array_unique( $tokens ) );
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

	/**
	 * List feed images (main, gallery, featured-image fallback) whose
	 * original is smaller than Meta's recommended minimum on either
	 * dimension. Upscaling can't recover missing detail — these are the
	 * ones that need an actual better source photo.
	 */
	public static function find_low_res_feed_images(): array {
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

		$flagged = [];
		foreach ( $query->posts as $pid ) {
			$post_id   = (int) $pid;
			$ids       = self::gallery_field_attachment_ids( $post_id, (string) ( $opts['feed_gallery_field'] ?? '' ) );
			$single_id = self::single_field_attachment_id( $post_id, (string) ( $opts['image_field'] ?? '' ) );
			if ( $single_id !== null ) {
				$ids[] = $single_id;
			}
			$thumb_id = get_post_thumbnail_id( $post_id );
			if ( $thumb_id ) {
				$ids[] = (int) $thumb_id;
			}

			foreach ( array_unique( $ids ) as $attachment_id ) {
				$attachment_meta = wp_get_attachment_metadata( $attachment_id );
				$width           = (int) ( $attachment_meta['width'] ?? 0 );
				$height          = (int) ( $attachment_meta['height'] ?? 0 );
				if ( $width === 0 || $height === 0 ) {
					continue;
				}
				if ( $width < self::MIN_FEED_DIM || $height < self::MIN_FEED_DIM ) {
					$flagged[] = [
						'post_id'   => $post_id,
						'title'     => get_the_title( $post_id ),
						'edit_link' => get_edit_post_link( $post_id, 'raw' ),
						'width'     => $width,
						'height'    => $height,
					];
				}
			}
		}

		wp_reset_postdata();
		return $flagged;
	}

	/**
	 * Flatten every published listing into the raw fields the Meta travel
	 * (Hotels / Destinations) and product feeds need. Prices are raw floats
	 * (or null); coordinates are floats (or null) and the caller decides
	 * whether a missing location disqualifies the listing. Province/reserve
	 * come from the destination taxonomy hierarchy and categories from the
	 * category taxonomy.
	 */
	public static function listing_rows(): array {
		$opts = Settings::all();

		$query = new \WP_Query(
			[
				'post_type'      => $opts['post_type'],
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);

		$lat_field     = (string) ( $opts['lat_field']           ?? '' );
		$lng_field     = (string) ( $opts['lng_field']           ?? '' );
		$addr_field    = (string) ( $opts['address_field']       ?? '' );
		$loc_field     = (string) ( $opts['location_field']      ?? '' );
		$city_field    = (string) ( $opts['feed_city_field']     ?? '' );
		$star_field    = (string) ( $opts['feed_star_rating_field'] ?? '' );
		$normal_field  = (string) ( $opts['normal_price_field']  ?? '' );
		$special_field = (string) ( $opts['special_price_field'] ?? '' );
		$dest_tax      = (string) ( $opts['destination_taxonomy'] ?? '' );
		$cat_tax       = (string) ( $opts['category_taxonomy'] ?? '' );
		$features_src  = (string) ( $opts['feed_features_field'] ?? '' );
		$break_type_src = (string) ( $opts['feed_break_type_field'] ?? '' );
		$gallery_field = (string) ( $opts['feed_gallery_field'] ?? '' );
		$desc_field    = (string) ( $opts['feed_description_field'] ?? '' );

		$rows = [];
		foreach ( $query->posts as $post ) {
			$image = self::resolve_image( $post->ID, (string) ( $opts['image_field'] ?? '' ), self::FEED_IMAGE_SIZE );
			if ( $image === '' ) {
				$thumb_id = get_post_thumbnail_id( $post->ID );
				$image    = $thumb_id ? self::attachment_image_url( (int) $thumb_id, self::FEED_IMAGE_SIZE ) : '';
			}

			$lat = $lat_field !== '' ? get_post_meta( $post->ID, $lat_field, true ) : '';
			$lng = $lng_field !== '' ? get_post_meta( $post->ID, $lng_field, true ) : '';
			$lat = is_numeric( $lat ) ? (float) $lat : null;
			$lng = is_numeric( $lng ) ? (float) $lng : null;

			$normal  = $normal_field  !== '' ? self::parse_amount( get_post_meta( $post->ID, $normal_field,  true ) ) : null;
			$special = $special_field !== '' ? self::parse_amount( get_post_meta( $post->ID, $special_field, true ) ) : null;

			// Street/address line: prefer a dedicated address field, fall back
			// to the geocoded location text.
			$addr1 = $addr_field !== '' ? (string) get_post_meta( $post->ID, $addr_field, true ) : '';
			if ( $addr1 === '' && $loc_field !== '' ) {
				$addr1 = (string) get_post_meta( $post->ID, $loc_field, true );
			}

			$city = $city_field !== '' ? (string) get_post_meta( $post->ID, $city_field, true ) : '';

			$star = null;
			if ( $star_field !== '' ) {
				$raw = get_post_meta( $post->ID, $star_field, true );
				if ( is_numeric( $raw ) ) {
					$star = (float) $raw;
				}
			}

			$geo        = self::destination_geo( $post->ID, $dest_tax );
			$categories = self::term_names( $post->ID, $cat_tax );
			$features   = self::feature_label( $post->ID, $features_src );
			$break_type = self::feature_label( $post->ID, $break_type_src );

			// Prefer the configured description field (e.g. "About Lodge");
			// fall back to the excerpt, then trimmed content.
			$description = $desc_field !== '' ? (string) get_post_meta( $post->ID, $desc_field, true ) : '';
			if ( trim( wp_strip_all_tags( $description ) ) === '' ) {
				$description = has_excerpt( $post )
					? get_the_excerpt( $post )
					: wp_trim_words( wp_strip_all_tags( $post->post_content ), 60 );
			}
			$description = trim( wp_strip_all_tags( (string) $description ) );

			$gallery = self::resolve_gallery( $post->ID, $gallery_field, self::FEED_IMAGE_SIZE, $image ?: '' );

			$rows[] = [
				'id'           => (int) $post->ID,
				'name'         => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'description'  => html_entity_decode( $description, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'url'          => (string) get_permalink( $post ),
				'image'        => $image ?: '',
				'gallery'      => $gallery,
				'lat'          => $lat,
				'lng'          => $lng,
				'price'        => $normal,
				'sale_price'   => $special,
				'addr1'        => trim( html_entity_decode( $addr1, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
				'city'         => trim( html_entity_decode( $city, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
				'country'      => $geo['country'],
				'province'     => $geo['province'],
				'reserve'      => $geo['reserve'],
				'categories'   => $categories,
				'features'     => $features,
				'break_type'   => $break_type,
				'star_rating'  => $star,
			];
		}

		wp_reset_postdata();
		return $rows;
	}

	/**
	 * Derive country / province / reserve from the destination taxonomy, which
	 * is nested Country > Province > Reserve. Takes the most specific term
	 * assigned to the post and maps the root-to-term chain by depth: level 0 =
	 * country (e.g. "South Africa"), level 1 = province (e.g. "Limpopo"),
	 * level 2 = reserve (e.g. "Kruger National Park"). Levels not present are
	 * left empty (e.g. a lodge tagged only to a province has no reserve).
	 */
	private static function destination_geo( int $post_id, string $taxonomy ): array {
		$out = [ 'country' => '', 'province' => '', 'reserve' => '' ];
		if ( $taxonomy === '' || ! taxonomy_exists( $taxonomy ) ) {
			return $out;
		}

		$terms = get_the_terms( $post_id, $taxonomy );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return $out;
		}

		$chosen     = null;
		$chosen_anc = -1;
		foreach ( $terms as $term ) {
			$anc = count( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) );
			if ( $anc > $chosen_anc ) {
				$chosen     = $term;
				$chosen_anc = $anc;
			}
		}
		if ( $chosen === null ) {
			return $out;
		}

		// Chain from root down to the chosen term: [country, province, reserve].
		$chain = array_reverse( get_ancestors( $chosen->term_id, $taxonomy, 'taxonomy' ) );
		$chain[] = (int) $chosen->term_id;

		$levels = [ 'country', 'province', 'reserve' ];
		foreach ( $chain as $i => $tid ) {
			if ( ! isset( $levels[ $i ] ) ) {
				break; // ignore anything deeper than reserve
			}
			$term = ( (int) $tid === (int) $chosen->term_id ) ? $chosen : get_term( (int) $tid, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$out[ $levels[ $i ] ] = $term->name;
			}
		}

		return $out;
	}

	/** Names of the terms a post has in a taxonomy, in admin order. Empty when none. */
	private static function term_names( int $post_id, string $taxonomy ): array {
		if ( $taxonomy === '' || ! taxonomy_exists( $taxonomy ) ) {
			return [];
		}
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}
		$names = [];
		foreach ( $terms as $t ) {
			$name = html_entity_decode( $t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $name !== '' ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/**
	 * Resolve the "features" source to a single comma-separated string. The
	 * source can be a taxonomy slug (term names) or a Pods/meta field holding
	 * a repeatable list, a multi-line string or a plain value.
	 */
	private static function feature_label( int $post_id, string $source ): string {
		if ( $source === '' ) {
			return '';
		}

		if ( taxonomy_exists( $source ) ) {
			return implode( ', ', self::term_names( $post_id, $source ) );
		}

		$raw = get_post_meta( $post_id, $source, true );

		$parts = [];
		if ( is_array( $raw ) ) {
			foreach ( $raw as $v ) {
				if ( is_scalar( $v ) ) {
					$parts[] = (string) $v;
				} elseif ( is_array( $v ) ) {
					// Pods relationship/file arrays expose a name/title.
					$parts[] = (string) ( $v['name'] ?? $v['post_title'] ?? $v['title'] ?? '' );
				}
			}
		} elseif ( is_scalar( $raw ) ) {
			// Split multi-line entries into separate features.
			$parts = preg_split( '/[\r\n]+/', (string) $raw ) ?: [];
		}

		// Strip any HTML markup (e.g. <ul>/<li> lists stored in the Pods field)
		// so the feed label is plain text; tag-only lines become empty and drop.
		$parts = array_filter(
			array_map(
				static function ( $p ) {
					return trim( html_entity_decode( wp_strip_all_tags( (string) $p ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				},
				$parts
			),
			static function ( $p ) {
				return $p !== '';
			}
		);

		return implode( ', ', $parts );
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
	 * Resolve an attachment ID + registered size to a URL. Cropping happens
	 * ahead of time via warm_feed_image() (run from Settings → Tools, in
	 * small batches) rather than here — a feed request can touch hundreds
	 * of images, and cropping them inline was slow enough to time the
	 * request out. Until an image is warmed, fall back to an existing size
	 * so the feed keeps working.
	 */
	private static function attachment_image_url( int $attachment_id, string $size ): string {
		if ( $size === self::FEED_IMAGE_SIZE ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( empty( $meta['sizes'][ self::FEED_IMAGE_SIZE ] ) ) {
				$url = wp_get_attachment_image_url( $attachment_id, 'large' );
				return $url ?: (string) wp_get_attachment_image_url( $attachment_id, 'full' );
			}
		}

		$url = wp_get_attachment_image_url( $attachment_id, $size );
		return $url ?: '';
	}

	/**
	 * Crop one attachment to the feed's hard-cropped size and cache it in
	 * its metadata. A single-size resize, not a full wp_generate_attachment_metadata()
	 * regen (which redoes every registered size) — cheap enough to run
	 * across a whole catalog in an admin-triggered AJAX batch.
	 * Returns true if the size exists afterward (already warmed counts).
	 */
	public static function warm_feed_image( int $attachment_id ): bool {
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes'][ self::FEED_IMAGE_SIZE ] ) ) {
			return true;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return false;
		}

		$orig_width  = (int) ( is_array( $meta ) ? ( $meta['width'] ?? 0 ) : 0 );
		$orig_height = (int) ( is_array( $meta ) ? ( $meta['height'] ?? 0 ) : 0 );
		$needs_upscale = $orig_width > 0 && $orig_height > 0 && min( $orig_width, $orig_height ) < 1200;

		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return false;
		}
		$editor->resize( 1200, 1200, true );
		$saved = $editor->save();
		if ( is_wp_error( $saved ) ) {
			return false;
		}

		// Upscaling softens the image; a mild sharpen offsets that. Only
		// worth doing when we actually scaled up — sharpening an
		// already-correctly-sized photo just looks artificial.
		if ( $needs_upscale && isset( $saved['path'], $saved['mime-type'] ) ) {
			self::sharpen_upscaled_image( (string) $saved['path'], (string) $saved['mime-type'] );
		}

		if ( ! is_array( $meta ) ) {
			$meta = [];
		}
		$meta['sizes'][ self::FEED_IMAGE_SIZE ] = [
			'file'      => $saved['file'],
			'width'     => $saved['width'],
			'height'    => $saved['height'],
			'mime-type' => $saved['mime-type'],
		];
		wp_update_attachment_metadata( $attachment_id, $meta );
		return true;
	}

	/**
	 * Mild unsharp-mask-style sharpen via GD's imageconvolution() — no
	 * Imagick dependency (not every host, e.g. Kinsta, has it enabled).
	 * Silently skipped if GD's convolution support or the file's format
	 * isn't available; the crop itself already succeeded either way.
	 */
	private static function sharpen_upscaled_image( string $path, string $mime ): void {
		if ( ! function_exists( 'imageconvolution' ) ) {
			return;
		}

		if ( $mime === 'image/jpeg' && function_exists( 'imagecreatefromjpeg' ) ) {
			$image = @imagecreatefromjpeg( $path );
		} elseif ( $mime === 'image/png' && function_exists( 'imagecreatefrompng' ) ) {
			$image = @imagecreatefrompng( $path );
		} else {
			return;
		}
		if ( ! $image ) {
			return;
		}

		$sharpen = [
			[ -1, -1, -1 ],
			[ -1, 16, -1 ],
			[ -1, -1, -1 ],
		];
		imageconvolution( $image, $sharpen, 8, 0 );

		if ( $mime === 'image/jpeg' ) {
			imagejpeg( $image, $path, 82 );
		} else {
			imagepng( $image, $path );
		}
		imagedestroy( $image );
	}

	/**
	 * Pull an attachment ID out of one Pods field entry (numeric ID,
	 * numeric-string ID, or an attachment data array). Plain URL strings
	 * yield null — there's no local file to crop.
	 */
	private static function extract_attachment_id( $entry ): ?int {
		if ( is_numeric( $entry ) || ( is_string( $entry ) && ctype_digit( $entry ) ) ) {
			return (int) $entry;
		}
		if ( is_array( $entry ) ) {
			$id = $entry['ID'] ?? $entry['id'] ?? null;
			if ( is_numeric( $id ) ) {
				return (int) $id;
			}
			if ( isset( $entry['guid'] ) && is_string( $entry['guid'] ) && filter_var( $entry['guid'], FILTER_VALIDATE_URL ) ) {
				$id = self::attachment_id_from_url( $entry['guid'] );
				return $id ? $id : null;
			}
			return null;
		}
		// A field can store a raw URL to a specific already-generated size
		// instead of an attachment reference — resolve it back so the
		// regen tool can actually warm the crop for it too.
		if ( is_string( $entry ) && filter_var( $entry, FILTER_VALIDATE_URL ) ) {
			$id = self::attachment_id_from_url( $entry );
			return $id ? $id : null;
		}
		return null;
	}

	/**
	 * Attachment ID referenced by a single-image Pods field, mirroring
	 * resolve_image()'s value shapes (attachment ID, single attachment
	 * array, or — misconfigured as multi — the first of an array of
	 * attachment arrays).
	 */
	private static function single_field_attachment_id( int $post_id, string $field ): ?int {
		if ( $field === '' ) {
			return null;
		}
		$val = get_post_meta( $post_id, $field, true );
		if ( empty( $val ) ) {
			return null;
		}
		if ( is_array( $val ) ) {
			$first = reset( $val );
			if ( is_array( $first ) && ( isset( $first['ID'] ) || isset( $first['id'] ) || isset( $first['guid'] ) ) ) {
				$val = $first;
			}
		}
		return self::extract_attachment_id( $val );
	}

	/**
	 * Attachment IDs referenced by a Pods gallery/multi-image field,
	 * mirroring resolve_gallery()'s value shapes (multiple meta rows, or
	 * a single meta row holding the whole list).
	 */
	private static function gallery_field_attachment_ids( int $post_id, string $field ): array {
		if ( $field === '' ) {
			return [];
		}

		$rows = get_post_meta( $post_id, $field );
		if ( empty( $rows ) ) {
			return [];
		}
		if ( count( $rows ) === 1 && is_array( $rows[0] ) ) {
			$rows = $rows[0];
		}

		$ids = [];
		foreach ( $rows as $entry ) {
			$id = self::extract_attachment_id( $entry );
			if ( $id !== null ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * AJAX: pre-crop feed images for a batch of listings (main image,
	 * gallery, and featured-image fallback), so the feed itself never has
	 * to crop on demand. Mirrors Coords_Sync::ajax_backfill's batching.
	 */
	public static function ajax_regen_feed_images(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( 'bushbreaks_maps_regen_feed_images', 'nonce' );

		$opts   = Settings::all();
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$batch  = 5;

		$query = new \WP_Query(
			[
				'post_type'      => $opts['post_type'],
				'post_status'    => 'publish',
				'posts_per_page' => $batch,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			]
		);

		$total     = (int) $query->found_posts;
		$processed = 0;
		$warmed    = 0;

		foreach ( $query->posts as $post_id ) {
			$ids       = self::gallery_field_attachment_ids( (int) $post_id, (string) ( $opts['feed_gallery_field'] ?? '' ) );
			$single_id = self::single_field_attachment_id( (int) $post_id, (string) ( $opts['image_field'] ?? '' ) );
			if ( $single_id !== null ) {
				$ids[] = $single_id;
			}
			$thumb_id = get_post_thumbnail_id( $post_id );
			if ( $thumb_id ) {
				$ids[] = (int) $thumb_id;
			}
			foreach ( array_unique( $ids ) as $attachment_id ) {
				if ( self::warm_feed_image( $attachment_id ) ) {
					$warmed++;
				}
			}
			$processed++;
		}

		wp_reset_postdata();

		$next = $offset + $processed;
		wp_send_json_success(
			[
				'total'  => $total,
				'next'   => $next,
				'done'   => $processed === 0 || $next >= $total,
				'warmed' => $warmed,
			]
		);
	}

	/**
	 * Resolve a URL back to its attachment ID, if it's hosted in this
	 * site's own media library. attachment_url_to_postid() matches the
	 * exact stored file path, so a URL pointing at a specific generated
	 * size (e.g. "...-1200x900.jpg") often won't match the original's
	 * _wp_attached_file — retry with any trailing "-WxH" suffix stripped.
	 * Returns 0 if nothing matches (e.g. a genuinely external image).
	 */
	private static function attachment_id_from_url( string $url ): int {
		$id = attachment_url_to_postid( $url );
		if ( $id ) {
			return (int) $id;
		}
		$stripped = preg_replace( '/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/', '', $url );
		if ( $stripped !== null && $stripped !== $url ) {
			$id = attachment_url_to_postid( $stripped );
		}
		return (int) $id;
	}

	/**
	 * A Pods field can store a raw URL to a specific already-generated
	 * size instead of an attachment reference — in that case the feed's
	 * requested $size would otherwise be silently ignored (the stored URL
	 * is whatever size happened to be picked when the field was filled
	 * in). Resolve back to the attachment so cropping still applies;
	 * fall back to the stored URL verbatim if it isn't a local attachment.
	 */
	private static function resolve_stored_url( string $url, string $size ): string {
		$attachment_id = self::attachment_id_from_url( $url );
		if ( $attachment_id ) {
			$resolved = self::attachment_image_url( $attachment_id, $size );
			if ( $resolved !== '' ) {
				return $resolved;
			}
		}
		return $url;
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
				return self::attachment_image_url( (int) $val, $size );
			}
			if ( filter_var( $val, FILTER_VALIDATE_URL ) ) {
				return self::resolve_stored_url( $val, $size );
			}
			return '';
		}

		if ( is_numeric( $val ) ) {
			return self::attachment_image_url( (int) $val, $size );
		}

		if ( is_array( $val ) ) {
			// Multiple-image field stores an array of attachment arrays — take the first.
			$first = reset( $val );
			if ( is_array( $first ) && ( isset( $first['ID'] ) || isset( $first['id'] ) || isset( $first['guid'] ) ) ) {
				$val = $first;
			}

			$id = $val['ID'] ?? $val['id'] ?? null;
			if ( is_numeric( $id ) ) {
				$url = self::attachment_image_url( (int) $id, $size );
				if ( $url ) {
					return $url;
				}
			}
			if ( isset( $val['guid'] ) && is_string( $val['guid'] ) && filter_var( $val['guid'], FILTER_VALIDATE_URL ) ) {
				return self::resolve_stored_url( $val['guid'], $size );
			}
		}

		return '';
	}

	/**
	 * Resolve a Pods gallery/multi-image field to a list of image URLs.
	 * Pods stores repeatable file fields either as multiple meta rows or as a
	 * single (serialized) array; entries can be attachment IDs, attachment
	 * data arrays, or plain URL strings. The main image URL is excluded and
	 * the list is capped so the feed stays within Meta's limits.
	 */
	private static function resolve_gallery( int $post_id, string $field, string $size, string $exclude_url = '', int $limit = 10 ): array {
		if ( $field === '' ) {
			return [];
		}

		$rows = get_post_meta( $post_id, $field );
		if ( empty( $rows ) ) {
			return [];
		}
		// Single meta row holding the whole list -> use the inner array.
		if ( count( $rows ) === 1 && is_array( $rows[0] ) ) {
			$rows = $rows[0];
		}

		$urls = [];
		foreach ( $rows as $entry ) {
			$url = '';
			if ( is_numeric( $entry ) || ( is_string( $entry ) && ctype_digit( $entry ) ) ) {
				$url = self::attachment_image_url( (int) $entry, $size );
			} elseif ( is_string( $entry ) && filter_var( $entry, FILTER_VALIDATE_URL ) ) {
				$url = self::resolve_stored_url( $entry, $size );
			} elseif ( is_array( $entry ) ) {
				$id = $entry['ID'] ?? $entry['id'] ?? null;
				if ( is_numeric( $id ) ) {
					$url = self::attachment_image_url( (int) $id, $size );
				}
				if ( $url === '' && isset( $entry['guid'] ) && is_string( $entry['guid'] ) && filter_var( $entry['guid'], FILTER_VALIDATE_URL ) ) {
					$url = self::resolve_stored_url( $entry['guid'], $size );
				}
			}

			if ( $url === '' || $url === $exclude_url || in_array( $url, $urls, true ) ) {
				continue;
			}
			$urls[] = $url;
			if ( count( $urls ) >= $limit ) {
				break;
			}
		}

		return $urls;
	}

	/**
	 * Derive a small accent palette from a primary hex colour:
	 * 'primary' (as picked), 'dark' (for text on white), 'deep' (extra
	 * dark for higher contrast), and 'soft' (translucent for chip-tint
	 * style backgrounds).
	 */
	public static function derive_palette( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[A-Fa-f0-9]{6}$/', $hex ) ) {
			$hex = '8AD000';
		}

		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		$hsl  = self::rgb_to_hsl( $r, $g, $b );
		$dark = self::hsl_to_hex( $hsl['h'], min( 1.0, $hsl['s'] ), max( 0.0, $hsl['l'] * 0.55 ) );
		$deep = self::hsl_to_hex( $hsl['h'], min( 1.0, $hsl['s'] ), max( 0.0, $hsl['l'] * 0.35 ) );

		return [
			'primary' => '#' . strtolower( $hex ),
			'dark'    => $dark,
			'deep'    => $deep,
			'soft'    => sprintf( 'rgba(%d, %d, %d, 0.18)', $r, $g, $b ),
		];
	}

	private static function rgb_to_hsl( int $r, int $g, int $b ): array {
		$r /= 255;
		$g /= 255;
		$b /= 255;
		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;
		$d   = $max - $min;

		if ( $d == 0 ) {
			return [ 'h' => 0.0, 's' => 0.0, 'l' => $l ];
		}
		$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
		switch ( $max ) {
			case $r:
				$h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
				break;
			case $g:
				$h = ( $b - $r ) / $d + 2;
				break;
			default:
				$h = ( $r - $g ) / $d + 4;
				break;
		}
		$h /= 6;
		return [ 'h' => $h, 's' => $s, 'l' => $l ];
	}

	private static function hsl_to_hex( float $h, float $s, float $l ): string {
		if ( $s == 0 ) {
			$r = $g = $b = $l;
		} else {
			$q  = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
			$p  = 2 * $l - $q;
			$r  = self::hue2rgb( $p, $q, $h + 1 / 3 );
			$g  = self::hue2rgb( $p, $q, $h );
			$b  = self::hue2rgb( $p, $q, $h - 1 / 3 );
		}
		return sprintf( '#%02x%02x%02x', (int) round( $r * 255 ), (int) round( $g * 255 ), (int) round( $b * 255 ) );
	}

	private static function hue2rgb( float $p, float $q, float $t ): float {
		if ( $t < 0 ) {
			$t += 1;
		}
		if ( $t > 1 ) {
			$t -= 1;
		}
		if ( $t < 1 / 6 ) {
			return $p + ( $q - $p ) * 6 * $t;
		}
		if ( $t < 1 / 2 ) {
			return $q;
		}
		if ( $t < 2 / 3 ) {
			return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
		}
		return $p;
	}
}
