<?php
namespace BluePrint3D\EtsyIntegration\Model\Config\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

class WhenMade extends AbstractSource
{
    public function getAllOptions()
    {
        if ($this->_options === null) {
            $this->_options = [
                ['value' => 'made_to_order', 'label' => __('Made to order')],
                ['value' => '2020_2026', 'label' => __('2020 - 2026')],
                ['value' => '2010_2019', 'label' => __('2010 - 2019')]
            ];
        }
        return $this->_options;
    }
}