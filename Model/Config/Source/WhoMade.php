<?php
namespace BluePrint3D\EtsyIntegration\Model\Config\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

class WhoMade extends AbstractSource
{
    public function getAllOptions()
    {
        if ($this->_options === null) {
            $this->_options = [
                ['value' => 'i_did', 'label' => __('I did')],
                ['value' => 'collective', 'label' => __('A member of my shop')],
                ['value' => 'someone_else', 'label' => __('Another company or person')]
            ];
        }
        return $this->_options;
    }
}