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

    var field = event.target;
    var newValue = field.value.trim();
    var originalValue = (field.dataset.etsyOriginalValue || '').trim();

    if (newValue === originalValue) {
        return;
    }

    var message = newValue === ''
        ? 'Clearing the Etsy Listing ID means the next sync will CREATE A NEW listing on Etsy '
            + 'instead of updating listing ' + originalValue + '. If that listing still represents '
            + 'this product, this will likely create a duplicate.\n\nContinue?'
        : 'Linking this product to Etsy Listing ID "' + newValue + '" means the next sync will '
            + 'OVERWRITE that listing\'s title, description, price, images, personalizations and '
            + 'variations with this product\'s current Magento data.\n\n'
            + 'Make sure this product is fully up to date in Magento first.\n\nContinue?';

    if (!window.confirm(message)) {
        field.value = originalValue;
    }
});
