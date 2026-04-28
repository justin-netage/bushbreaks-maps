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
			'category_taxonomy'       => 'popular_request',
			'image_field'         => 'banner',
			'normal_price_field'      => 'normal_price',
			'special_price_field'     => 'special_price',
			'price_description_field' => 'price_description',
			'valid_from_field'    => 'valid_from',
			'valid_until_field'   => 'valid_until',
			'currency_symbol'     => 'R',
			'thumbnail_size'      => 'medium',
			'map_center_lat' => -23.6980,
			'map_center_lng' => 31.0498,
			'map_zoom'       => 6,
			'list_limit'     => 10,
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
			wp_enqueue_script(
				'bushbreaks-maps-admin',
				BUSHBREAKS_MAPS_URL . 'assets/js/bbm-admin.js',
				[ 'jquery' ],
				BUSHBREAKS_MAPS_VERSION,
				true
			);
			wp_localize_script(
				'bushbreaks-maps-admin',
				'BushbreaksMapsAdmin',
				[
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'bushbreaks_maps_backfill' ),
					'i18n'    => [
						'starting'    => __( 'Starting…', 'bushbreaks-maps' ),
						'progress'    => __( 'Processed %1$s of %2$s…', 'bushbreaks-maps' ),
						'done'        => __( 'Done. Processed %1$s of %2$s.', 'bushbreaks-maps' ),
						'error'       => __( 'Error during backfill.', 'bushbreaks-maps' ),
						'networkError'=> __( 'Network error.', 'bushbreaks-maps' ),
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

		$text_keys = [ 'post_type', 'lat_field', 'lng_field', 'address_field', 'iframe_field', 'location_field', 'destination_taxonomy', 'category_taxonomy', 'image_field', 'normal_price_field', 'special_price_field', 'price_description_field', 'valid_from_field', 'valid_until_field', 'currency_symbol', 'thumbnail_size', 'google_maps_api_key', 'tile_url', 'tile_attr' ];
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
		$opts = self::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bushbreaks Maps', 'bushbreaks-maps' ); ?></h1>
			<p><?php esc_html_e( 'Use the [bushbreaks_map] shortcode to embed the map. Configure the Pods post type and field names below.', 'bushbreaks-maps' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'bushbreaks_maps' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="bbm_post_type"><?php esc_html_e( 'Pod / Post Type slug', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_post_type" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[post_type]" type="text" value="<?php echo esc_attr( $opts['post_type'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_lat"><?php esc_html_e( 'Latitude field', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_lat" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[lat_field]" type="text" value="<?php echo esc_attr( $opts['lat_field'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_lng"><?php esc_html_e( 'Longitude field', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_lng" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[lng_field]" type="text" value="<?php echo esc_attr( $opts['lng_field'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_address"><?php esc_html_e( 'Address field (shown on cards)', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_address" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[address_field]" type="text" value="<?php echo esc_attr( $opts['address_field'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_iframe"><?php esc_html_e( 'Google Maps iframe field', 'bushbreaks-maps' ); ?></label></th>
						<td>
							<input id="bbm_iframe" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[iframe_field]" type="text" value="<?php echo esc_attr( $opts['iframe_field'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'When set, lat/lng are extracted from the iframe on save (preferred source).', 'bushbreaks-maps' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="bbm_location"><?php esc_html_e( 'Location text field (geocoded)', 'bushbreaks-maps' ); ?></label></th>
						<td>
							<input id="bbm_location" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[location_field]" type="text" value="<?php echo esc_attr( $opts['location_field'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Used as a fallback when no iframe is set. Geocoded via OpenStreetMap Nominatim and cached.', 'bushbreaks-maps' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="bbm_destination_tax"><?php esc_html_e( 'Destination taxonomy slug', 'bushbreaks-maps' ); ?></label></th>
						<td>
							<input id="bbm_destination_tax" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[destination_taxonomy]" type="text" value="<?php echo esc_attr( $opts['destination_taxonomy'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Custom taxonomy used for destinations. Search will match lodges tagged with destinations whose name matches the query.', 'bushbreaks-maps' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="bbm_category_tax"><?php esc_html_e( 'Category taxonomy slug', 'bushbreaks-maps' ); ?></label></th>
						<td>
							<input id="bbm_category_tax" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[category_taxonomy]" type="text" value="<?php echo esc_attr( $opts['category_taxonomy'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Taxonomy whose terms appear in the front-end filter dropdown beneath the search bar (e.g. popular_request).', 'bushbreaks-maps' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="bbm_image"><?php esc_html_e( 'Image field (Pods)', 'bushbreaks-maps' ); ?></label></th>
						<td>
							<input id="bbm_image" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[image_field]" type="text" value="<?php echo esc_attr( $opts['image_field'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Pods image/file field used as the card thumbnail. Falls back to the WordPress featured image when empty.', 'bushbreaks-maps' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="bbm_thumb"><?php esc_html_e( 'Thumbnail size', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_thumb" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[thumbnail_size]" type="text" value="<?php echo esc_attr( $opts['thumbnail_size'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_normal_price"><?php esc_html_e( 'Normal price field', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_normal_price" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[normal_price_field]" type="text" value="<?php echo esc_attr( $opts['normal_price_field'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_special_price"><?php esc_html_e( 'Special price field', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_special_price" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[special_price_field]" type="text" value="<?php echo esc_attr( $opts['special_price_field'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_price_desc"><?php esc_html_e( 'Price description field', 'bushbreaks-maps' ); ?></label></th>
						<td>
							<input id="bbm_price_desc" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[price_description_field]" type="text" value="<?php echo esc_attr( $opts['price_description_field'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Short unit shown next to the price (e.g. ppn, pn, ppp).', 'bushbreaks-maps' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="bbm_valid_from"><?php esc_html_e( 'Special valid-from field', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_valid_from" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[valid_from_field]" type="text" value="<?php echo esc_attr( $opts['valid_from_field'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_valid_until"><?php esc_html_e( 'Special valid-until field', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_valid_until" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[valid_until_field]" type="text" value="<?php echo esc_attr( $opts['valid_until_field'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_currency"><?php esc_html_e( 'Currency symbol', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_currency" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[currency_symbol]" type="text" value="<?php echo esc_attr( $opts['currency_symbol'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_clat"><?php esc_html_e( 'Default map centre latitude', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_clat" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[map_center_lat]" type="number" step="any" value="<?php echo esc_attr( (string) $opts['map_center_lat'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_clng"><?php esc_html_e( 'Default map centre longitude', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_clng" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[map_center_lng]" type="number" step="any" value="<?php echo esc_attr( (string) $opts['map_center_lng'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_zoom"><?php esc_html_e( 'Default zoom (1-19)', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_zoom" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[map_zoom]" type="number" min="1" max="19" value="<?php echo esc_attr( (string) $opts['map_zoom'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_list_limit"><?php esc_html_e( 'List limit', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_list_limit" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[list_limit]" type="number" min="1" max="50" value="<?php echo esc_attr( (string) $opts['list_limit'] ); ?>" class="small-text"></td>
					</tr>
					<tr><th colspan="2"><h2 style="margin:8px 0 0"><?php esc_html_e( 'Custom markers', 'bushbreaks-maps' ); ?></h2></th></tr>
					<tr>
						<th><label for="bbm_marker_url"><?php esc_html_e( 'Lodge marker image', 'bushbreaks-maps' ); ?></label></th>
						<td>
							<input id="bbm_marker_url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[marker_icon_url]" type="text" value="<?php echo esc_attr( $opts['marker_icon_url'] ); ?>" class="large-text" autocomplete="off">
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
							<input id="bbm_marker_width" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[marker_icon_width]" type="number" min="8" max="256" value="<?php echo esc_attr( (string) $opts['marker_icon_width'] ); ?>" style="width:90px"> &times;
							<input id="bbm_marker_height" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[marker_icon_height]" type="number" min="8" max="256" value="<?php echo esc_attr( (string) $opts['marker_icon_height'] ); ?>" style="width:90px">
							<p class="description"><?php esc_html_e( 'Width × height. Filled in automatically when you pick from the media library.', 'bushbreaks-maps' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="bbm_cluster_url"><?php esc_html_e( 'Cluster icon', 'bushbreaks-maps' ); ?></label></th>
						<td>
							<input id="bbm_cluster_url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cluster_icon_url]" type="text" value="<?php echo esc_attr( $opts['cluster_icon_url'] ); ?>" class="large-text" autocomplete="off">
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
							<input id="bbm_cluster_size" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cluster_icon_size]" type="number" min="16" max="200" value="<?php echo esc_attr( (string) $opts['cluster_icon_size'] ); ?>" style="width:90px">
						</td>
					</tr>
					<tr><th colspan="2"><h2 style="margin:8px 0 0"><?php esc_html_e( 'Map provider', 'bushbreaks-maps' ); ?></h2></th></tr>
					<tr>
						<th><label for="bbm_gmaps_key"><?php esc_html_e( 'Google Maps API key', 'bushbreaks-maps' ); ?></label></th>
						<td>
							<input id="bbm_gmaps_key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[google_maps_api_key]" type="text" value="<?php echo esc_attr( $opts['google_maps_api_key'] ); ?>" class="large-text" autocomplete="off">
							<p class="description"><?php esc_html_e( 'When set, the front-end map switches to Google Maps. Leave empty to use OpenStreetMap (Leaflet).', 'bushbreaks-maps' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="bbm_tile"><?php esc_html_e( 'Leaflet tile URL', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_tile" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[tile_url]" type="text" value="<?php echo esc_attr( $opts['tile_url'] ); ?>" class="large-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_tile_attr"><?php esc_html_e( 'Tile attribution', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_tile_attr" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[tile_attr]" type="text" value="<?php echo esc_attr( $opts['tile_attr'] ); ?>" class="large-text"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Backfill coordinates', 'bushbreaks-maps' ); ?></h2>
			<p><?php esc_html_e( 'Resolve coordinates for every accommodation: iframe first, then geocode the location text. Already-cached entries are skipped.', 'bushbreaks-maps' ); ?></p>
			<p>
				<button type="button" class="button button-primary" id="bbm-backfill"><?php esc_html_e( 'Run backfill', 'bushbreaks-maps' ); ?></button>
				<span id="bbm-backfill-status" style="margin-left:10px;"></span>
			</p>

			<hr />

			<h2><?php esc_html_e( 'Accommodations missing coordinates', 'bushbreaks-maps' ); ?></h2>
			<?php $missing = Repository::find_missing_coords(); ?>
			<?php if ( empty( $missing ) ) : ?>
				<p><?php esc_html_e( 'All accommodations have usable coordinates.', 'bushbreaks-maps' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: %d: number of accommodations missing coordinates */
						esc_html( _n( '%d accommodation has no usable lat/lng and is hidden from the map.', '%d accommodations have no usable lat/lng and are hidden from the map.', count( $missing ), 'bushbreaks-maps' ) ),
						(int) count( $missing )
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
		<?php
	}
}
