<?php
namespace Lotzwoo\Admin;

use Lotzwoo\Plugin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Product_Flag {
	public function __construct() {
		// Feld im Produkt-Backend
		add_action( 'woocommerce_product_options_general_product_data', [ $this, 'add_field' ] );
		// Speichern
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_field' ] );
	}

	private function get_meta_key() : string {
		$opts = get_option( 'lotzwoo_options', [] );
		if ( isset( $opts['meta_key'] ) ) {
			return $opts['meta_key'];
		}
		return Plugin::opt( 'meta_key', '_ca_is_estimated' );
	}

	public function add_field() : void {
		if ( ! Plugin::ca_prices_enabled() ) {
			return;
		}

		$meta_key = $this->get_meta_key();
		echo '<div class="options_group">';
		woocommerce_wp_checkbox( [
			'id'          => $meta_key,
			'label'       => __( 'Endpreis steht erst beim Kommissionieren fest?', 'lotzapp-for-woocommerce' ),
			'description' => __( 'Aktiviere, wenn dies ein Ca.-Artikel ist. Preise werden mit Prefix angezeigt und Summen entsprechend gekennzeichnet.', 'lotzapp-for-woocommerce' ),
		] );
		echo '</div>';
	}

	public function save_field( \WC_Product $product ) : void {
		if ( ! Plugin::ca_prices_enabled() ) {
			return;
		}

		$meta_key = $this->get_meta_key();
		$value    = isset( $_POST[ $meta_key ] ) ? 'yes' : 'no';
		$product->update_meta_data( $meta_key, $value );
	}
}

