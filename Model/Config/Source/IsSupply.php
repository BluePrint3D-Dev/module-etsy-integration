<?php
namespace BluePrint3D\EtsyIntegration\Model\Config\Source;

class IsSupply implements \Magento\Framework\Data\OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'false', 'label' => __('A finished product')],
            ['value' => 'true', 'label' => __('A supply or tool to make things')]
        ];
    }
}