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
		wp_register_style(
			'bushbreaks-maps',
			BUSHBREAKS_MAPS_URL . 'assets/css/bushbreaks-maps.css',
			[ 'leaflet' ],
			BUSHBREAKS_MAPS_VERSION
		);
		wp_register_script(
			'bushbreaks-maps',
			BUSHBREAKS_MAPS_URL . 'assets/js/bushbreaks-maps.js',
			[ 'leaflet' ],
			BUSHBREAKS_MAPS_VERSION,
			true
		);
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

		wp_enqueue_style( 'bushbreaks-maps' );
		wp_enqueue_script( 'bushbreaks-maps' );

		wp_localize_script(
			'bushbreaks-maps',
			'BushbreaksMapsData',
			[
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'bushbreaks_maps' ),
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
				'i18n'      => [
					'searchPlaceholder' => __( 'Search lodges, towns, regions…', 'bushbreaks-maps' ),
					'listHeading'       => __( 'Lodges', 'bushbreaks-maps' ),
					'noResults'         => __( 'No lodges match your search.', 'bushbreaks-maps' ),
					'viewDetails'       => __( 'View details', 'bushbreaks-maps' ),
				],
			]
		);

		ob_start();
		$template = BUSHBREAKS_MAPS_DIR . 'templates/map-display.php';
		$height   = $atts['height'];
		$i18n     = [
			'searchPlaceholder' => __( 'Search lodges, towns, regions…', 'bushbreaks-maps' ),
			'listHeading'       => __( 'Lodges', 'bushbreaks-maps' ),
			'viewDetails'       => __( 'View details', 'bushbreaks-maps' ),
		];
		include $template;
		return (string) ob_get_clean();
	}
}
