/* Keep a single native origin selected; expose the control whenever it is ambiguous. */
(function ($) {
    'use strict';
    const config = window.petishProductOrigin;
    if (!config || !config.productId || !config.fieldName || !config.value) return;

    function enhance(form) {
        const $form = $(form);
        if (String($form.data('product_id')) !== String(config.productId) || form.dataset.petishOriginReady) return;
        const fields = Array.from(form.querySelectorAll('.variations select'))
            .filter(select => select.name === config.fieldName);
        if (fields.length !== 1) return;
        const select = fields[0];
        const row = select.closest('tr');
        if (!row || row.hidden || window.getComputedStyle(row).display === 'none') return;
        const originalDisplay = row.style.display;
        const $select = $(select);
        const moved = [];
        let pending = false;
        let changing = false;
        form.dataset.petishOriginReady = 'true';

        function restoreRow() {
            row.hidden = false;
            row.style.display = originalDisplay;
            delete row.dataset.petishOriginHidden;
            moved.forEach(position => {
                const anchor = position.next && position.next.parentNode === position.parent ? position.next : null;
                position.parent.insertBefore(position.node, anchor);
            });
            moved.length = 0;
        }

        function onlyVerifiedOrigin() {
            const options = Array.from(select.options).filter(option => option.value !== '');
            if (options.length !== 1 || options[0].value !== config.value || options[0].disabled || select.disabled) return false;
            const originalOptions = $select.data('attribute_options');
            if (Array.isArray(originalOptions) && originalOptions.some(option => option.value && option.value !== config.value)) return false;
            const variations = $form.data('product_variations');
            if (Array.isArray(variations) && (!variations.length || variations.some(variation =>
                !variation.attributes || variation.attributes[config.fieldName] !== config.value))) return false;
            return true;
        }

        function moveResetControls() {
            const rows = Array.from(form.querySelectorAll('.variations tr')).filter(candidate =>
                candidate !== row && candidate.querySelector('select') && !candidate.hidden && window.getComputedStyle(candidate).display !== 'none');
            const target = rows.length ? rows[rows.length - 1].querySelector('td.value') : null;
            if (!target) return false;
            const controls = Array.from(row.querySelectorAll('.reset_variations'));
            form.querySelectorAll('.reset_variations_alert').forEach(alert => {
                if (!target.contains(alert)) controls.push(alert);
            });
            controls.forEach(node => {
                if (moved.some(position => position.node === node)) return;
                moved.push({node, parent: node.parentNode, next: node.nextSibling});
                target.appendChild(node);
            });
            return true;
        }

        function refresh() {
            pending = false;
            if (changing) return;
            if (!onlyVerifiedOrigin()) {
                restoreRow();
                return;
            }
            if (select.value !== config.value) {
                const announcements = Array.from(form.querySelectorAll('.reset_variations_alert'))
                    .map(node => ({node, text: node.textContent}));
                changing = true;
                $select.val(config.value).trigger('change');
                changing = false;
                // A native Clear announcement must survive this internal re-selection.
                announcements.forEach(announcement => {
                    if (announcement.text) announcement.node.textContent = announcement.text;
                });
            }
            if (select.value !== config.value || !onlyVerifiedOrigin() || !moveResetControls()) {
                restoreRow();
                return;
            }
            // Native matching still reads this selected field; only its redundant row is hidden.
            row.hidden = true;
            row.style.display = 'none';
            row.dataset.petishOriginHidden = 'true';
        }

        function schedule() {
            if (pending || changing) return;
            pending = true;
            window.setTimeout(refresh, 0);
        }
        $form.on('wc_variation_form.petish-origin woocommerce_update_variation_values.petish-origin reset_data.petish-origin found_variation.petish-origin', schedule);
        $form.on('change.petish-origin', '.variations select', schedule);
        $form.on('click.petish-origin', '.reset_variations', schedule);
        $form.on('reset_focus.petish-origin', function () {
            if (!row.hidden) return;
            const visible = Array.from(form.querySelectorAll('.variations select')).find(field =>
                field !== select && !field.disabled && window.getComputedStyle(field.closest('tr')).display !== 'none');
            if (visible) visible.focus();
        });
        form.addEventListener('reset', schedule);
        refresh();
    }

    $(function () {
        $('.variations_form').each(function () { enhance(this); });
        $(document).on('wc_variation_form.petish-origin', '.variations_form', function () { enhance(this); });
    });
})(jQuery);
