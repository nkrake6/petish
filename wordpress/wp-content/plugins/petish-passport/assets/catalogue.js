/* Keep native details controls open on larger screens and compact on phones. */
(function () {
    'use strict';

    function initialiseCatalogueFilters() {
        var panels = document.querySelectorAll('.pc-shop-sidebar > .pc-sidebar-panel');
        if (!panels.length || !window.matchMedia) {
            return;
        }

        var phone = window.matchMedia('(max-width: 767px)');
        function applyBreakpointDefault() {
            panels.forEach(function (panel) {
                panel.open = !phone.matches;
            });
        }

        // Set a default only on arrival or when crossing the phone breakpoint.
        // Native summary clicks and keyboard toggles retain the user's choice.
        applyBreakpointDefault();
        if (phone.addEventListener) {
            phone.addEventListener('change', applyBreakpointDefault);
        } else {
            phone.addListener(applyBreakpointDefault);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialiseCatalogueFilters, { once: true });
    } else {
        initialiseCatalogueFilters();
    }
})();
