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
namespace BluePrint3D\EtsyIntegration\Model\Config\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

/**
 * Class WhenMade
 * Source model providing options for "When was it made?" Etsy product attribute.
 */
class WhenMade extends AbstractSource
{
    /**
     * Retrieve all options for "When was it made" attribute
     *
     * @return array
     */
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
