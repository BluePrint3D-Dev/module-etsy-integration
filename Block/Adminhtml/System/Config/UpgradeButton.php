<?php
/**
 * Copyright (c) 2026 BluePrint3D Ltd. All rights reserved.
 */
namespace BluePrint3D\EtsyIntegration\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders an 'Upgrade to Pro' button inside Magento System Configuration
 */
class UpgradeButton extends Field
{
    /**
     * Remove scope label
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _renderScopeLabel(AbstractElement $element)
    {
        return '';
    }

    /**
     * Render button HTML
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $url = 'https://www.blueprint3d.dev/modules/etsy-integration-pro';

        return sprintf(
            '<button type="button" class="action-secondary" onclick="window.open(\'%s\', \'_blank\')">' .
            '<span>⭐ %s</span>' .
            '</button>',
            $url,
            __('Upgrade to Etsy Integration Pro (Unlimited Sync)')
        );
    }
}
