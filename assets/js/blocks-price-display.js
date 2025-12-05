(function () {
    const ns = 'lotzwoo-price-display';

    const extract = (extensions) => {
        if (!extensions || typeof extensions !== 'object') {
            return {};
        }
        return extensions.lotzwoo || {};
    };

    const mapPrice = (value, extensions) => {
        const ext = extract(extensions);
        if (typeof ext.item_price_html === 'string' && ext.item_price_html !== '') {
            return ext.item_price_html;
        }
        return value;
    };

    const mapSubtotal = (value, extensions) => {
        const ext = extract(extensions);
        if (typeof ext.item_subtotal_html === 'string' && ext.item_subtotal_html !== '') {
            return ext.item_subtotal_html;
        }
        return value;
    };

    const mapCartSubtotal = (value, extensions) => {
        const ext = extract(extensions);
        if (typeof ext.cart_subtotal_html === 'string' && ext.cart_subtotal_html !== '') {
            return ext.cart_subtotal_html;
        }
        return value;
    };

    const mapCartTotal = (value, extensions) => {
        const ext = extract(extensions);
        if (typeof ext.cart_total_html === 'string' && ext.cart_total_html !== '') {
            return ext.cart_total_html;
        }
        return value;
    };

    const handlers = {
        cartItemPrice: mapPrice,
        subtotalPriceFormat: mapSubtotal,
        cartItemClass: (value) => value,
        cartSubtotal: mapCartSubtotal,
        cartTotal: mapCartTotal,
        totalPriceFormat: mapCartTotal,
        totalValue: mapCartTotal,
    };

    const registerFilters = (api) => {
        if (typeof api !== 'function') {
            return;
        }

        api(ns, handlers);
    };

    const decodeMarkup = () => {
        try {
            const decode = (value) => {
                let decoded = value;
                // Decode twice to handle double-encoded entities.
                for (let i = 0; i < 2; i++) {
                    const textarea = document.createElement('textarea');
                    textarea.innerHTML = decoded;
                    const next = textarea.value;
                    if (next === decoded) {
                        break;
                    }
                    decoded = next;
                }
                return decoded;
            };

            const nodes = document.querySelectorAll(
                [
                    '.wc-block-components-product-price',
                    '.wc-block-components-totals-item__value',
                    '.wc-block-components-totals-footer-item .wc-block-components-formatted-money-amount',
                    '.wc-block-components-order-summary-item__price',
                    '.wc-block-components-order-summary-item__total',
                    '.wc-block-cart-item__total',
                    '.wc-block-cart-item__prices',
                ].join(',')
            );
            nodes.forEach((node) => {
                const html = node.innerHTML;
                if (!html) {
                    return;
                }
                if (
                    html.indexOf('&lt;') === -1 &&
                    html.indexOf('&gt;') === -1 &&
                    html.indexOf('&amp;lt;') === -1 &&
                    html.indexOf('&amp;gt;') === -1
                ) {
                    return;
                }
                const decoded = decode(html);
                if (decoded && decoded !== html) {
                    node.innerHTML = decoded;
                }
            });
        } catch (e) {
            // silent
        }
    };

    registerFilters(window.wc && window.wc.blocksCheckout && window.wc.blocksCheckout.registerCheckoutFilters);
    registerFilters(window.wc && window.wc.blocksCart && window.wc.blocksCart.registerCheckoutFilters);
    registerFilters(window.wc && window.wc.blocksMiniCart && window.wc.blocksMiniCart.registerCheckoutFilters);

    // Fallback for subtotal: some builds do not expose a subtotal filter, so patch the DOM using the
    // Store API data (extensions + current subtotal value).
    function patchSubtotalFromStore() {
        try {
            if (!window.wp || !wp.data || typeof wp.data.select !== 'function') {
                return;
            }

            const sources = [
                { key: 'wc/store/cart', dataMethod: 'getCartData', totalsMethod: 'getCartTotals' },
                { key: 'wc/store/checkout', dataMethod: 'getCheckoutData', totalsMethod: 'getCartTotals' },
                { key: 'wc/store/checkout', dataMethod: 'getCartData', totalsMethod: 'getTotals' },
            ];

            let ext = null;
            let totals = null;

            for (let i = 0; i < sources.length; i++) {
                const src = sources[i];
                const sel = wp.data.select(src.key);
                if (!sel) {
                    continue;
                }
                const getData = sel[src.dataMethod];
                const getTotals = sel[src.totalsMethod];
                if (typeof getData !== 'function') {
                    continue;
                }
                const data = getData.call(sel);
                if (data && data.extensions && data.extensions.lotzwoo) {
                    ext = data.extensions.lotzwoo;
                }
                if (typeof getTotals === 'function') {
                    totals = getTotals.call(sel);
                }
                if (ext) {
                    break;
                }
            }

            if (!ext || !ext.cart_subtotal_html) {
                return;
            }

            const valueNodes = document.querySelectorAll('.wc-block-components-totals-item__value');
            if (!valueNodes.length) {
                return;
            }

            // Current subtotal HTML from DOM as fallback replacement for <price/>.
            const replaceWithCurrent = (node) => {
                if (!node.dataset) {
                    return null;
                }
                // Cache the original subtotal to avoid repeated prefixing.
                if (!node.dataset.lotzwooOriginalSubtotal) {
                    node.dataset.lotzwooOriginalSubtotal = node.innerHTML;
                }
                const baseHtml = node.dataset.lotzwooOriginalSubtotal || node.innerHTML;
                return ext.cart_subtotal_html.replace('<price/>', baseHtml);
            };

            valueNodes.forEach((node) => {
                const label = node.parentElement
                    ?.querySelector('.wc-block-components-totals-item__label')
                    ?.textContent?.trim()
                    .toLowerCase();
                const isSubtotal =
                    (label && (label === 'zwischensumme' || label === 'subtotal')) ||
                    node.parentElement?.classList.contains('wc-block-components-totals-item--subtotal');

                if (!isSubtotal) {
                    return;
                }

                const newHtml = replaceWithCurrent(node);
                if (newHtml && newHtml !== node.innerHTML) {
                    node.innerHTML = newHtml;
                    node.dataset.lotzwooSubtotalApplied = 'yes';
                }
            });
        } catch (e) {
            // silent fallback
        }
    }

    // Run once and on cart updates / DOM changes.
    const rerun = () => {
        patchSubtotalFromStore();
        decodeMarkup();
    };

    rerun();

    if (window.wp && wp.data && typeof wp.data.subscribe === 'function') {
        wp.data.subscribe(rerun);
    }

    if (window.MutationObserver) {
        const observer = new MutationObserver(() => {
            rerun();
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }
})();
