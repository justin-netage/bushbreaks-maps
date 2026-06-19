<?php
namespace Bushbreaks_Maps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	public const OPTION_KEY = 'bushbreaks_maps_settings';

	public static function defaults(): array {
		return [
			'post_type'      => 'accommodation',
			'lat_field'      => 'latitude',
			'lng_field'      => 'longitude',
			'address_field'  => 'location',
			'iframe_field'   => 'google_maps_iframe',
			'location_field' => 'location',
			'destination_taxonomy'    => 'destination',
			'enable_region_filter'    => true,
			'category_taxonomy'       => 'popular_request',
			'image_field'         => 'banner',
			'normal_price_field'      => 'normal_price',
			'special_price_field'     => 'special_price',
			'price_description_field' => 'price_description',
			'valid_from_field'    => 'valid_from',
			'valid_until_field'   => 'valid_until',
			'currency_symbol'     => 'R',
			'feed_currency'       => 'ZAR',
			'feed_brand'          => '',
			'feed_country'        => 'South Africa',
			'feed_city_field'        => '',
			'feed_star_rating_field' => '',
			'primary_color'       => '#8AD000',
			'thumbnail_size'      => 'large',
			'map_center_lat' => -23.6980,
			'map_center_lng' => 31.0498,
			'map_zoom'       => 6,
			'list_limit'     => 10,
			'list_heading_label'  => 'Lodges',
			'featured_post_ids'   => [],
			'marker_icon_url'    => '',
			'marker_icon_width'  => 32,
			'marker_icon_height' => 40,
			'cluster_icon_url'   => '',
			'cluster_icon_size'  => 48,
			'google_maps_api_key' => '',
			'tile_url'       => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
			'tile_attr'      => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
		];
	}

	public static function get( string $key ) {
		$opts = get_option( self::OPTION_KEY, [] );
		$opts = is_array( $opts ) ? $opts : [];
		$opts = array_merge( self::defaults(), $opts );
		return $opts[ $key ] ?? null;
	}

	public static function all(): array {
		$opts = get_option( self::OPTION_KEY, [] );
		$opts = is_array( $opts ) ? $opts : [];
		return array_merge( self::defaults(), $opts );
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_bushbreaks_maps_reorder_categories', [ $this, 'ajax_reorder_categories' ] );
		add_action( 'wp_ajax_bushbreaks_maps_reorder_destinations', [ $this, 'ajax_reorder_destinations' ] );
		add_action( 'wp_ajax_bushbreaks_maps_lodge_search', [ $this, 'ajax_lodge_search' ] );
	}

	public function add_menu(): void {
		$hook = add_options_page(
			__( 'Bushbreaks Maps', 'bushbreaks-maps' ),
			__( 'Bushbreaks Maps', 'bushbreaks-maps' ),
			'manage_options',
			'bushbreaks-maps',
			[ $this, 'render_page' ]
		);

		add_action( 'admin_enqueue_scripts', function ( $current ) use ( $hook ) {
			if ( $current !== $hook ) {
				return;
			}
			wp_enqueue_media();
			wp_enqueue_script( 'jquery-ui-sortable' );
			wp_enqueue_script(
				'bushbreaks-maps-admin',
				BUSHBREAKS_MAPS_URL . 'assets/js/bbm-admin.js',
				[ 'jquery', 'jquery-ui-sortable' ],
				BUSHBREAKS_MAPS_VERSION,
				true
			);
			wp_localize_script(
				'bushbreaks-maps-admin',
				'BushbreaksMapsAdmin',
				[
					'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
					'nonce'             => wp_create_nonce( 'bushbreaks_maps_backfill' ),
					'reorderNonce'      => wp_create_nonce( 'bushbreaks_maps_reorder_categories' ),
					'reorderDestNonce'  => wp_create_nonce( 'bushbreaks_maps_reorder_destinations' ),
					'lodgeSearchNonce'  => wp_create_nonce( 'bushbreaks_maps_lodge_search' ),
					'optionKey'         => self::OPTION_KEY,
					'i18n'    => [
						'starting'    => __( 'Starting…', 'bushbreaks-maps' ),
						'progress'    => __( 'Processed %1$s of %2$s…', 'bushbreaks-maps' ),
						'done'        => __( 'Done. Processed %1$s of %2$s.', 'bushbreaks-maps' ),
						'error'       => __( 'Error during backfill.', 'bushbreaks-maps' ),
						'networkError'=> __( 'Network error.', 'bushbreaks-maps' ),
						'saving'      => __( 'Saving…', 'bushbreaks-maps' ),
						'saved'       => __( 'Order saved.', 'bushbreaks-maps' ),
						'saveFailed'  => __( 'Save failed.', 'bushbreaks-maps' ),
					],
				]
			);
		} );
	}

	public function register_settings(): void {
		register_setting(
			'bushbreaks_maps',
			self::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => self::defaults(),
			]
		);
	}

	public function sanitize( $input ): array {
		$out = self::defaults();
		if ( ! is_array( $input ) ) {
			return $out;
		}

		$text_keys = [ 'post_type', 'list_heading_label', 'lat_field', 'lng_field', 'address_field', 'iframe_field', 'location_field', 'destination_taxonomy', 'category_taxonomy', 'image_field', 'normal_price_field', 'special_price_field', 'price_description_field', 'valid_from_field', 'valid_until_field', 'currency_symbol', 'feed_brand', 'feed_country', 'feed_city_field', 'feed_star_rating_field', 'thumbnail_size', 'google_maps_api_key', 'tile_url', 'tile_attr' ];
		foreach ( $text_keys as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( (string) $input[ $k ] );
			}
		}

		if ( isset( $input['map_center_lat'] ) && is_numeric( $input['map_center_lat'] ) ) {
			$out['map_center_lat'] = (float) $input['map_center_lat'];
		}
		if ( isset( $input['map_center_lng'] ) && is_numeric( $input['map_center_lng'] ) ) {
			$out['map_center_lng'] = (float) $input['map_center_lng'];
		}
		if ( isset( $input['map_zoom'] ) && is_numeric( $input['map_zoom'] ) ) {
			$out['map_zoom'] = max( 1, min( 19, (int) $input['map_zoom'] ) );
		}
		if ( isset( $input['list_limit'] ) && is_numeric( $input['list_limit'] ) ) {
			$out['list_limit'] = max( 1, min( 50, (int) $input['list_limit'] ) );
		}

		$out['featured_post_ids'] = [];
		if ( isset( $input['featured_post_ids'] ) && is_array( $input['featured_post_ids'] ) ) {
			$seen = [];
			foreach ( $input['featured_post_ids'] as $fid ) {
				$id = (int) $fid;
				if ( $id > 0 && ! isset( $seen[ $id ] ) ) {
					$seen[ $id ] = true;
					$out['featured_post_ids'][] = $id;
				}
			}
		}

		if ( isset( $input['feed_currency'] ) ) {
			$code = strtoupper( trim( (string) $input['feed_currency'] ) );
			$out['feed_currency'] = preg_match( '/^[A-Z]{3}$/', $code ) ? $code : 'ZAR';
		}

		$out['enable_region_filter'] = ! empty( $input['enable_region_filter'] );

		if ( isset( $input['primary_color'] ) ) {
			$raw = trim( (string) $input['primary_color'] );
			if ( preg_match( '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $raw ) ) {
				$out['primary_color'] = $raw;
			}
		}

		foreach ( [ 'marker_icon_url', 'cluster_icon_url' ] as $url_key ) {
			if ( isset( $input[ $url_key ] ) ) {
				$out[ $url_key ] = esc_url_raw( (string) $input[ $url_key ] );
			}
		}
		foreach ( [ 'marker_icon_width', 'marker_icon_height' ] as $size_key ) {
			if ( isset( $input[ $size_key ] ) && is_numeric( $input[ $size_key ] ) ) {
				$out[ $size_key ] = max( 8, min( 256, (int) $input[ $size_key ] ) );
			}
		}
		if ( isset( $input['cluster_icon_size'] ) && is_numeric( $input['cluster_icon_size'] ) ) {
			$out['cluster_icon_size'] = max( 16, min( 200, (int) $input['cluster_icon_size'] ) );
		}

		return $out;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$opts        = self::all();
		$option_attr = esc_attr( self::OPTION_KEY );
		?>
		<style>
			.bbm-tab-content { display: none; }
			.bbm-tab-content.is-active { display: block; }
			.bbm-settings-page .bbm-submit-row { margin-top: 12px; }
			.bbm-featured-picker { max-width: 520px; position: relative; }
			.bbm-featured-search { width: 100%; }
			.bbm-featured-suggestions {
				margin: 4px 0 8px;
				padding: 4px;
				border: 1px solid #cfd4da;
				border-radius: 4px;
				background: #fff;
				max-height: 220px;
				overflow-y: auto;
				box-shadow: 0 4px 10px rgba(15, 30, 50, 0.08);
			}
			.bbm-featured-suggestions[hidden] { display: none; }
			.bbm-featured-suggest {
				display: block;
				width: 100%;
				padding: 6px 10px;
				border: 0;
				background: transparent;
				text-align: left;
				cursor: pointer;
				border-radius: 4px;
				font-size: 13px;
				font-family: inherit;
				color: inherit;
			}
			.bbm-featured-suggest:hover,
			.bbm-featured-suggest:focus { background: #f3f5f7; outline: none; }
			.bbm-featured-suggest-empty { padding: 6px 10px; color: #6a7280; font-size: 13px; }
			.bbm-featured-selected { list-style: none; margin: 8px 0 0; padding: 0; }
			.bbm-featured-selected li {
				display: flex;
				align-items: center;
				gap: 10px;
				padding: 8px 10px;
				margin: 0 0 4px;
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
			}
			.bbm-featured-handle { cursor: grab; color: #8c8f94; font-size: 18px; line-height: 1; user-select: none; }
			.bbm-featured-handle:active { cursor: grabbing; }
			.bbm-featured-name { flex: 1; font-size: 14px; }
			.bbm-featured-remove {
				background: transparent;
				border: 0;
				color: #6a7280;
				cursor: pointer;
				font-size: 18px;
				line-height: 1;
				padding: 0 6px;
			}
			.bbm-featured-remove:hover { color: #b54708; }
			.bbm-featured-placeholder { background: #f0f7e0; border: 1px dashed <?php echo esc_attr( $opts['primary_color'] ?? '#8AD000' ); ?>; border-radius: 4px; margin: 0 0 4px; }
		</style>
		<div class="wrap bbm-settings-page">
			<h1><?php esc_html_e( 'Bushbreaks Maps', 'bushbreaks-maps' ); ?></h1>
			<p><?php esc_html_e( 'Use the [bushbreaks_map] shortcode to embed the map. Choose a tab to jump between groups of settings.', 'bushbreaks-maps' ); ?></p>

			<h2 class="nav-tab-wrapper bbm-tabs">
				<a href="#general"  class="nav-tab nav-tab-active" data-tab="general"><?php esc_html_e( 'General', 'bushbreaks-maps' ); ?></a>
				<a href="#fields"   class="nav-tab"                 data-tab="fields"><?php esc_html_e( 'Field mapping', 'bushbreaks-maps' ); ?></a>
				<a href="#pricing"  class="nav-tab"                 data-tab="pricing"><?php esc_html_e( 'Pricing', 'bushbreaks-maps' ); ?></a>
				<a href="#filters"  class="nav-tab"                 data-tab="filters"><?php esc_html_e( 'Filters', 'bushbreaks-maps' ); ?></a>
				<a href="#map"      class="nav-tab"                 data-tab="map"><?php esc_html_e( 'Map &amp; Theme', 'bushbreaks-maps' ); ?></a>
				<a href="#feed"     class="nav-tab"                 data-tab="feed"><?php esc_html_e( 'Facebook feed', 'bushbreaks-maps' ); ?></a>
				<a href="#tools"    class="nav-tab"                 data-tab="tools"><?php esc_html_e( 'Tools', 'bushbreaks-maps' ); ?></a>
			</h2>

			<form method="post" action="options.php" class="bbm-settings-form">
				<?php settings_fields( 'bushbreaks_maps' ); ?>

				<div class="bbm-tab-content is-active" data-tab="general">
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="bbm_post_type"><?php esc_html_e( 'Pod / Post Type slug', 'bushbreaks-maps' ); ?></label></th>
							<td><input id="bbm_post_type" name="<?php echo $option_attr; ?>[post_type]" type="text" value="<?php echo esc_attr( $opts['post_type'] ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="bbm_list_heading"><?php esc_html_e( 'List heading label', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_list_heading" name="<?php echo $option_attr; ?>[list_heading_label]" type="text" value="<?php echo esc_attr( $opts['list_heading_label'] ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Heading shown above the lodge list on the front end (e.g. "Lodges", "Accommodations").', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="bbm_list_limit"><?php esc_html_e( 'List limit', 'bushbreaks-maps' ); ?></label></th>
							<td><input id="bbm_list_limit" name="<?php echo $option_attr; ?>[list_limit]" type="number" min="1" max="50" value="<?php echo esc_attr( (string) $opts['list_limit'] ); ?>" class="small-text"></td>
						</tr>
						<tr>
							<th><label for="bbm_clat"><?php esc_html_e( 'Default map centre latitude', 'bushbreaks-maps' ); ?></label></th>
							<td><input id="bbm_clat" name="<?php echo $option_attr; ?>[map_center_lat]" type="number" step="any" value="<?php echo esc_attr( (string) $opts['map_center_lat'] ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="bbm_clng"><?php esc_html_e( 'Default map centre longitude', 'bushbreaks-maps' ); ?></label></th>
							<td><input id="bbm_clng" name="<?php echo $option_attr; ?>[map_center_lng]" type="number" step="any" value="<?php echo esc_attr( (string) $opts['map_center_lng'] ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="bbm_zoom"><?php esc_html_e( 'Default zoom (1-19)', 'bushbreaks-maps' ); ?></label></th>
							<td><input id="bbm_zoom" name="<?php echo $option_attr; ?>[map_zoom]" type="number" min="1" max="19" value="<?php echo esc_attr( (string) $opts['map_zoom'] ); ?>" class="small-text"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Featured accommodations', 'bushbreaks-maps' ); ?></th>
							<td>
								<p class="description" style="margin:0 0 6px;"><?php esc_html_e( 'These appear first in the default list — before any search or filter is applied. Drag to reorder.', 'bushbreaks-maps' ); ?></p>
								<div class="bbm-featured-picker">
									<input type="search" class="bbm-featured-search regular-text" placeholder="<?php esc_attr_e( 'Search accommodations to add…', 'bushbreaks-maps' ); ?>" autocomplete="off" style="margin-bottom:4px;">
									<div class="bbm-featured-suggestions" hidden></div>
									<ul class="bbm-featured-selected">
										<?php
										$featured_ids = (array) ( $opts['featured_post_ids'] ?? [] );
										foreach ( $featured_ids as $fid ) {
											$fid_int = (int) $fid;
											if ( $fid_int <= 0 ) {
												continue;
											}
											$title = get_the_title( $fid_int );
											if ( $title === '' ) {
												continue;
											}
											?>
											<li data-id="<?php echo esc_attr( (string) $fid_int ); ?>">
												<span class="bbm-featured-handle" aria-hidden="true">&#8801;</span>
												<span class="bbm-featured-name"><?php echo esc_html( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); ?></span>
												<input type="hidden" name="<?php echo $option_attr; ?>[featured_post_ids][]" value="<?php echo esc_attr( (string) $fid_int ); ?>">
												<button type="button" class="bbm-featured-remove" aria-label="<?php esc_attr_e( 'Remove', 'bushbreaks-maps' ); ?>">&times;</button>
											</li>
											<?php
										}
										?>
									</ul>
								</div>
							</td>
						</tr>
					</table>
				</div>

				<div class="bbm-tab-content" data-tab="fields">
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="bbm_lat"><?php esc_html_e( 'Latitude field', 'bushbreaks-maps' ); ?></label></th>
							<td><input id="bbm_lat" name="<?php echo $option_attr; ?>[lat_field]" type="text" value="<?php echo esc_attr( $opts['lat_field'] ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="bbm_lng"><?php esc_html_e( 'Longitude field', 'bushbreaks-maps' ); ?></label></th>
							<td><input id="bbm_lng" name="<?php echo $option_attr; ?>[lng_field]" type="text" value="<?php echo esc_attr( $opts['lng_field'] ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="bbm_address"><?php esc_html_e( 'Address field (shown on cards)', 'bushbreaks-maps' ); ?></label></th>
							<td><input id="bbm_address" name="<?php echo $option_attr; ?>[address_field]" type="text" value="<?php echo esc_attr( $opts['address_field'] ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="bbm_iframe"><?php esc_html_e( 'Google Maps iframe field', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_iframe" name="<?php echo $option_attr; ?>[iframe_field]" type="text" value="<?php echo esc_attr( $opts['iframe_field'] ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'When set, lat/lng are extracted from the iframe on save (preferred source).', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="bbm_location"><?php esc_html_e( 'Location text field (geocoded)', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_location" name="<?php echo $option_attr; ?>[location_field]" type="text" value="<?php echo esc_attr( $opts['location_field'] ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Used as a fallback when no iframe is set. Geocoded via OpenStreetMap Nominatim and cached.', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="bbm_image"><?php esc_html_e( 'Image field (Pods)', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_image" name="<?php echo $option_attr; ?>[image_field]" type="text" value="<?php echo esc_attr( $opts['image_field'] ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Pods image/file field used as the card thumbnail. Falls back to the WordPress featured image when empty.', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="bbm_thumb"><?php esc_html_e( 'Thumbnail size', 'bushbreaks-maps' ); ?></label></th>
							<td><input id="bbm_thumb" name="<?php echo $option_attr; ?>[thumbnail_size]" type="text" value="<?php echo esc_attr( $opts['thumbnail_size'] ); ?>" class="regular-text"></td>
						</tr>
					</table>
				</div>

				<div class="bbm-tab-content" data-tab="pricing">
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="bbm_normal_price"><?php esc_html_e( 'Normal price field', 'bushbreaks-maps' ); ?></label></th>
							<td><input id="bbm_normal_price" name="<?php echo $option_attr; ?>[normal_price_field]" type="text" value="<?php echo esc_attr( $opts['normal_price_field'] ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="bbm_special_price"><?php esc_html_e( 'Special price field', 'bushbreaks-maps' ); ?></label></th>
							<td><input id="bbm_special_price" name="<?php echo $option_attr; ?>[special_price_field]" type="text" value="<?php echo esc_attr( $opts['special_price_field'] ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="bbm_price_desc"><?php esc_html_e( 'Price description field', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_price_desc" name="<?php echo $option_attr; ?>[price_description_field]" type="text" value="<?php echo esc_attr( $opts['price_description_field'] ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Short unit shown next to the price (e.g. ppn, pn, ppp).', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="bbm_valid_from"><?php esc_html_e( 'Special valid-from field', 'bushbreaks-maps' ); ?></label></th>
							<td><input id="bbm_valid_from" name="<?php echo $option_attr; ?>[valid_from_field]" type="text" value="<?php echo esc_attr( $opts['valid_from_field'] ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="bbm_valid_until"><?php esc_html_e( 'Special valid-until field', 'bushbreaks-maps' ); ?></label></th>
							<td><input id="bbm_valid_until" name="<?php echo $option_attr; ?>[valid_until_field]" type="text" value="<?php echo esc_attr( $opts['valid_until_field'] ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="bbm_currency"><?php esc_html_e( 'Currency symbol', 'bushbreaks-maps' ); ?></label></th>
							<td><input id="bbm_currency" name="<?php echo $option_attr; ?>[currency_symbol]" type="text" value="<?php echo esc_attr( $opts['currency_symbol'] ); ?>" class="small-text"></td>
						</tr>
					</table>
				</div>

				<div class="bbm-tab-content" data-tab="filters">
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="bbm_destination_tax"><?php esc_html_e( 'Destination taxonomy slug', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_destination_tax" name="<?php echo $option_attr; ?>[destination_taxonomy]" type="text" value="<?php echo esc_attr( $opts['destination_taxonomy'] ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Custom taxonomy used for destinations. Search will match lodges tagged with destinations whose name matches the query.', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Region filter', 'bushbreaks-maps' ); ?></th>
							<td>
								<label for="bbm_enable_region_filter">
									<input id="bbm_enable_region_filter" name="<?php echo $option_attr; ?>[enable_region_filter]" type="checkbox" value="1" <?php checked( ! empty( $opts['enable_region_filter'] ) ); ?>>
									<?php esc_html_e( 'Show the "Filter by Region…" dropdown on the front-end map', 'bushbreaks-maps' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Uncheck to hide the region dropdown. The taxonomy and term ordering settings stay intact so you can re-enable later.', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="bbm_category_tax"><?php esc_html_e( 'Category taxonomy slug', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_category_tax" name="<?php echo $option_attr; ?>[category_taxonomy]" type="text" value="<?php echo esc_attr( $opts['category_taxonomy'] ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Taxonomy whose terms appear in the front-end filter dropdown beneath the search bar (e.g. popular_request).', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="bbm-tab-content" data-tab="map">
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="bbm_primary_color"><?php esc_html_e( 'Primary color', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_primary_color" name="<?php echo $option_attr; ?>[primary_color]" type="color" value="<?php echo esc_attr( $opts['primary_color'] ); ?>" style="width:60px;height:34px;padding:0;border:1px solid #cfd4da;border-radius:6px;background:transparent;cursor:pointer;">
								<p class="description"><?php esc_html_e( 'Accent colour used across chips, special-price text, discount pills, links and the loader spinner. Derived darker/lighter shades follow your choice.', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
						<tr><th colspan="2"><h3 style="margin:8px 0 0"><?php esc_html_e( 'Custom markers', 'bushbreaks-maps' ); ?></h3></th></tr>
						<tr>
							<th><label for="bbm_marker_url"><?php esc_html_e( 'Lodge marker image', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_marker_url" name="<?php echo $option_attr; ?>[marker_icon_url]" type="text" value="<?php echo esc_attr( $opts['marker_icon_url'] ); ?>" class="large-text" autocomplete="off">
								<button type="button" class="button bbm-media-picker"
									data-target="bbm_marker_url"
									data-width-target="bbm_marker_width"
									data-height-target="bbm_marker_height"
									data-title="<?php echo esc_attr__( 'Choose lodge marker', 'bushbreaks-maps' ); ?>">
									<?php esc_html_e( 'Choose from Media Library', 'bushbreaks-maps' ); ?>
								</button>
								<p class="description"><?php esc_html_e( 'Leave empty to use the default pin.', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="bbm_marker_width"><?php esc_html_e( 'Marker size (px)', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_marker_width" name="<?php echo $option_attr; ?>[marker_icon_width]" type="number" min="8" max="256" value="<?php echo esc_attr( (string) $opts['marker_icon_width'] ); ?>" style="width:90px"> &times;
								<input id="bbm_marker_height" name="<?php echo $option_attr; ?>[marker_icon_height]" type="number" min="8" max="256" value="<?php echo esc_attr( (string) $opts['marker_icon_height'] ); ?>" style="width:90px">
								<p class="description"><?php esc_html_e( 'Width × height. Filled in automatically when you pick from the media library.', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="bbm_cluster_url"><?php esc_html_e( 'Cluster icon', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_cluster_url" name="<?php echo $option_attr; ?>[cluster_icon_url]" type="text" value="<?php echo esc_attr( $opts['cluster_icon_url'] ); ?>" class="large-text" autocomplete="off">
								<button type="button" class="button bbm-media-picker"
									data-target="bbm_cluster_url"
									data-title="<?php echo esc_attr__( 'Choose cluster icon', 'bushbreaks-maps' ); ?>">
									<?php esc_html_e( 'Choose from Media Library', 'bushbreaks-maps' ); ?>
								</button>
								<p class="description"><?php esc_html_e( 'Leave empty to use the default cluster style. The lodge count is drawn on top.', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="bbm_cluster_size"><?php esc_html_e( 'Cluster size (px)', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_cluster_size" name="<?php echo $option_attr; ?>[cluster_icon_size]" type="number" min="16" max="200" value="<?php echo esc_attr( (string) $opts['cluster_icon_size'] ); ?>" style="width:90px">
							</td>
						</tr>
						<tr><th colspan="2"><h3 style="margin:8px 0 0"><?php esc_html_e( 'Map provider', 'bushbreaks-maps' ); ?></h3></th></tr>
						<tr>
							<th><label for="bbm_gmaps_key"><?php esc_html_e( 'Google Maps API key', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_gmaps_key" name="<?php echo $option_attr; ?>[google_maps_api_key]" type="password" value="<?php echo esc_attr( $opts['google_maps_api_key'] ); ?>" class="large-text" autocomplete="off" spellcheck="false">
								<p class="description"><?php esc_html_e( 'When set, the front-end map switches to Google Maps. Leave empty to use OpenStreetMap (Leaflet).', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="bbm_tile"><?php esc_html_e( 'Leaflet tile URL', 'bushbreaks-maps' ); ?></label></th>
							<td><input id="bbm_tile" name="<?php echo $option_attr; ?>[tile_url]" type="text" value="<?php echo esc_attr( $opts['tile_url'] ); ?>" class="large-text"></td>
						</tr>
						<tr>
							<th><label for="bbm_tile_attr"><?php esc_html_e( 'Tile attribution', 'bushbreaks-maps' ); ?></label></th>
							<td><input id="bbm_tile_attr" name="<?php echo $option_attr; ?>[tile_attr]" type="text" value="<?php echo esc_attr( $opts['tile_attr'] ); ?>" class="large-text"></td>
						</tr>
					</table>
				</div>

				<div class="bbm-tab-content" data-tab="feed">
					<h2><?php esc_html_e( 'Meta travel catalog feeds', 'bushbreaks-maps' ); ?></h2>
					<p><?php esc_html_e( 'Two feeds built from your accommodation listings, in Meta\'s travel catalog XML format. Each lodge appears in both. Add each URL to the matching catalog type in Commerce Manager.', 'bushbreaks-maps' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th><?php esc_html_e( 'Hotels feed URL', 'bushbreaks-maps' ); ?></th>
							<td>
								<input type="text" class="large-text" readonly onfocus="this.select();" value="<?php echo esc_attr( Feed::feed_url( 'facebook' ) ); ?>">
								<p class="description">
									<?php esc_html_e( 'Add to a Hotels catalog → Data sources → "Scheduled feed".', 'bushbreaks-maps' ); ?>
									<a href="<?php echo esc_url( Feed::feed_url( 'facebook' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open feed', 'bushbreaks-maps' ); ?></a>
								</p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Destinations feed URL', 'bushbreaks-maps' ); ?></th>
							<td>
								<input type="text" class="large-text" readonly onfocus="this.select();" value="<?php echo esc_attr( Feed::feed_url( 'destinations' ) ); ?>">
								<p class="description">
									<?php esc_html_e( 'Add to a Destinations catalog → Data sources → "Scheduled feed".', 'bushbreaks-maps' ); ?>
									<a href="<?php echo esc_url( Feed::feed_url( 'destinations' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open feed', 'bushbreaks-maps' ); ?></a>
								</p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Products feed URL', 'bushbreaks-maps' ); ?></th>
							<td>
								<input type="text" class="large-text" readonly onfocus="this.select();" value="<?php echo esc_attr( Feed::feed_url( 'products' ) ); ?>">
								<p class="description">
									<?php esc_html_e( 'Standard product catalog (RSS). Categories become product_type; province, reserve and categories become custom_label_0/1/2.', 'bushbreaks-maps' ); ?>
									<a href="<?php echo esc_url( Feed::feed_url( 'products' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open feed', 'bushbreaks-maps' ); ?></a>
								</p>
								<?php if ( ! get_option( 'permalink_structure' ) ) : ?>
									<p class="description"><em><?php esc_html_e( 'Tip: enable pretty permalinks (Settings → Permalinks) for cleaner /bushbreaks-feed/*.xml URLs.', 'bushbreaks-maps' ); ?></em></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><label for="bbm_feed_currency"><?php esc_html_e( 'Feed currency (ISO code)', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_feed_currency" name="<?php echo $option_attr; ?>[feed_currency]" type="text" maxlength="3" value="<?php echo esc_attr( $opts['feed_currency'] ); ?>" class="small-text" style="text-transform:uppercase;">
								<p class="description"><?php esc_html_e( 'Three-letter ISO 4217 code used for base_price (e.g. ZAR, USD, EUR). Your on-site "R" symbol is display-only.', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="bbm_feed_brand"><?php esc_html_e( 'Brand', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_feed_brand" name="<?php echo $option_attr; ?>[feed_brand]" type="text" value="<?php echo esc_attr( $opts['feed_brand'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( (string) get_bloginfo( 'name' ) ); ?>">
								<p class="description"><?php esc_html_e( 'Brand applied to every hotel. Leave empty to use the site name.', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="bbm_feed_country"><?php esc_html_e( 'Country', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_feed_country" name="<?php echo $option_attr; ?>[feed_country]" type="text" value="<?php echo esc_attr( $opts['feed_country'] ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Applied to every hotel\'s address. Region comes from the top-level destination term and neighborhood from the reserve/sub-term automatically.', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="bbm_feed_city_field"><?php esc_html_e( 'City field (optional)', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_feed_city_field" name="<?php echo $option_attr; ?>[feed_city_field]" type="text" value="<?php echo esc_attr( $opts['feed_city_field'] ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Pods meta field holding the town/city for the address. Leave empty if you don\'t store one — latitude/longitude still pin the hotel.', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="bbm_feed_star_field"><?php esc_html_e( 'Star rating field (optional)', 'bushbreaks-maps' ); ?></label></th>
							<td>
								<input id="bbm_feed_star_field" name="<?php echo $option_attr; ?>[feed_star_rating_field]" type="text" value="<?php echo esc_attr( $opts['feed_star_rating_field'] ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Pods meta field holding a numeric star rating (e.g. 4 or 4.5). Output as star_rating when present.', 'bushbreaks-maps' ); ?></p>
							</td>
						</tr>
					</table>
					<p class="description"><?php esc_html_e( 'Listings without coordinates, an image, or a price are skipped — Meta requires a location, image and base_price for every hotel.', 'bushbreaks-maps' ); ?></p>
				</div>

				<p class="submit bbm-submit-row">
					<?php submit_button( null, 'primary', 'submit', false ); ?>
				</p>
			</form>

			<div class="bbm-tab-content" data-tab="tools">
				<h2><?php esc_html_e( 'Backfill coordinates', 'bushbreaks-maps' ); ?></h2>
				<p><?php esc_html_e( 'Resolve coordinates for every accommodation: iframe first, then geocode the location text. Already-cached entries are skipped.', 'bushbreaks-maps' ); ?></p>
				<p>
					<button type="button" class="button button-primary" id="bbm-backfill"><?php esc_html_e( 'Run backfill', 'bushbreaks-maps' ); ?></button>
					<span id="bbm-backfill-status" style="margin-left:10px;"></span>
				</p>

				<?php
				$missing       = Repository::find_missing_coords();
				$missing_count = count( $missing );
				?>
				<details class="bbm-collapsible">
					<summary>
						<?php
						if ( $missing_count === 0 ) {
							esc_html_e( 'Accommodations missing coordinates (0)', 'bushbreaks-maps' );
						} else {
							printf(
								/* translators: %d: number of accommodations missing coordinates */
								esc_html( _n( 'Accommodations missing coordinates (%d)', 'Accommodations missing coordinates (%d)', $missing_count, 'bushbreaks-maps' ) ),
								(int) $missing_count
							);
						}
						?>
					</summary>
					<div class="bbm-collapsible-body">
						<?php if ( $missing_count === 0 ) : ?>
							<p><?php esc_html_e( 'All accommodations have usable coordinates.', 'bushbreaks-maps' ); ?></p>
						<?php else : ?>
							<p>
								<?php
								printf(
									/* translators: %d: number of accommodations missing coordinates */
									esc_html( _n( '%d accommodation has no usable lat/lng and is hidden from the map.', '%d accommodations have no usable lat/lng and are hidden from the map.', $missing_count, 'bushbreaks-maps' ) ),
									(int) $missing_count
								);
								?>
							</p>
							<table class="widefat striped" style="max-width:720px;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Lodge', 'bushbreaks-maps' ); ?></th>
										<th><?php esc_html_e( 'Last sync status', 'bushbreaks-maps' ); ?></th>
										<th></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $missing as $m ) : ?>
										<tr>
											<td><?php echo esc_html( $m['title'] ); ?></td>
											<td><code><?php echo esc_html( $m['status'] !== '' ? $m['status'] : 'never processed' ); ?></code></td>
											<td>
												<?php if ( $m['edit_link'] ) : ?>
													<a href="<?php echo esc_url( $m['edit_link'] ); ?>"><?php esc_html_e( 'Edit', 'bushbreaks-maps' ); ?></a>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				</details>

				<hr />

				<h2><?php esc_html_e( 'Category order', 'bushbreaks-maps' ); ?></h2>
				<p><?php esc_html_e( 'Drag to reorder the categories that appear in the front-end filter dropdown. New categories added later appear at the end until reordered.', 'bushbreaks-maps' ); ?></p>
				<?php $this->render_category_order_list( $opts ); ?>

				<hr />

				<h2><?php esc_html_e( 'Region order', 'bushbreaks-maps' ); ?></h2>
				<p><?php esc_html_e( 'Drag to reorder regions and their child reserves. Each list can be reordered independently; to move a reserve between regions, change its parent on the WordPress term edit page.', 'bushbreaks-maps' ); ?></p>
				<?php $this->render_destination_order_list( $opts ); ?>
			</div>
		</div>
		<?php
	}

	private function render_category_order_list( array $opts ): void {
		$tax_slug = (string) ( $opts['category_taxonomy'] ?? '' );
		if ( $tax_slug === '' || ! taxonomy_exists( $tax_slug ) ) {
			echo '<p><em>' . esc_html__( 'Set the Category taxonomy slug above before configuring order.', 'bushbreaks-maps' ) . '</em></p>';
			return;
		}

		$terms = get_terms(
			[
				'taxonomy'   => $tax_slug,
				'hide_empty' => false,
			]
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			echo '<p><em>' . esc_html__( 'No categories found in this taxonomy.', 'bushbreaks-maps' ) . '</em></p>';
			return;
		}

		$terms = self::sort_terms_by_order( $terms );
		?>
		<style>
			.bbm-sortable { list-style: none; margin: 8px 0; padding: 0; max-width: 480px; }
			.bbm-sort-item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; margin: 0 0 4px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; }
			.bbm-sort-handle { cursor: grab; color: #8c8f94; font-size: 18px; line-height: 1; padding: 0 4px; user-select: none; }
			.bbm-sort-handle:active { cursor: grabbing; }
			.bbm-sort-label { font-size: 14px; }
			.bbm-sort-placeholder { background: #f0f7e0; border: 1px dashed <?php echo esc_attr( $opts['primary_color'] ?? '#8AD000' ); ?>; border-radius: 4px; margin: 0 0 4px; }
			.bbm-collapsible > summary { cursor: pointer; font-size: 14px; font-weight: 600; padding: 8px 0; list-style: none; display: inline-flex; align-items: center; gap: 6px; }
			.bbm-collapsible > summary::-webkit-details-marker { display: none; }
			.bbm-collapsible > summary::before { content: "\25B8"; display: inline-block; color: #8c8f94; transition: transform 0.15s ease; }
			.bbm-collapsible[open] > summary::before { transform: rotate(90deg); }
			.bbm-collapsible-body { padding: 8px 0 0; }
		</style>
		<ul id="bbm-category-order-list" class="bbm-sortable">
			<?php foreach ( $terms as $t ) : ?>
				<li class="bbm-sort-item" data-id="<?php echo esc_attr( (string) $t->term_id ); ?>">
					<span class="bbm-sort-handle" aria-hidden="true">&#8801;</span>
					<span class="bbm-sort-label"><?php echo esc_html( $t->name ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<p><span id="bbm-order-status" class="description" style="margin-left:0;"></span></p>
		<?php
	}

	public static function sort_terms_by_order( array $terms, string $meta_key = '_bbm_category_order' ): array {
		usort( $terms, function ( $a, $b ) use ( $meta_key ) {
			$id_a = is_object( $a ) ? (int) $a->term_id : (int) ( $a['id'] ?? 0 );
			$id_b = is_object( $b ) ? (int) $b->term_id : (int) ( $b['id'] ?? 0 );
			$oa   = (int) get_term_meta( $id_a, $meta_key, true );
			$ob   = (int) get_term_meta( $id_b, $meta_key, true );

			if ( $oa === $ob ) {
				$name_a = is_object( $a ) ? $a->name : (string) ( $a['name'] ?? '' );
				$name_b = is_object( $b ) ? $b->name : (string) ( $b['name'] ?? '' );
				return strcmp( $name_a, $name_b );
			}
			if ( $oa === 0 ) {
				return 1;
			}
			if ( $ob === 0 ) {
				return -1;
			}
			return $oa <=> $ob;
		} );
		return $terms;
	}

	public function ajax_reorder_categories(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( 'bushbreaks_maps_reorder_categories', 'nonce' );

		$order    = isset( $_POST['order'] ) && is_array( $_POST['order'] ) ? wp_unslash( $_POST['order'] ) : [];
		$position = 1;
		foreach ( $order as $term_id ) {
			$tid = (int) $term_id;
			if ( $tid > 0 ) {
				update_term_meta( $tid, '_bbm_category_order', $position );
				$position++;
			}
		}

		wp_send_json_success();
	}

	private function render_destination_order_list( array $opts ): void {
		$tax_slug = (string) ( $opts['destination_taxonomy'] ?? '' );
		if ( $tax_slug === '' || ! taxonomy_exists( $tax_slug ) ) {
			echo '<p><em>' . esc_html__( 'Set the Destination taxonomy slug above before configuring order.', 'bushbreaks-maps' ) . '</em></p>';
			return;
		}

		$terms = get_terms(
			[
				'taxonomy'   => $tax_slug,
				'hide_empty' => false,
			]
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			echo '<p><em>' . esc_html__( 'No destinations found in this taxonomy.', 'bushbreaks-maps' ) . '</em></p>';
			return;
		}

		$by_parent = [];
		foreach ( $terms as $t ) {
			$pid = (int) ( $t->parent ?? 0 );
			$by_parent[ $pid ][] = $t;
		}
		foreach ( $by_parent as $k => $list ) {
			$by_parent[ $k ] = self::sort_terms_by_order( $list, '_bbm_destination_order' );
		}

		?>
		<style>
			.bbm-dest-sortable { list-style: none; margin: 8px 0; padding: 0; max-width: 480px; }
			.bbm-dest-sortable .bbm-sort-item { display: block; padding: 0; margin: 0 0 4px; background: transparent; border: 0; }
			.bbm-dest-sortable .bbm-sort-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; }
			.bbm-dest-sortable .bbm-dest-sortable { margin: 4px 0 0 24px; }
			.bbm-dest-sortable .bbm-dest-sortable .bbm-sort-row { background: #f7f8f9; }
		</style>
		<ul class="bbm-sortable bbm-dest-sortable" data-parent="0">
		<?php
		foreach ( $by_parent[0] ?? [] as $parent ) {
			$this->render_destination_node( $parent, $by_parent );
		}
		echo '</ul>';
		echo '<p><span id="bbm-dest-order-status" class="description" style="margin-left:0;"></span></p>';
	}

	private function render_destination_node( $term, array $by_parent ): void {
		$children = $by_parent[ (int) $term->term_id ] ?? [];
		echo '<li class="bbm-sort-item" data-id="' . esc_attr( (string) $term->term_id ) . '">';
		echo '<div class="bbm-sort-row">';
		echo '<span class="bbm-sort-handle" aria-hidden="true">&#8801;</span>';
		echo '<span class="bbm-sort-label">' . esc_html( $term->name ) . '</span>';
		echo '</div>';
		if ( ! empty( $children ) ) {
			echo '<ul class="bbm-sortable bbm-dest-sortable" data-parent="' . esc_attr( (string) $term->term_id ) . '">';
			foreach ( $children as $child ) {
				$this->render_destination_node( $child, $by_parent );
			}
			echo '</ul>';
		}
		echo '</li>';
	}

	public function ajax_reorder_destinations(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( 'bushbreaks_maps_reorder_destinations', 'nonce' );

		$order    = isset( $_POST['order'] ) && is_array( $_POST['order'] ) ? wp_unslash( $_POST['order'] ) : [];
		$position = 1;
		foreach ( $order as $term_id ) {
			$tid = (int) $term_id;
			if ( $tid > 0 ) {
				update_term_meta( $tid, '_bbm_destination_order', $position );
				$position++;
			}
		}

		wp_send_json_success();
	}

	public function ajax_lodge_search(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( 'bushbreaks_maps_lodge_search', 'nonce' );

		$opts = self::all();
		$term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';

		$args = [
			'post_type'      => $opts['post_type'],
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];
		if ( $term !== '' ) {
			$args['s'] = $term;
		}
		$query = new \WP_Query( $args );

		$items = [];
		foreach ( $query->posts as $pid ) {
			$items[] = [
				'id'    => (int) $pid,
				'title' => html_entity_decode( get_the_title( (int) $pid ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			];
		}

		wp_send_json_success( [ 'items' => $items ] );
	}
}
