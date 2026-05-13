<?php
/**
 * Plugin Name: Bushbreaks Maps
 * Description: Displays a map of lodge accommodations (from a Pods custom post type) with search and a featured list.
 * Version:     0.8.0
 * Author:      Net Age
 * License:     GPL-2.0-or-later
 * Text Domain: bushbreaks-maps
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BUSHBREAKS_MAPS_VERSION', '0.8.0' );
define( 'BUSHBREAKS_MAPS_FILE', __FILE__ );
define( 'BUSHBREAKS_MAPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'BUSHBREAKS_MAPS_URL', plugin_dir_url( __FILE__ ) );

require_once BUSHBREAKS_MAPS_DIR . 'includes/class-settings.php';
require_once BUSHBREAKS_MAPS_DIR . 'includes/class-repository.php';
require_once BUSHBREAKS_MAPS_DIR . 'includes/class-geocoder.php';
require_once BUSHBREAKS_MAPS_DIR . 'includes/class-coords-sync.php';
require_once BUSHBREAKS_MAPS_DIR . 'includes/class-shortcode.php';
require_once BUSHBREAKS_MAPS_DIR . 'includes/class-ajax.php';
require_once BUSHBREAKS_MAPS_DIR . 'includes/class-plugin.php';

require_once BUSHBREAKS_MAPS_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

$bushbreaks_maps_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/justin-netage/bushbreaks-maps/',
	BUSHBREAKS_MAPS_FILE,
	'bushbreaks-maps'
);
$bushbreaks_maps_update_checker->setBranch( 'main' );
$bushbreaks_maps_update_checker->getVcsApi()->enableReleaseAssets();

add_action( 'plugins_loaded', static function () {
	Bushbreaks_Maps\Plugin::instance()->init();
} );
