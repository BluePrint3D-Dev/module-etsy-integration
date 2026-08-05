<?php
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