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
namespace BluePrint3D\EtsyIntegration\Setup\Patch\Data;

use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;

class AddEtsyProductAttributes implements DataPatchInterface
{
    private $moduleDataSetup;
    private $eavSetupFactory;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $groupName = 'BluePrint3D Etsy Integration';

        // 1. Sync to Etsy (Toggle)
        $eavSetup->addAttribute(Product::ENTITY, 'etsy_sync_enabled', [
            'type' => 'int',
            'label' => 'Sync to Etsy',
            'input' => 'boolean',
            'source' => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::class,
            'default' => '0',
            'global' => ScopedAttributeInterface::SCOPE_STORE,
            'group' => $groupName,
            'sort_order' => 10,
        ]);

        // 2. Who Made It?
        $eavSetup->addAttribute(Product::ENTITY, 'etsy_who_made', [
            'type' => 'varchar',
            'label' => 'Who made it?',
            'input' => 'select',
            'source' => \BluePrint3D\EtsyIntegration\Model\Config\Source\WhoMade::class,
            'global' => ScopedAttributeInterface::SCOPE_STORE,
            'group' => $groupName,
            'sort_order' => 20,
        ]);

        // 3. When Was It Made?
        $eavSetup->addAttribute(Product::ENTITY, 'etsy_when_made', [
            'type' => 'varchar',
            'label' => 'When was it made?',
            'input' => 'select',
            'source' => \BluePrint3D\EtsyIntegration\Model\Config\Source\WhenMade::class,
            'global' => ScopedAttributeInterface::SCOPE_STORE,
            'group' => $groupName,
            'sort_order' => 30,
        ]);

        // 4. Is it a supply?
        $eavSetup->addAttribute(Product::ENTITY, 'etsy_is_supply', [
            'type' => 'int',
            'label' => 'Is this a supply or tool to make things?',
            'input' => 'boolean',
            'source' => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::class,
            'default' => '0',
            'global' => ScopedAttributeInterface::SCOPE_STORE,
            'group' => $groupName,
            'sort_order' => 40,
        ]);

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    public static function getDependencies() { return []; }
    public function getAliases() { return []; }
}