/* Hide a redundant origin through WooCommerce's public presentation filter. */
(function () {
    'use strict';
    const checkout = window.wc && window.wc.blocksCheckout;
    if (!checkout || typeof checkout.registerCheckoutFilters !== 'function') return;

    checkout.registerCheckoutFilters('petish-origin', {
        cartItemClass: function (current, extensions, args) {
            if (!args || !['cart', 'summary'].includes(args.context)
                || !extensions || !extensions.petish_origin
                || extensions.petish_origin.hide_shipping_origin !== true
                || typeof current !== 'string') return current;
            const classes = current.split(/\s+/).filter(Boolean);
            if (!classes.includes('petish-single-origin')) classes.push('petish-single-origin');
            return classes.join(' ');
        }
    });
}());
