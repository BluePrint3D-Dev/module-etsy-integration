/**
 * Copyright (c) 2026 BluePrint3D Ltd. All rights reserved.
 *
 * Commercial Software License (EULA)
 * This software is licensed, not sold. Unauthorized reproduction, distribution,
 * reverse engineering, or sublicensing of this source code, modified or
 * unmodified, without an active license agreement from BluePrint3D Ltd
 * is strictly prohibited.
 *
 * Warns before a manual edit to the Etsy Listing ID field takes effect. The next sync
 * always pushes the product's full current Magento state to whatever listing ID is
 * stored here - editing it in either direction changes what gets overwritten:
 *   - Pointing it at a different/existing listing overwrites that listing's content.
 *   - Clearing it makes the next sync create a brand new listing instead.
 *
 * The field's loaded value is set by Magento's knockout form binding (a JS property
 * assignment), not the HTML value attribute, so element.defaultValue never reflects it -
 * capture the real value on focus instead, otherwise cancelling reverts to blank.
 *
 * Magento's confirm dialog is asynchronous, so by the time the admin answers, the field
 * (and the form's knockout value) already hold the new text. Cancelling puts the old value
 * back and fires a change event so the form's value follows the field.
 *
 * This file is loaded as a plain script, so RequireJS is called lazily when a change happens.
 */
document.addEventListener('focusin', function (event) {
    if (event.target.matches('input[name="product[etsy_listing_id]"]')) {
        event.target.dataset.etsyOriginalValue = event.target.value;
    }
});

document.addEventListener('change', function (event) {
    if (!event.target.matches('input[name="product[etsy_listing_id]"]')) {
        return;
    }

    var field = event.target,
        newValue = field.value.trim(),
        originalValue = (field.dataset.etsyOriginalValue || '').trim();

    if (newValue === originalValue) {
        return;
    }

    require(['underscore', 'Magento_Ui/js/modal/confirm', 'mage/translate'], function (_, confirm, $t) {
        var content;

        // The dialog renders HTML, so anything the admin typed must be escaped first.
        if (newValue === '') {
            content = $t('Clearing the Etsy Listing ID means the next sync will <strong>create a new listing</strong> '
                + 'on Etsy instead of updating listing %1. If that listing still represents this product, '
                + 'this will likely create a duplicate.').replace('%1', function () {
                return _.escape(originalValue);
            });
        } else {
            content = $t('Linking this product to Etsy Listing ID %1 means the next sync will '
                + '<strong>overwrite</strong> that listing\'s title, description, price, images, personalizations '
                + 'and variations with this product\'s current Magento data.<br><br>'
                + 'Make sure this product is fully up to date in Magento first.')
                .replace('%1', function () {
                    return '<strong>' + _.escape(newValue) + '</strong>';
                });
        }

        confirm({
            title: newValue === '' ? $t('Clear the Etsy Listing ID?') : $t('Link to this Etsy listing?'),
            content: content,
            actions: {
                /**
                 * Keep the new listing ID.
                 */
                confirm: function () {
                    field.dataset.etsyOriginalValue = newValue;
                },

                /**
                 * Runs however the dialog is dismissed (Cancel, the X, Esc or a click outside it).
                 */
                cancel: function () {
                    field.value = originalValue;
                    field.dispatchEvent(new Event('change', {bubbles: true}));
                }
            }
        });
    });
});
