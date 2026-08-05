<?php
namespace BluePrint3D\EtsyIntegration\Model\Config\Source;

class WhenMade implements \Magento\Framework\Data\OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'made_to_order', 'label' => __('Made to order')],
            ['value' => '2020_2026', 'label' => __('2020 - 2026')],
            ['value' => '2010_2019', 'label' => __('2010 - 2019')]
        ];
    }
}