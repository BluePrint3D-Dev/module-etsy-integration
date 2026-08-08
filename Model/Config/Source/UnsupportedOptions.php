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

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Class UnsupportedOptions
 * Source model providing options for handling unsupported product configurations during sync.
 */
class UnsupportedOptions implements OptionSourceInterface
{
    /**
     * Retrieve options array for handling unsupported options
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'strict', 'label' => __('Strict (Abort Sync)')],
            ['value' => 'lenient', 'label' => __('Lenient (Strip Unsupported & Continue)')],
        ];
    }
}
