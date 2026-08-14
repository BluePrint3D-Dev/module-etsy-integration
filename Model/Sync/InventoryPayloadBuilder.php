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
namespace BluePrint3D\EtsyIntegration\Model\Sync;

use Magento\Catalog\Model\Product;

/**
 * Class InventoryPayloadBuilder
 * Builds the Etsy Listing Inventory payload (variations + per-combination pricing)
 * for dropdown/radio custom options that carry a price adjustment.
 */
class InventoryPayloadBuilder
{
    /**
     * Etsy's fixed property_id slots for custom (non-taxonomy) variation properties.
     */
    private const CUSTOM_PROPERTY_IDS = [513, 514];

    /**
     * Assemble the updateListingInventory payload for up to 2 priced dropdowns.
     *
     * @param Product $product
     * @param array $pricedDropdowns Each entry: ['title' => string, 'values' => [['label' => string, 'price' => float], ...]]
     * @param float $basePrice
     * @param int $readinessStateId
     * @param int $qty
     * @return array|null Null when there are no priced dropdowns to sync.
     */
    public function build(
        Product $product,
        array $pricedDropdowns,
        float $basePrice,
        int $readinessStateId,
        int $qty
    ): ?array {
        if (empty($pricedDropdowns)) {
            return null;
        }

        $properties = [];
        foreach (array_slice($pricedDropdowns, 0, count(self::CUSTOM_PROPERTY_IDS)) as $index => $dropdown) {
            $properties[] = [
                'property_id' => self::CUSTOM_PROPERTY_IDS[$index],
                'property_name' => $dropdown['title'],
                'values' => $dropdown['values']
            ];
        }

        $sku = (string)$product->getSku();
        $combinations = $this->buildCombinations($properties);

        $products = [];
        foreach ($combinations as $combination) {
            $priceDelta = 0.0;
            $propertyValues = [];

            foreach ($combination as $entry) {
                $priceDelta += $entry['value']['price'];
                $propertyValues[] = [
                    'property_id' => $entry['property_id'],
                    'property_name' => $entry['property_name'],
                    'value_ids' => [],
                    'values' => [$entry['value']['label']],
                    'scale_id' => null
                ];
            }

            $products[] = [
                'sku' => $sku,
                'property_values' => $propertyValues,
                'offerings' => [
                    [
                        'price' => round($basePrice + $priceDelta, 2),
                        'quantity' => $qty,
                        'is_enabled' => true,
                        'readiness_state_id' => $readinessStateId
                    ]
                ]
            ];
        }

        $propertyIds = array_column($properties, 'property_id');

        return [
            'products' => $products,
            // Price varies with every priced dropdown by definition; quantity/SKU don't
            // (Magento custom options carry no per-value stock or SKU modifier).
            'price_on_property' => $propertyIds,
            'quantity_on_property' => [],
            'sku_on_property' => []
        ];
    }

    /**
     * Build the Cartesian product of value combinations across up to 2 properties.
     *
     * @param array $properties Each entry: ['property_id' => int, 'property_name' => string, 'values' => array]
     * @return array List of combinations, each a list of ['property_id', 'property_name', 'value'] entries.
     */
    private function buildCombinations(array $properties): array
    {
        $combinations = [[]];

        foreach ($properties as $property) {
            $next = [];
            foreach ($combinations as $existing) {
                foreach ($property['values'] as $value) {
                    $next[] = array_merge($existing, [[
                        'property_id' => $property['property_id'],
                        'property_name' => $property['property_name'],
                        'value' => $value
                    ]]);
                }
            }
            $combinations = $next;
        }

        return $combinations;
    }
}
