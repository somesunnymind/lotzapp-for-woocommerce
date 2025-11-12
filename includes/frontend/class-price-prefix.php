<?php
namespace Lotzwoo\Frontend;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Price_Prefix {

	public function __construct() {
		// Einheitlich NUR ??ber get_price_html arbeiten ??? vermeidet Doppel-Prefix bei variablen/ gruppierten Preisen
		add_filter( 'woocommerce_get_price_html', [ $this, 'maybe_prefix_product_price' ], 10, 2 );

		// Cart/Checkout (Classic Templates) ??? Itempreise & Subtotals/Total
		add_filter( 'woocommerce_cart_item_price', [ $this, 'maybe_prefix_cart_item_price' ], 10, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', [ $this, 'maybe_prefix_cart_item_subtotal' ], 10, 3 );
		add_filter( 'woocommerce_cart_subtotal', [ $this, 'maybe_prefix_cart_subtotal_row' ], 10, 3 );
		add_filter( 'woocommerce_cart_totals_order_total_html', [ $this, 'maybe_prefix_cart_total_html' ], 10, 1 );

		// Mini-Cart (Widget) ??? Subtotal & Total separat filtern
		add_filter( 'woocommerce_widget_shopping_cart_subtotal', [ $this, 'maybe_prefix_widget_subtotal' ], 10, 1 );
		add_filter( 'woocommerce_widget_shopping_cart_total', [ $this, 'maybe_prefix_widget_total' ], 10, 1 );

		// Bestellungen / E-Mails ??? Gesamtsumme
		add_filter( 'woocommerce_get_formatted_order_total', [ $this, 'maybe_prefix_order_total' ], 10, 3 );

		// Blocks-Checkout: kleiner JS-Shim, wenn Ca-Artikel im Warenkorb
		add_action( 'enqueue_block_assets', [ $this, 'enqueue_blocks_bridge' ] );
	}

	/* ===== Helpers ===== */

	private function get_prefix() : string {
		$opts   = get_option( 'lotzwoo_options', [] );
		$prefix = isset( $opts['prefix'] ) ? (string) $opts['prefix'] : 'Ca. ';
		return $prefix;
	}

	private function get_meta_key() : string {
		$opts = get_option( 'lotzwoo_options', [] );
		return $opts['meta_key'] ?? '_ca_is_estimated';
	}

	private function has_prefix( string $html ) : bool {
		$p = trim( wp_strip_all_tags( $this->get_prefix() ) );
		return $p !== '' && strpos( wp_strip_all_tags( $html ), $p ) === 0;
	}

	private function prefix( string $html ) : string {
		if ( $html === '' ) return $html;
		if ( $this->has_prefix( $html ) ) return $html; // doppelt vermeiden
		return esc_html( $this->get_prefix() ) . $html;
	}

	/** ???Ist Ca-Produkt???? ??? streng: nur 'yes'; inkl. Parent-Fallback f??r Variationen. */
	private function is_ca_product( $product ) : bool {
		if ( ! $product ) return false;
		$meta_key = $this->get_meta_key();

		$val = $product->get_meta( $meta_key, true );
		if ( $val === 'yes' ) return true;

		// Variation ??? Parent pr??fen
		if ( method_exists( $product, 'get_parent_id' ) ) {
			$pid = (int) $product->get_parent_id();
			if ( $pid ) {
				$parent = wc_get_product( $pid );
				if ( $parent ) {
					$pv = $parent->get_meta( $meta_key, true );
					return $pv === 'yes';
				}
			}
		}
		return false;
	}

	private function cart_has_ca_items() : bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) return false;
		foreach ( WC()->cart->get_cart() as $item ) {
			$p = $item['data'] ?? null;
			if ( $this->is_ca_product( $p ) ) return true;
		}
		return false;
	}

	private function order_has_ca_items( \WC_Order $order ) : bool {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $this->is_ca_product( $item->get_product() ) ) return true;
		}
		return false;
	}

	/* ===== Produktpreise (Katalog/Single) ===== */

	public function maybe_prefix_product_price( string $price_html, $product ) : string {
		return $this->is_ca_product( $product ) ? $this->prefix( $price_html ) : $price_html;
	}

	/* ===== Cart/Checkout (Classic Templates) ===== */

	public function maybe_prefix_cart_item_price( string $price_html, array $cart_item, string $cart_item_key ) : string {
		$product = $cart_item['data'] ?? null;
		return $this->is_ca_product( $product ) ? $this->prefix( $price_html ) : $price_html;
	}

	public function maybe_prefix_cart_item_subtotal( string $subtotal_html, array $cart_item, string $cart_item_key ) : string {
		$product = $cart_item['data'] ?? null;
		return $this->is_ca_product( $product ) ? $this->prefix( $subtotal_html ) : $subtotal_html;
	}

	public function maybe_prefix_cart_subtotal_row( string $cart_subtotal_html, bool $compound, $cart ) : string {
		return $this->cart_has_ca_items() ? $this->prefix( $cart_subtotal_html ) : $cart_subtotal_html;
	}

	public function maybe_prefix_cart_total_html( string $order_total_html ) : string {
		return $this->cart_has_ca_items() ? $this->prefix( $order_total_html ) : $order_total_html;
	}

	/* ===== Mini-Cart (Widget) ===== */

	public function maybe_prefix_widget_subtotal( string $html ) : string {
		return $this->cart_has_ca_items() ? $this->prefix( $html ) : $html;
	}

	public function maybe_prefix_widget_total( string $html ) : string {
		return $this->cart_has_ca_items() ? $this->prefix( $html ) : $html;
	}

	/* ===== Orders / E-Mails ===== */

	public function maybe_prefix_order_total( string $formatted_total, \WC_Order $order, bool $tax_display ) : string {
		return $this->order_has_ca_items( $order ) ? $this->prefix( $formatted_total ) : $formatted_total;
	}

	/* ===== WooCommerce Blocks Bridge (Checkout/Cart) ===== */
	public function enqueue_blocks_bridge() : void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;
		if ( ! $this->cart_has_ca_items() ) return;

		$handle = 'lotzwoo-blocks-bridge';
		wp_register_script( $handle, '', [], '1.0.1', true );
		wp_add_inline_script( $handle, $this->blocks_inline_js(), 'after' );
		wp_enqueue_script( $handle );
		wp_localize_script( $handle, 'lotzwoo_BLOCKS', [ 'prefix' => $this->get_prefix() ] );
	}

	private function blocks_inline_js() : string {
		return <<<JS
(function(){
	function ready(fn){ if(document.readyState!=='loading'){fn();} else {document.addEventListener('DOMContentLoaded',fn);} }
	ready(function(){
		var reg = window?.wc?.blocksCheckout?.registerCheckoutFilters;
		if (typeof reg !== 'function' || !window.lotzwoo_BLOCKS) return;
		var ns = 'lotzwoo-price-prefix';
		var P = function(html){ if(!html) return html; var p=(lotzwoo_BLOCKS.prefix||'Ca. ').trim(); if(html.replace(/<[^>]*>/g,'').trim().indexOf(p)===0){return html;} return p+' '+html; };
		reg(ns, {
			cartItemPrice: function(def, extensions, args){ return P(def); },
			subtotalPriceFormat: function(def){ return (lotzwoo_BLOCKS.prefix||'Ca. ') + ' <price/>'; }
		});
	});
})();
JS;
	}
}

