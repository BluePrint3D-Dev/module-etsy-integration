<?php
namespace BluePrint3D\EtsyIntegration\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class SyncButton extends Field
{
    protected function _getElementHtml(AbstractElement $element)
    {
        $url = $this->getUrl('blueprint3d_etsy/taxonomy/sync');

        $button = $this->getLayout()->createBlock(
            \Magento\Backend\Block\Widget\Button::class
        )->setData([
            'id' => 'sync_etsy_taxonomy_button',
            'label' => __('Sync Categories from Etsy'),
            'onclick' => "setLocation('$url')",
            'class' => 'action-secondary'
        ]);

        return $button->toHtml();
    }
}