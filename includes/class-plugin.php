<?php
namespace Bushbreaks_Maps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		$this->maybe_migrate_options();
		( new Settings() )->register();
		( new Shortcode() )->register();
		( new Feed() )->register();
		( new Ajax() )->register();
		( new Coords_Sync() )->register();
	}

	private function maybe_migrate_options(): void {
		$stored = get_option( Settings::OPTION_KEY );
		if ( ! is_array( $stored ) ) {
			return;
		}
		$dirty = false;

		// One-shot: bump the stored default thumbnail_size from the old
		// 'medium' to 'large' so existing installs benefit from the
		// higher-resolution default. If an admin explicitly chose 'medium',
		// they can change it back via Settings.
		if ( isset( $stored['thumbnail_size'] ) && $stored['thumbnail_size'] === 'medium' ) {
			$stored['thumbnail_size'] = 'large';
			$dirty = true;
		}

		if ( $dirty ) {
			update_option( Settings::OPTION_KEY, $stored );
		}
	}
}
