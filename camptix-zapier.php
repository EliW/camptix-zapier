<?php
/**
 * Plugin Name: CampTix Zapier Connector
 * Plugin URI: https://github.com/EliW/camptix-zapier
 * Description: A plugin that sends purchase triggers to a Zapier endpoint for additional integration
 * Author: Eli White
 * Author URI: https://eliw.com/
 * Version: 0.1
 * License: GPLv3 or later
 * Text Domain: camptix-zapier
 */

class CampTix_Zapier {
	protected static $instance = null;

	private function __construct() {
		if ( ! class_exists( 'CampTix_Plugin' ) ) {
			// Can't work if there isn't CampTix already loaded
			return;
		}

		add_action( 'camptix_load_addons', array( $this, 'camptix_load_addons' ) );
	}

	public static function get_instance() {
		return self::$instance ?
				self::$instance :
				( self::$instance = new self );
	}

	public function camptix_load_addons() {
		require_once __DIR__ . '/class-camptix-addon-zapier.php';

		camptix_register_addon( 'CampTix_Addon_Zapier' );
	}
}

add_action( 'plugins_loaded', array( 'CampTix_Zapier', 'get_instance' ) );
