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

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Class MakeEtsyProductAttributesOptional
 * The Etsy product attributes were created required (Magento's default), so any product saved
 * without them - through the REST API, an import or another extension - was rejected. They only
 * matter for products sent to Etsy, and the sync falls back to the store's Global Listing Defaults.
 */
class MakeEtsyProductAttributesOptional implements DataPatchInterface
{
    private const ATTRIBUTES = [
        'etsy_sync_enabled',
        'etsy_auto_renew',
        'etsy_who_made',
        'etsy_when_made',
        'etsy_is_supply',
    ];

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var EavSetupFactory
     */
    private $eavSetupFactory;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EavSetupFactory $eavSetupFactory
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    /**
     * Apply setup patch
     *
     * @return $this
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        foreach (self::ATTRIBUTES as $code) {
            if ($eavSetup->getAttributeId(Product::ENTITY, $code)) {
                $eavSetup->updateAttribute(Product::ENTITY, $code, 'is_required', 0);
            }
        }
        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        return [AddEtsyProductAttributes::class, AddEtsyAutoRenewAttribute::class];
    }

    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        return [];
    }
}
