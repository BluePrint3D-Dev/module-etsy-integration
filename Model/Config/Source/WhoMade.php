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
 * Class WhoMade
 * Source model for "Who made it?" Etsy attribute option provider.
 */
class WhoMade extends AbstractSource
{
    /**
     * Retrieve all options for "Who made it" attribute
     *
     * @return array
     */
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
