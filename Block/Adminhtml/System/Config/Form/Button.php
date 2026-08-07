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
namespace BluePrint3D\EtsyIntegration\Block\Adminhtml\System\Config\Form;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class Button extends Field
{
    /**
     * Remove scope label
     */
    protected function _renderScopeLabel(AbstractElement $element)
    {
        return '';
    }

    /**
     * Return custom HTML for the button
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        // This will route to our custom controller we are about to build
        $url = $this->getUrl('blueprint3d_etsy/auth/index');

        return sprintf(
            '<button type="button" class="action-default scalable primary" onclick="window.location.href=\'%s\'">
                <span>%s</span>
            </button>',
            $url,
            __('Connect to Etsy')
        );
    }
}