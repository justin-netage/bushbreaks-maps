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
			'featured_field' => 'featured',
			'address_field'  => 'address',
			'thumbnail_size' => 'medium',
			'map_center_lat' => -23.6980,
			'map_center_lng' => 31.0498,
			'map_zoom'       => 6,
			'featured_limit' => 6,
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
		add_options_page(
			__( 'Bushbreaks Maps', 'bushbreaks-maps' ),
			__( 'Bushbreaks Maps', 'bushbreaks-maps' ),
			'manage_options',
			'bushbreaks-maps',
			[ $this, 'render_page' ]
		);
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

		$text_keys = [ 'post_type', 'lat_field', 'lng_field', 'featured_field', 'address_field', 'thumbnail_size', 'tile_url', 'tile_attr' ];
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
		if ( isset( $input['featured_limit'] ) && is_numeric( $input['featured_limit'] ) ) {
			$out['featured_limit'] = max( 1, min( 50, (int) $input['featured_limit'] ) );
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
						<th><label for="bbm_featured"><?php esc_html_e( 'Featured boolean field', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_featured" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[featured_field]" type="text" value="<?php echo esc_attr( $opts['featured_field'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_address"><?php esc_html_e( 'Address field (optional)', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_address" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[address_field]" type="text" value="<?php echo esc_attr( $opts['address_field'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_thumb"><?php esc_html_e( 'Thumbnail size', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_thumb" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[thumbnail_size]" type="text" value="<?php echo esc_attr( $opts['thumbnail_size'] ); ?>" class="regular-text"></td>
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
						<th><label for="bbm_featured_limit"><?php esc_html_e( 'Featured list limit', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_featured_limit" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[featured_limit]" type="number" min="1" max="50" value="<?php echo esc_attr( (string) $opts['featured_limit'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_tile"><?php esc_html_e( 'Map tile URL', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_tile" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[tile_url]" type="text" value="<?php echo esc_attr( $opts['tile_url'] ); ?>" class="large-text"></td>
					</tr>
					<tr>
						<th><label for="bbm_tile_attr"><?php esc_html_e( 'Tile attribution', 'bushbreaks-maps' ); ?></label></th>
						<td><input id="bbm_tile_attr" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[tile_attr]" type="text" value="<?php echo esc_attr( $opts['tile_attr'] ); ?>" class="large-text"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
