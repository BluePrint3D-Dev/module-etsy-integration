<?php
/**
 * Copyright (c) 2026 BluePrint3D Ltd. All rights reserved.
 *
 * Commercial Software License (EULA)
 * This software is licensed, not sold. Unauthorized reproduction, distribution,
 * reverse engineering, or sublicensing of this source code, modified or
 * unmodified, without an active license agreement from BluePrint3D Ltd
 * is strictly prohibited.
 *
 * @author    BluePrint3D Ltd <support@blueprint3d.dev>
 * @copyright 2026 BluePrint3D Ltd (Company No. 13473806)
 * @license   Commercial Proprietary EULA (See LICENSE.txt)
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
