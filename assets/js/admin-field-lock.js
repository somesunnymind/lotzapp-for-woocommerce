(function (window, document) {
    'use strict';

    var data = window.lotzwooFieldLockData || {};
    var selectors = Array.isArray(data.selectors) ? data.selectors.filter(Boolean) : [];
    if (!selectors.length) {
        return;
    }

    var iconUrl = data.iconUrl || '';
    var tooltip = data.tooltip || '';
    var LOCKED_ATTR = 'data-lotzapp-locked';

    function safeQuerySelectorAll(selector, context) {
        try {
            return (context || document).querySelectorAll(selector);
        } catch (error) {
            return [];
        }
    }

    function findLabelFor(field) {
        if (!field.id) {
            return null;
        }
        var selector = 'label[for="' + (window.CSS && CSS.escape ? CSS.escape(field.id) : field.id.replace(/"/g, '\\"')) + '"]';
        try {
            return document.querySelector(selector);
        } catch (error) {
            return null;
        }
    }

    function createIconElement() {
        var span = document.createElement('span');
        span.className = 'lotzapp-lock-icon woocommerce-help-tip';
        if (tooltip) {
            span.setAttribute('data-tip', tooltip);
            span.setAttribute('aria-label', tooltip);
        }
        if (iconUrl) {
            var img = document.createElement('img');
            img.src = iconUrl;
            img.alt = tooltip || 'LotzApp';
            span.appendChild(img);
        } else {
            span.textContent = 'i';
        }
        return span;
    }

    function insertIcon(field) {
        var label = findLabelFor(field);
        var target = label || field;
        if (!target || !target.parentNode) {
            return;
        }
        if (target.getAttribute('data-lotzapp-icon') === 'yes') {
            return;
        }
        var icon = createIconElement();
        icon.setAttribute('data-lotzapp-for', field.id || '');
        if (target.nextSibling) {
            target.parentNode.insertBefore(icon, target.nextSibling);
        } else {
            target.parentNode.appendChild(icon);
        }
        target.setAttribute('data-lotzapp-icon', 'yes');
        if (window.jQuery) {
            window.jQuery(document.body).trigger('init_tooltips');
        }
    }

    function lockField(field) {
        if (!field || field.getAttribute(LOCKED_ATTR) === 'yes') {
            return;
        }

        var tag = field.tagName ? field.tagName.toLowerCase() : '';
        var type = (field.getAttribute('type') || '').toLowerCase();

        if (tag === 'select' || type === 'checkbox' || type === 'radio' || tag === 'button') {
            field.disabled = true;
            field.setAttribute('aria-disabled', 'true');
        } else {
            field.readOnly = true;
            field.setAttribute('aria-readonly', 'true');
        }

        field.classList.add('lotzapp-locked-field');
        field.setAttribute(LOCKED_ATTR, 'yes');
        insertIcon(field);
    }

    function applyLocks(context) {
        selectors.forEach(function (selector) {
            var nodes = safeQuerySelectorAll(selector, context);
            Array.prototype.forEach.call(nodes, lockField);
        });
    }

    function scheduleLocks() {
        applyLocks(document);
    }

    function debounce(fn, delay) {
        var timer;
        return function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(fn, delay);
        };
    }

    var debouncedSchedule = debounce(scheduleLocks, 100);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleLocks);
    } else {
        scheduleLocks();
    }

    var observer = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
            if (mutations[i].addedNodes && mutations[i].addedNodes.length) {
                debouncedSchedule();
                break;
            }
        }
    });
    if (document.body) {
        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (window.jQuery) {
        window.jQuery(document).on('woocommerce_variations_loaded woocommerce_variations_added wc_backbone_modal_loaded', debouncedSchedule);
    }
})(window, document);


