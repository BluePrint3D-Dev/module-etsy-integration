<?php
namespace BluePrint3D\EtsyIntegration\Model\Config\Source;

class WhoMade implements \Magento\Framework\Data\OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'i_did', 'label' => __('I did')],
            ['value' => 'collective', 'label' => __('A member of my shop')],
            ['value' => 'someone_else', 'label' => __('Another company or person')]
        ];
    }
}