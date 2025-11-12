<?php
namespace Lotzwoo;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use Lotzwoo\Admin\Settings_Page;
use Lotzwoo\Admin\Product_Flag;
use Lotzwoo\Frontend\Price_Prefix;

class Plugin {
	/** @var self */
	private static $instance;

	/** Singleton */
	public static function instance() : self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->define_constants();   // <- existiert jetzt sicher
		$this->bootstrap_autoload_fallbacks();
		$this->init_admin();
		$this->init_frontend();
	}

	/** Reserviert f??r sp??tere Feature-Flags/Konstanten */
	private function define_constants() : void {
		// z.B. define('LOTZWOO_FEATURE_X', true);
	}

	/** Absicherung, falls der Autoloader Unterordner nicht findet */
	private function bootstrap_autoload_fallbacks() : void {
		$base = defined('LOTZWOO_PLUGIN_DIR') ? LOTZWOO_PLUGIN_DIR : plugin_dir_path( __FILE__ ) . '../';
		$files = [
			'includes/admin/class-settings-page.php',
			'includes/admin/class-product-flag.php',
			'includes/frontend/class-price-prefix.php',
		];
		foreach ( $files as $rel ) {
			$file = $base . $rel;
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}

	private function init_admin() : void {
		if ( is_admin() ) {
			new Settings_Page();
			new Product_Flag();   // Produkt-Meta ???Ca-Artikel??? im Backend
		}
	}

	private function init_frontend() : void {
		new Price_Prefix();      // globales ???Ca. ???-Prefix in Frontend, Cart, Mails, Blocks-Bridge
	}
}

