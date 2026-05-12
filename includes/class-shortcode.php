<?php
namespace Bushbreaks_Maps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shortcode {

	public const TAG = 'bushbreaks_map';

	public function register(): void {
		add_shortcode( self::TAG, [ $this, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
	}

	public function register_assets(): void {
		$api_key = trim( (string) Settings::get( 'google_maps_api_key' ) );

		// Leaflet core
		wp_register_style(
			'leaflet',
			'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
			[],
			'1.9.4'
		);
		wp_register_script(
			'leaflet',
			'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
			[],
			'1.9.4',
			true
		);

		// Leaflet markercluster (CSS bundle + JS)
		wp_register_style(
			'leaflet-markercluster',
			'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css',
			[ 'leaflet' ],
			'1.5.3'
		);
		wp_register_style(
			'leaflet-markercluster-default',
			'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css',
			[ 'leaflet-markercluster' ],
			'1.5.3'
		);
		wp_register_script(
			'leaflet-markercluster',
			'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js',
			[ 'leaflet' ],
			'1.5.3',
			true
		);

		$style_deps  = $api_key === '' ? [ 'leaflet-markercluster-default' ] : [];
		$script_deps = $api_key === '' ? [ 'leaflet-markercluster' ] : [];

		wp_register_style(
			'bushbreaks-maps',
			BUSHBREAKS_MAPS_URL . 'assets/css/bushbreaks-maps.css',
			$style_deps,
			BUSHBREAKS_MAPS_VERSION
		);
		wp_register_script(
			'bushbreaks-maps',
			BUSHBREAKS_MAPS_URL . 'assets/js/bushbreaks-maps.js',
			$script_deps,
			BUSHBREAKS_MAPS_VERSION,
			true
		);

		if ( $api_key !== '' ) {
			// Google markerclusterer (UMD global "markerClusterer")
			wp_register_script(
				'bushbreaks-maps-google-cluster',
				'https://unpkg.com/@googlemaps/markerclusterer@2.5.3/dist/index.min.js',
				[ 'bushbreaks-maps' ],
				'2.5.3',
				true
			);

			$google_url = add_query_arg(
				[
					'key'      => $api_key,
					'callback' => 'BushbreaksMapsBoot',
					'loading'  => 'async',
					'v'        => 'weekly',
				],
				'https://maps.googleapis.com/maps/api/js'
			);
			wp_register_script(
				'bushbreaks-maps-google',
				$google_url,
				[ 'bushbreaks-maps-google-cluster' ],
				null,
				[
					'strategy'  => 'async',
					'in_footer' => true,
				]
			);
		}
	}

	public function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'height' => '600px',
			],
			is_array( $atts ) ? $atts : [],
			self::TAG
		);

		$opts = Settings::all();

		$all_locations = Repository::query( [ 'limit' => -1 ] );
		$list_items    = Repository::query(
			[
				'limit' => (int) $opts['list_limit'],
			]
		);

		$categories  = [];
		$cat_tax_slug = (string) ( $opts['category_taxonomy'] ?? '' );
		if ( $cat_tax_slug !== '' && taxonomy_exists( $cat_tax_slug ) ) {
			$terms = get_terms(
				[
					'taxonomy'   => $cat_tax_slug,
					'hide_empty' => false,
				]
			);
			if ( ! is_wp_error( $terms ) ) {
				$terms = Settings::sort_terms_by_order( $terms );
				foreach ( $terms as $t ) {
					$categories[] = [
						'id'   => (int) $t->term_id,
						'slug' => $t->slug,
						'name' => $t->name,
					];
				}
			}
		}

		$destinations  = [];
		$dest_tax_slug = (string) ( $opts['destination_taxonomy'] ?? '' );
		if ( $dest_tax_slug !== '' && taxonomy_exists( $dest_tax_slug ) ) {
			$dest_terms = get_terms(
				[
					'taxonomy'   => $dest_tax_slug,
					'hide_empty' => false,
				]
			);
			if ( ! is_wp_error( $dest_terms ) && ! empty( $dest_terms ) ) {
				$by_parent = [];
				foreach ( $dest_terms as $t ) {
					$pid = (int) ( $t->parent ?? 0 );
					$by_parent[ $pid ][] = $t;
				}
				foreach ( $by_parent as $k => $list ) {
					$by_parent[ $k ] = Settings::sort_terms_by_order( $list, '_bbm_destination_order' );
				}

				$walker = function ( $parent_id ) use ( &$walker, &$by_parent ) {
					$out = [];
					if ( empty( $by_parent[ $parent_id ] ) ) {
						return $out;
					}
					foreach ( $by_parent[ $parent_id ] as $t ) {
						$node = [
							'id'   => (int) $t->term_id,
							'slug' => $t->slug,
							'name' => $t->name,
						];
						$children = $walker( (int) $t->term_id );
						if ( ! empty( $children ) ) {
							$node['children'] = $children;
						}
						$out[] = $node;
					}
					return $out;
				};
				$destinations = $walker( 0 );
			}
		}

		$api_key = trim( (string) ( $opts['google_maps_api_key'] ?? '' ) );
		$provider = $api_key !== '' ? 'google' : 'leaflet';

		wp_enqueue_style( 'bushbreaks-maps' );
		wp_enqueue_script( 'bushbreaks-maps' );
		if ( $provider === 'google' ) {
			wp_enqueue_script( 'bushbreaks-maps-google' );
		}

		wp_localize_script(
			'bushbreaks-maps',
			'BushbreaksMapsData',
			[
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'bushbreaks_maps' ),
				'provider'  => $provider,
				'locations' => $all_locations,
				'center'    => [
					'lat' => (float) $opts['map_center_lat'],
					'lng' => (float) $opts['map_center_lng'],
				],
				'zoom'      => (int) $opts['map_zoom'],
				'tile'      => [
					'url'  => $opts['tile_url'],
					'attr' => $opts['tile_attr'],
				],
				'icons'     => [
					'marker'  => [
						'url'    => (string) $opts['marker_icon_url'],
						'width'  => (int) $opts['marker_icon_width'],
						'height' => (int) $opts['marker_icon_height'],
					],
					'cluster' => [
						'url'  => (string) $opts['cluster_icon_url'],
						'size' => (int) $opts['cluster_icon_size'],
					],
				],
				'categories'   => $categories,
				'destinations' => $destinations,
				'i18n'      => [
					'searchPlaceholder'       => __( 'Search lodges, towns, regions…', 'bushbreaks-maps' ),
					'listHeading'             => __( 'Lodges', 'bushbreaks-maps' ),
					'noResults'               => __( 'No lodges match your search.', 'bushbreaks-maps' ),
					'viewDetails'             => __( 'View details', 'bushbreaks-maps' ),
					'searching'               => __( 'Searching lodges…', 'bushbreaks-maps' ),
					'categoryPlaceholder'     => __( 'Filter by category…', 'bushbreaks-maps' ),
					'removeCategory'          => __( 'Remove filter', 'bushbreaks-maps' ),
					'destinationPlaceholder'  => __( 'Filter by Region…', 'bushbreaks-maps' ),
					'removeDestination'       => __( 'Remove region filter', 'bushbreaks-maps' ),
					'resultsCountSingle'      => __( '1 lodge', 'bushbreaks-maps' ),
					'resultsCountPlural'      => __( '%d lodges', 'bushbreaks-maps' ),
				],
			]
		);

		ob_start();
		$template = BUSHBREAKS_MAPS_DIR . 'templates/map-display.php';
		$height   = $atts['height'];
		$i18n     = [
			'searchPlaceholder'      => __( 'Search lodges, towns, regions…', 'bushbreaks-maps' ),
			'listHeading'            => __( 'Lodges', 'bushbreaks-maps' ),
			'viewDetails'            => __( 'View details', 'bushbreaks-maps' ),
			'searching'              => __( 'Searching lodges…', 'bushbreaks-maps' ),
			'categoryPlaceholder'    => __( 'Filter by category…', 'bushbreaks-maps' ),
			'destinationPlaceholder' => __( 'Filter by Region…', 'bushbreaks-maps' ),
		];
		include $template;
		return (string) ob_get_clean();
	}
}
