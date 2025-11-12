<?php

namespace Lotzwoo\Frontend;

use Lotzwoo\Plugin;
use Lotzwoo\Helpers\Estimated;

class Price_Prefix
{
    public function __construct()
    {
        add_filter('woocommerce_get_price_html', [$this, 'filter_price_html'], 10, 2);
        add_filter('woocommerce_cart_item_price', [$this, 'filter_cart_item_price'], 10, 3);
        add_filter('woocommerce_cart_item_subtotal', [$this, 'filter_cart_item_subtotal'], 10, 3);
        add_filter('woocommerce_cart_totals_order_total_html', [$this, 'filter_cart_order_total_html'], 10, 1);
        add_filter('woocommerce_cart_totals_subtotal_html', [$this, 'filter_cart_subtotal_html'], 10, 1);
        add_filter('woocommerce_cart_subtotal', [$this, 'filter_cart_subtotal'], 10, 3);
        add_filter('woocommerce_get_formatted_order_total', [$this, 'filter_order_total'], 10, 2);

        // Blocks bridge
        add_action('enqueue_block_assets', [$this, 'enqueue_blocks_bridge']);
    }

    private function get_prefix(): string
    {
        $prefix = (string) Plugin::opt('price_prefix', 'Ca. ');
        return trim($prefix);
    }

    private function get_total_prefix(): string
    {
        $total_prefix = Plugin::opt('total_prefix', null);
        if (!is_string($total_prefix)) {
            $total_prefix = '';
        }
        $total_prefix = trim((string) $total_prefix);
        if ($total_prefix === '') {
            $total_prefix = $this->get_prefix();
        }
        return $total_prefix;
    }


    public function filter_price_html($price_html, $product)
    {
        if (Estimated::is_estimated_product($product)) {
            $prefix = $this->get_prefix();
            return esc_html($prefix) . ' ' . $price_html;
        }
        return $price_html;
    }

    private function add_prefix_once(string $html, ?string $custom_prefix = null): string
    {
        $prefix = $custom_prefix !== null ? trim((string) $custom_prefix) : $this->get_prefix();
        if ($prefix === '') {
            return $html;
        }
        // Avoid double-prefixing
        if (strpos(trim(wp_strip_all_tags($html)), $prefix) === 0) {
            return $html;
        }
        return esc_html($prefix) . ' ' . $html;
    }

    private function wrap_prefixed_html(string $html, string $context): string
    {
        if (strpos($html, 'data-lotzwoo-estimated="yes"') !== false || strpos($html, 'lotzwoo-price--' . $context) !== false) {
            return $this->add_prefix_once($html);
        }

        $prefixed = $this->add_prefix_once($html);
        $context_slug = preg_replace('/[^a-z0-9_-]/i', '', $context);
        if ($context_slug === '') {
            $context_slug = 'amount';
        }

        $classes = 'lotzwoo-price lotzwoo-price--' . $context_slug;
        return sprintf('<span class="%s" data-lotzwoo-estimated="yes">%s</span>', esc_attr($classes), $prefixed);
    }

    public function filter_cart_item_price($price_html, $cart_item, $cart_item_key)
    {
        $product = isset($cart_item['data']) ? $cart_item['data'] : null;
        if (Estimated::is_estimated_product($product)) {
            return $this->wrap_prefixed_html($price_html, 'line-price');
        }
        return $price_html;
    }

    public function filter_cart_item_subtotal($subtotal_html, $cart_item, $cart_item_key)
    {
        $product = isset($cart_item['data']) ? $cart_item['data'] : null;
        if (Estimated::is_estimated_product($product)) {
            return $this->wrap_prefixed_html($subtotal_html, 'line-subtotal');
        }
        return $subtotal_html;
    }

    public function filter_cart_order_total_html($total_html)
    {
        if (Estimated::cart_has_estimated()) {
            return $this->add_prefix_once($total_html, $this->get_total_prefix());
        }
        return $total_html;
    }

    public function filter_cart_subtotal_html($subtotal_html)
    {
        if (Estimated::cart_has_estimated()) {
            return $this->add_prefix_once($subtotal_html);
        }
        return $subtotal_html;
    }

    public function filter_cart_subtotal($cart_subtotal, $compound, $cart)
    {
        if ($cart instanceof \WC_Cart && Estimated::cart_has_estimated($cart)) {
            return $this->add_prefix_once($cart_subtotal);
        }
        return $cart_subtotal;
    }

    public function filter_order_total($formatted_total, $order)
    {
        // Prefix the overall total in emails/admin when any estimated item is present
        $has_estimated = false;
        if ($order && method_exists($order, 'get_items')) {
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if (Estimated::is_estimated_product($product)) {
                    $has_estimated = true;
                    break;
                }
            }
        }

        if ($has_estimated) {
            return $this->add_prefix_once($formatted_total, $this->get_total_prefix());
        }
        return $formatted_total;
    }

    public function enqueue_blocks_bridge(): void
    {
        if (!defined('LOTZWOO_FEATURE_BLOCKS') || !LOTZWOO_FEATURE_BLOCKS) {
            return;
        }

        $cart        = function_exists('WC') ? WC()->cart : null;
        $identifiers = Estimated::get_cart_estimated_identifiers($cart);
        $cart_items  = Estimated::get_cart_items_snapshot($cart);

        $has_estimated     = Estimated::cart_has_estimated($cart);
        $range_enabled     = (bool) Plugin::opt('show_range_note', 0);
        $buffer_amount     = ($range_enabled && $has_estimated) ? Estimated::get_cart_buffer_amount($cart) : 0.0;
        $totals            = ($cart && method_exists($cart, 'get_totals')) ? (array) $cart->get_totals() : [];
        $cart_total        = isset($totals['total']) ? (float) $totals['total'] : 0.0;
        $range_min         = max(0.0, $cart_total - $buffer_amount);
        $range_note_html   = '';
        if ($range_enabled && $buffer_amount > 0) {
            $range_note_html = sprintf('min. %s', wc_price($range_min));
        }

        $line_prefix   = $this->get_prefix();
        $total_prefix  = $this->get_total_prefix();

        $data = [
            'prefix'                 => $line_prefix,
            'linePrefix'             => $line_prefix,
            'totalPrefix'            => $total_prefix,
            'hasEstimated'           => $has_estimated,
            'estimatedProductIds'    => $identifiers['product_ids'],
            'estimatedVariationIds'  => $identifiers['variation_ids'],
            'estimatedCartItemKeys'  => $identifiers['cart_item_keys'],
            'bufferProductId'        => (int) Plugin::opt('buffer_product_id'),
            'cartItems'              => $cart_items,
            'rangeMin'               => $range_min,
            'bufferAmount'           => $buffer_amount,
            'rangeEnabled'           => ($range_enabled && $range_note_html !== ''),
            'rangeNoteHtml'          => $range_note_html,
        ];

        // Enqueue a tiny inline bridge; no external file needed.
        $handle = 'lotzwoo-blocks-bridge';
        wp_register_script($handle, '', [], '0.1.0', true);
        wp_enqueue_script($handle);
        wp_add_inline_script($handle, 'window.lotzwoo = ' . wp_json_encode($data) . ';', 'before');

        $inline = <<<'JS'
(function(){
  try {
    if (!window.lotzwoo || !window.lotzwoo.hasEstimated) { return; }
    var linePrefix = (window.lotzwoo.linePrefix || window.lotzwoo.prefix || '').trim();
    var totalPrefix = (window.lotzwoo.totalPrefix || linePrefix || '').trim();
    if (!linePrefix && !totalPrefix) { return; }
    var prefixText = function(text, prefixValue){
      if (!prefixValue) { return text; }
      if (!text) { return text; }
      var t = ('' + text).trim();
      return t.indexOf(prefixValue) === 0 ? text : prefixValue + ' ' + t;
    };

    var container = document.querySelector('.wc-block-cart, .wc-block-checkout');
    if (!container) { return; }

    var lineSelectors = [
      '.wc-block-components-product-price',
      '.wc-block-cart-item__row-price',
      '.wc-block-cart-item__total',
      '.wc-block-cart-item__prices .wc-block-components-product-price',
      '.wc-block-components-order-summary-item__total',
      '.wc-block-components-order-summary-item__total .wc-block-components-formatted-money-amount',
      '.wc-block-components-order-summary-item__price',
      '.wc-block-components-formatted-money-amount'
    ];

    var totalSelectors = [
      '.wc-block-components-totals-item__value',
      '.wc-block-cart__totals .wc-block-components-totals-item-value',
      '.wc-block-components-totals-footer-item .wc-block-components-formatted-money-amount',
      '.wc-block-checkout-order-summary__totals .wc-block-components-formatted-money-amount'
    ];

    var rowSelectors = [
      '.wc-block-cart-items__row',
      '.wc-block-components-order-summary-item',
      '.wc-block-checkout-order-summary__item'
    ];

    var allPriceSelectors = lineSelectors.concat(totalSelectors);

    var estProducts = new Set((window.lotzwoo.estimatedProductIds || []).map(function(v){ return parseInt(v, 10); }));
    var estVariations = new Set((window.lotzwoo.estimatedVariationIds || []).map(function(v){ return parseInt(v, 10); }));
    var estKeys = new Set(window.lotzwoo.estimatedCartItemKeys || []);
    var bufferId = parseInt(window.lotzwoo.bufferProductId || 0, 10);
    var isCheckout = container.classList.contains('wc-block-checkout');
    var rangeEnabled = !!window.lotzwoo.rangeEnabled;
    var predefinedRange = (window.lotzwoo.rangeNoteHtml || '').trim();
    var bufferAmount = parseFloat(window.lotzwoo.bufferAmount || 0);
    if (isNaN(bufferAmount)) { bufferAmount = 0; }
    var currency = null;
    if (window.wc && window.wc.wcSettings && window.wc.wcSettings.currency) {
      currency = window.wc.wcSettings.currency;
    } else if (window.wcSettings && window.wcSettings.currency) {
      currency = window.wcSettings.currency;
    }

    function getMinorUnit(){
      if (!currency) { return 2; }
      if (typeof currency.minorUnit === 'number') { return currency.minorUnit; }
      if (typeof currency.minorUnit === 'string') {
        var parsedMinor = parseInt(currency.minorUnit, 10);
        if (!isNaN(parsedMinor)) { return parsedMinor; }
      }
      if (typeof currency.decimals === 'number') { return currency.decimals; }
      if (typeof currency.decimals === 'string') {
        var parsedDecimals = parseInt(currency.decimals, 10);
        if (!isNaN(parsedDecimals)) { return parsedDecimals; }
      }
      return 2;
    }

    function formatNumber(amount){
      var decimals = getMinorUnit();
      var decSeparator = '.';
      var thouSeparator = ',';
      if (currency) {
        if (currency.decimalSeparator) {
          decSeparator = currency.decimalSeparator;
        }
        if (currency.thousandSeparator) {
          thouSeparator = currency.thousandSeparator;
        }
      }

      var negative = amount < 0;
      var number = Math.abs(amount).toFixed(decimals);
      var parts = number.split('.');
      parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thouSeparator);
      var formatted = parts.join(decSeparator);
      return (negative ? '-' : '') + formatted;
    }

    function formatCurrency(amount){
      if (!currency) {
        return formatNumber(amount);
      }
      var priceFormat = currency.priceFormat || '%1$s%2$s';
      var symbol = currency.symbol || '';
      return priceFormat.replace('%1$s', symbol).replace('%2$s', formatNumber(amount));
    }

    function parseCurrency(text){
      if (!text) { return 0; }
      var cleaned = text.replace(/[^0-9,.-]/g, '');
      if (currency) {
        var thou = currency.thousandSeparator || ',';
        var dec = currency.decimalSeparator || '.';
        if (thou && thou !== dec) {
          var thouReg = new RegExp('\\' + thou, 'g');
          cleaned = cleaned.replace(thouReg, '');
        }
        if (dec && dec !== '.') {
          var decReg = new RegExp('\\' + dec, 'g');
          cleaned = cleaned.replace(decReg, '.');
        }
      } else {
        cleaned = cleaned.replace(/,/g, '.');
      }
      var parsed = parseFloat(cleaned);
      return isNaN(parsed) ? 0 : parsed;
    }

    function convertMinorString(str){
      if (typeof str !== 'string') { return null; }
      var trimmed = str.trim();
      if (!trimmed) { return null; }
      var parsed = parseFloat(trimmed);
      if (isNaN(parsed)) {
        parsed = parseFloat(trimmed.replace(/[^0-9.-]/g, ''));
      }
      if (isNaN(parsed)) { return null; }
      var hasDecimal = trimmed.indexOf('.') !== -1 || trimmed.indexOf(',') !== -1;
      if (!hasDecimal) {
        var decimals = getMinorUnit();
        if (decimals > 0) {
          return parsed / Math.pow(10, decimals);
        }
      }
      return parsed;
    }

    function normalizeStoreAmount(entry){
      if (entry === null || entry === undefined) { return null; }

      var resolveWithMinor = function(amount, minor){
        if (typeof amount === 'string') {
          amount = parseFloat(amount);
        }
        if (typeof amount !== 'number' || isNaN(amount)) {
          return null;
        }
        if (typeof minor === 'string') {
          var parsedMinor = parseInt(minor, 10);
          minor = isNaN(parsedMinor) ? undefined : parsedMinor;
        }
        if (typeof minor !== 'number' || isNaN(minor)) {
          minor = getMinorUnit();
        }
        if (typeof minor !== 'number' || isNaN(minor) || minor < 0) {
          return amount;
        }
        return amount / Math.pow(10, minor);
      };

      if (typeof entry === 'number' && !isNaN(entry)) {
        return resolveWithMinor(entry, getMinorUnit());
      }

      if (typeof entry === 'string') {
        var converted = convertMinorString(entry);
        if (converted !== null && !isNaN(converted)) { return converted; }
        var parsed = parseCurrency(entry);
        if (isNaN(parsed)) { return null; }
        var decimals = getMinorUnit();
        var hasDecimal = entry.indexOf('.') !== -1 || entry.indexOf(',') !== -1;
        if (!hasDecimal && decimals > 0) {
          return parsed / Math.pow(10, decimals);
        }
        return parsed;
      }

      if (typeof entry === 'object') {
        if (entry.value !== undefined) {
          var minor = entry.currency_minor_unit;
          var resolved = resolveWithMinor(entry.value, minor);
          if (resolved !== null) { return resolved; }
        }
        if (entry.amount !== undefined) {
          var minorAmount = entry.currency_minor_unit;
          var resolvedAmount = resolveWithMinor(entry.amount, minorAmount);
          if (resolvedAmount !== null) { return resolvedAmount; }
        }
        if (entry.rendered !== undefined) {
          var renderedParsed = convertMinorString(entry.rendered);
          if (renderedParsed !== null && !isNaN(renderedParsed)) { return renderedParsed; }
          renderedParsed = parseCurrency(entry.rendered);
          if (isNaN(renderedParsed)) { return null; }
          var renderedDecimals = getMinorUnit();
          if (entry.rendered.indexOf('.') === -1 && entry.rendered.indexOf(',') === -1 && renderedDecimals > 0) {
            return renderedParsed / Math.pow(10, renderedDecimals);
          }
          return renderedParsed;
        }
        if (entry.price !== undefined) {
          var priceParsed = convertMinorString(entry.price);
          if (priceParsed !== null && !isNaN(priceParsed)) { return priceParsed; }
          priceParsed = parseCurrency(entry.price);
          if (isNaN(priceParsed)) { return null; }
          var priceDecimals = getMinorUnit();
          if (entry.price.indexOf('.') === -1 && entry.price.indexOf(',') === -1 && priceDecimals > 0) {
            return priceParsed / Math.pow(10, priceDecimals);
          }
          return priceParsed;
        }
      }

      return null;
    }

    function extractAmount(value){
      if (value === null || value === undefined) { return null; }
      var normalized = normalizeStoreAmount(value);
      if (normalized !== null && !isNaN(normalized)) {
        return normalized;
      }
      if (typeof value === 'object') {
        if (value.value !== undefined) {
          return extractAmount(value.value);
        }
        if (value.amount !== undefined) {
          return extractAmount(value.amount);
        }
      }
      return null;
    }

    function getStoreTotalAmount(){
      if (!window.wp || !wp.data || typeof wp.data.select !== 'function') {
        return null;
      }

      var selectors = [
        { store: 'wc/store/cart', method: 'getCartTotals', path: 'total_price' },
        { store: 'wc/store/cart', method: 'getTotals', path: 'total_price' },
        { store: 'wc/store', method: 'getCartTotals', path: 'total_price' },
        { store: 'wc/store/checkout', method: 'getTotals', path: 'total_price' },
        { store: 'wc/store/checkout', method: 'getCartTotals', path: 'total_price' }
      ];

      for (var i = 0; i < selectors.length; i++) {
        var cfg = selectors[i];
        var store;
        try {
          store = wp.data.select(cfg.store);
        } catch (e) {
          continue;
        }
        if (!store || typeof store[cfg.method] !== 'function') {
          continue;
        }
        var totals = store[cfg.method]();
        if (!totals) {
          continue;
        }
        var value = totals;
        if (cfg.path && value && typeof value === 'object') {
          value = value[cfg.path];
        }

        var amount = extractAmount(value);
        if (amount !== null && !isNaN(amount)) {
          return amount;
        }
      }

      return null;
    }

    function normalizeNumber(value) {
      if (value === null || value === undefined || value === '') {
        return 0;
      }
      var num = parseInt(value, 10);
      return isNaN(num) ? 0 : num;
    }

    function normalizeUrl(url) {
      if (!url) { return ''; }
      try {
        var parsed = new URL(url, window.location.origin);
        parsed.hash = '';
        parsed.search = '';
        var href = parsed.href;
        if (href.slice(-1) === '/') {
          href = href.slice(0, -1);
        }
        return href;
      } catch (e) {
        if (typeof url === 'string') {
          return url.replace(/[#?].*$/, '').replace(/\/$/, '');
        }
        return '';
      }
    }

    var cartItemsRaw = Array.isArray(window.lotzwoo.cartItems) ? window.lotzwoo.cartItems : [];
    var cartItemsByKey = {};
    var cartItemsByPermalink = {};

    cartItemsRaw.forEach(function(item){
      if (!item || typeof item !== 'object') { return; }
      var entry = {
        key: item.key || item.cart_item_key || '',
        product_id: normalizeNumber(item.product_id || item.productId || item.id),
        variation_id: normalizeNumber(item.variation_id || item.variationId || (item.variation && (item.variation.id || item.variation.variation_id))),
        permalink: normalizeUrl(item.permalink || ''),
        is_estimated: !!item.is_estimated
      };
      if (entry.key) {
        cartItemsByKey[entry.key] = entry;
      }
      if (entry.permalink) {
        cartItemsByPermalink[entry.permalink] = entry;
      }
    });

    function lookupItem(row, index){
      if (!row) { return null; }

      var keyAttr = row.getAttribute('data-cart-item-key');
      if (keyAttr && cartItemsByKey[keyAttr]) {
        return cartItemsByKey[keyAttr];
      }

      var link = row.querySelector('.wc-block-components-product-name');
      if (link) {
        var href = normalizeUrl(link.getAttribute('href') || '');
        if (href && cartItemsByPermalink[href]) {
          return cartItemsByPermalink[href];
        }
      }

      return cartItemsRaw[index] || null;
    }

    function annotateRows(){
      var rows = container.querySelectorAll(rowSelectors.join(','));
      if (!rows.length) { return; }

      rows.forEach(function(row, index){
        var item = lookupItem(row, index);
        var key = '';
        var productId = 0;
        var variationId = 0;
        var isEstimated = false;

        if (item) {
          key = item.key || '';
          productId = normalizeNumber(item.product_id);
          variationId = normalizeNumber(item.variation_id);
          isEstimated = !!item.is_estimated;
        }

        if (key) {
          row.setAttribute('data-cart-item-key', key);
        } else {
          row.removeAttribute('data-cart-item-key');
        }

        if (productId) {
          row.setAttribute('data-product-id', productId);
        } else {
          row.removeAttribute('data-product-id');
        }

        if (variationId) {
          row.setAttribute('data-variation-id', variationId);
        } else {
          row.removeAttribute('data-variation-id');
        }

        var priceNodes = row.querySelectorAll(allPriceSelectors.join(','));
        priceNodes.forEach(function(node){
          if (key) {
            node.setAttribute('data-cart-item-key', key);
          } else {
            node.removeAttribute('data-cart-item-key');
          }
          if (productId) {
            node.setAttribute('data-product-id', productId);
          } else {
            node.removeAttribute('data-product-id');
          }
          if (variationId) {
            node.setAttribute('data-variation-id', variationId);
          } else {
            node.removeAttribute('data-variation-id');
          }
        });

        if (!isEstimated && key && estKeys.has(key)) {
          isEstimated = true;
        }
        if (!isEstimated && productId && estProducts.has(productId)) {
          isEstimated = true;
        }
        if (!isEstimated && variationId && estVariations.has(variationId)) {
          isEstimated = true;
        }

        if (!isEstimated) {
          priceNodes.forEach(function(node){
            var text = (node.textContent || '').trim();
            if (!text) { return; }
            var matchesLine = linePrefix && text.indexOf(linePrefix) === 0;
            var matchesTotal = totalPrefix && text.indexOf(totalPrefix) === 0;
            if (matchesLine || matchesTotal) {
              isEstimated = true;
              node.setAttribute('data-lotzwoo-estimated', 'yes');
            }
          });
        }

        if (!isEstimated && row.querySelector('.lotzwoo-price, [data-lotzwoo-estimated="yes"]')) {
          isEstimated = true;
        }

        if (isEstimated) {
          row.setAttribute('data-lotzwoo-estimated', 'yes');
          priceNodes.forEach(function(node){
            node.setAttribute('data-lotzwoo-estimated', 'yes');
          });
        } else {
          row.removeAttribute('data-lotzwoo-estimated');
          priceNodes.forEach(function(node){
            node.removeAttribute('data-lotzwoo-estimated');
          });
        }
      });
    }

    function matchesIdentifiers(node, checker){
      if (!node || node.nodeType !== 1) { return false; }
      var current = node;
      while (current && current !== container && current.nodeType === 1) {
        if (checker(current)) {
          return true;
        }
        current = current.parentElement;
      }
      return false;
    }

    function shouldPrefix(node){
      return matchesIdentifiers(node, function(el){
        if (el.getAttribute('data-lotzwoo-estimated') === 'yes') { return true; }
        var key = el.getAttribute('data-cart-item-key');
        if (key && estKeys.has(key)) { return true; }
        var pid = normalizeNumber(el.getAttribute('data-product-id'));
        if (pid && estProducts.has(pid)) { return true; }
        var vid = normalizeNumber(el.getAttribute('data-variation-id'));
        if (vid && estVariations.has(vid)) { return true; }
        return false;
      });
    }

    function isBufferNode(node){
      if (!bufferId) { return false; }
      return matchesIdentifiers(node, function(el){
        var pid = normalizeNumber(el.getAttribute('data-product-id'));
        if (pid && pid === bufferId) { return true; }
        var vid = normalizeNumber(el.getAttribute('data-variation-id'));
        if (vid && vid === bufferId) { return true; }
        return false;
      });
    }

    function prefixNode(node, prefixValue){
      try {
        if (!node || typeof node.textContent !== 'string') { return; }
        var effectivePrefix = (prefixValue || '').trim();
        if (!effectivePrefix) { return; }
        var current = node.textContent;
        if (!current) { return; }
        if (current.trim().indexOf(effectivePrefix) === 0) { return; }
        node.textContent = prefixText(current, effectivePrefix);
      } catch (e) {}
    }

    function applyToNodeList(nodeList){
      nodeList.forEach(function(node){
        if (shouldPrefix(node)) {
          prefixNode(node, linePrefix);
        }
      });
    }

    function hideBufferRemoveButtons(nodeList){
      nodeList.forEach(function(btn){
        if (isBufferNode(btn)) {
          btn.style.display = 'none';
          btn.setAttribute('aria-hidden', 'true');
          btn.setAttribute('disabled', 'disabled');
        }
      });
    }

    var updatingRangeNote = false;

    function insertRangeNote(){
      if (updatingRangeNote) { return; }
      updatingRangeNote = true;

      var existingNotes = Array.prototype.slice.call(container.querySelectorAll('.lotzwoo-range-note'));

      if (!rangeEnabled || !window.lotzwoo.hasEstimated || !isCheckout || (!bufferAmount && !predefinedRange)) {
        existingNotes.forEach(function(node){
          if (node && node.parentElement) {
            node.parentElement.removeChild(node);
          }
        });
        updatingRangeNote = false;
        return;
      }

      function findOrderTotalNode() {
        var rows = container.querySelectorAll('.wc-block-components-totals-item');
        var labelCandidates = ['gesamtsumme', 'bestellsumme', 'order total', 'total', 'grand total', 'total amount'];
        var matchedRow = null;

        rows.forEach(function(row){
          if (matchedRow) { return; }
          var labelEl = row.querySelector('.wc-block-components-totals-item__label');
          var valueEl = row.querySelector('.wc-block-components-totals-item__value, .wc-block-components-formatted-money-amount');
          if (!valueEl) { return; }

          if (row.classList.contains('wc-block-components-totals-item--order-total')) {
            matchedRow = valueEl;
            return;
          }

          if (labelEl) {
            var labelText = (labelEl.textContent || '').replace(/[:\s]+$/, '').trim().toLowerCase();
            if (labelCandidates.indexOf(labelText) !== -1) {
              matchedRow = valueEl;
              return;
            }
          }
        });

        if (!matchedRow) {
          var footerValue = container.querySelector('.wc-block-components-totals-footer-item:last-of-type .wc-block-components-totals-item__value');
          if (footerValue) {
            matchedRow = footerValue;
          }
        }

        if (!matchedRow) {
          matchedRow = container.querySelector('.wc-block-components-totals-item__value:last-of-type, .wc-block-components-formatted-money-amount:last-of-type');
        }

        return matchedRow;
      }

      var totalNode = findOrderTotalNode();
      if (!totalNode) {
        updatingRangeNote = false;
        return;
      }

      var existingNote = null;
      existingNotes.forEach(function(node){
        if (!node || !node.parentElement) {
          return;
        }
        if (!existingNote && totalNode.contains(node)) {
          existingNote = node;
          return;
        }
        node.parentElement.removeChild(node);
      });

      var totalValue = getStoreTotalAmount();
      if (totalValue === null || isNaN(totalValue)) {
        totalValue = parseCurrency(totalNode.textContent || '');
      }
      if (!isFinite(totalValue)) {
        updatingRangeNote = false;
        return;
      }

      var noteHtml = predefinedRange;
      if (!noteHtml) {
        var minAmount = Math.max(0, totalValue - bufferAmount);        noteHtml = 'min. ' + formatCurrency(minAmount);
      }

      var note = existingNote;

      if (!note) {
        note = document.createElement('small');
        note.className = 'lotzwoo-range-note';
        note.style.display = 'block';
        note.style.fontSize = '0.6em';
        note.style.opacity = '0.65';
        note.style.marginTop = '4px';
        note.style.lineHeight = '1.1';
        note.style.textAlign = 'right';
      }

      var currentRange = note.getAttribute('data-lotzwoo-range');
      if (currentRange === noteHtml && totalNode.contains(note)) {
        updatingRangeNote = false;
        return;
      }

      note.setAttribute('data-lotzwoo-range', noteHtml);
      note.innerHTML = noteHtml;
      if (!totalNode.contains(note)) {
        totalNode.appendChild(note);
      }
      updatingRangeNote = false;
    }

    function applyOnce(){
      annotateRows();

      try {
        var lineNodes = container.querySelectorAll(lineSelectors.join(','));
        applyToNodeList(Array.prototype.slice.call(lineNodes));

        if (window.lotzwoo.hasEstimated) {
          var totalNodes = container.querySelectorAll(totalSelectors.join(','));
          Array.prototype.slice.call(totalNodes).forEach(function(node){
            if (shouldPrefix(node)) {
              prefixNode(node, totalPrefix);
              return;
            }

            var itemRow = node.closest('.wc-block-components-totals-item');
            if (!itemRow) { return; }

            var labelEl = itemRow.querySelector('.wc-block-components-totals-item__label');
            var label = (labelEl ? labelEl.textContent : '').trim().toLowerCase();
            var isOrderTotal = itemRow.classList.contains('wc-block-components-totals-item--order-total');
            var orderLabels = ['gesamtsumme', 'bestellsumme', 'order total', 'total', 'grand total', 'total amount'];

            if (isOrderTotal || orderLabels.indexOf(label) !== -1) {
              prefixNode(node, totalPrefix);
            }
          });
        }

        if (bufferId) {
          var removeSelectors = [
            '.wc-block-cart-item__remove-link',
            'button[data-cart-item-id]',
            'button[data-cart-item-key]',
            'button[aria-label*="Remove"]'
          ];
          var removeNodes = container.querySelectorAll(removeSelectors.join(','));
          hideBufferRemoveButtons(Array.prototype.slice.call(removeNodes));
        }
      } catch (e) {}

      insertRangeNote();
    }

    applyOnce();

    var observer;

    function schedule(){
      clearTimeout(schedule._t);
      schedule._t = setTimeout(function(){
        applyOnce();
      }, 100);
    }

    if (window.wp && wp.data && typeof wp.data.subscribe === 'function') {
      wp.data.subscribe(schedule);
    }

    observer = new MutationObserver(schedule);
    observer.observe(container, { childList: true, subtree: true });

    window.addEventListener('wc-blocks_added_to_cart', schedule);
    window.addEventListener('updated_wc_div', schedule);
  } catch (e) { /* no-op */ }
})();
JS;
        wp_add_inline_script($handle, $inline);
    }
}


