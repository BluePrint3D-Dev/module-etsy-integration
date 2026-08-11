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
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Class PayloadBuilder
 * Constructs the array payloads required by the Etsy API.
 */
class PayloadBuilder
{
    /**
     * @var StockRegistryInterface
     */
    protected $stockRegistry;

    /**
     * @var CategoryRepositoryInterface
     */
    protected $categoryRepository;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * PayloadBuilder constructor.
     *
     * @param StockRegistryInterface $stockRegistry
     * @param CategoryRepositoryInterface $categoryRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        StockRegistryInterface $stockRegistry,
        CategoryRepositoryInterface $categoryRepository,
        LoggerInterface $logger
    ) {
        $this->stockRegistry = $stockRegistry;
        $this->categoryRepository = $categoryRepository;
        $this->logger = $logger;
    }

    /**
     * Assembles the full API payload array for Etsy listing creation/update.
     *
     * @param Product $product
     * @param int $shippingProfileId
     * @param int $readinessStateId
     * @return array
     * @throws LocalizedException
     */
    public function build(Product $product, int $shippingProfileId, int $readinessStateId): array
    {
        $stockItem = $this->stockRegistry->getStockItem($product->getId());
        $qty = max(1, (int)$stockItem->getQty());

        $taxonomyId = $this->getEtsyTaxonomyId($product);
        if (!$taxonomyId) {
            throw new LocalizedException(
                __('No Etsy Category mapped for product: %1', $product->getSku())
            );
        }

        return [
            'quantity'            => $qty,
            'title'               => substr((string)$product->getName(), 0, 140),
            'description'         => $this->formatDescription($product),
            'price'               => $this->getCalculatedPrice($product),
            'who_made'            => $product->getData('etsy_who_made'),
            'when_made'           => $product->getData('etsy_when_made'),
            'taxonomy_id'         => (int)$taxonomyId,
            'is_supply'           => (bool)$product->getData('etsy_is_supply'),
            'should_auto_renew'   => (bool)$product->getData('etsy_auto_renew'),
            'tags'                => $this->getEtsyTags($product),
            'shipping_profile_id' => $shippingProfileId,
            'readiness_state_id'  => $readinessStateId
        ];
    }

    /**
     * Calculates base product price.
     *
     * PUBLIC method so Pro plugin (ApplyPriceRulePlugin) can intercept and apply markups (+% or +£).
     *
     * @param Product $product
     * @return float
     */
    public function getCalculatedPrice(Product $product): float
    {
        $price = $product->getFinalPrice() ?: $product->getPrice();
        return (float)$price;
    }

    /**
     * Generate Etsy tags from product meta keywords or name.
     *
     * @param Product $product
     * @return array
     */
    private function getEtsyTags(Product $product): array
    {
        $tags = [];
        $metaKeywords = (string)$product->getData('meta_keyword');

        if (empty(trim($metaKeywords))) {
            $name = (string)$product->getName();
            $metaKeywords = $name . ', ' . str_replace(' ', ', ', $name);
        }

        $keywordArray = preg_split('/[,;\n]+/', $metaKeywords);

        foreach ($keywordArray as $keyword) {
            $clean = preg_replace('/[^a-zA-Z0-9\s]/', '', $keyword);
            $clean = trim((string)$clean);

            if ($clean !== '') {
                if (strlen($clean) > 20) {
                    $clean = substr($clean, 0, 20);
                }

                $clean = strtolower($clean);

                if (!in_array($clean, $tags, true)) {
                    $tags[] = $clean;
                }
            }

            if (count($tags) >= 13) {
                break;
            }
        }

        return $tags;
    }

    /**
     * Cleans Magento HTML descriptions into plain text for Etsy.
     *
     * @param Product $product
     * @return string
     */
    private function formatDescription(Product $product): string
    {
        $desc = (string)($product->getDescription() ?: $product->getName());

        // 1. Force decode ALL HTML entities (loops until fully decoded)
        $previous = '';
        while ($desc !== $previous) {
            $previous = $desc;
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $desc = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // 2. Nuke <style>, <script>, and PageBuilder Tab Navigation completely
        $desc = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $desc);
        $desc = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $desc);
        $desc = preg_replace('/<ul\b[^>]*data-element="navigation"[^>]*>(.*?)<\/ul>/is', '', $desc);
        $desc = preg_replace('/<ul\b[^>]*role="tablist"[^>]*>(.*?)<\/ul>/is', '', $desc);

        // 3. Nuke Magento internal widgets/shortcodes (e.g., {{widget ...}})
        $desc = preg_replace('/\{\{.*?\}\}/s', '', $desc);

        // 4. Format visual spacing before stripping tags
        $desc = preg_replace('/<br\s*\/?>/i', "\n", $desc);
        $desc = str_replace(
            ['</p>', '</div>', '</ul>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>'],
            "\n\n",
            $desc
        );
        $desc = str_replace('<li>', "• ", $desc);

        // 5. Strip the actual HTML brackets
        $desc = strip_tags($desc);

        // 6. Clean up ugly whitespace and multi-newlines
        $desc = preg_replace("/\n\n+/", "\n\n", $desc);
        $desc = preg_replace("/[ \t]+/", " ", $desc); // Condense multiple spaces into one

        return trim($desc);
    }

    /**
     * Extracts the mapped Etsy Taxonomy ID from the product's categories.
     *
     * @param Product $product
     * @return int|null
     */
    private function getEtsyTaxonomyId(Product $product)
    {
        $categoryIds = $product->getCategoryIds();
        foreach ($categoryIds as $categoryId) {
            $category = $this->categoryRepository->get($categoryId);
            $taxId = $category->getData('etsy_taxonomy_id');
            if (!empty($taxId)) {
                return (int)$taxId;
            }
        }
        return null;
    }
}
