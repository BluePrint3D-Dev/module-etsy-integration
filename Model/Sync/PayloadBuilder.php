<?php
namespace BluePrint3D\EtsyIntegration\Model\Sync;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Psr\Log\LoggerInterface;

class PayloadBuilder
{
    protected $stockRegistry;
    protected $categoryRepository;
    protected $logger;

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
     */
    public function build(Product $product, int $shippingProfileId, int $readinessStateId): array
    {
        $stockItem = $this->stockRegistry->getStockItem($product->getId());
        $qty = max(1, (int)$stockItem->getQty());

        $taxonomyId = $this->getEtsyTaxonomyId($product);
        if (!$taxonomyId) {
            throw new \Exception("No Etsy Category mapped for product: " . $product->getSku());
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
     * PUBLIC method so Pro plugin (ApplyPriceRulePlugin) can intercept and apply markups (+% or +£).
     */
    public function getCalculatedPrice(Product $product): float
    {
        $price = $product->getFinalPrice() ?: $product->getPrice();
        return (float)$price;
    }

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

    private function formatDescription(Product $product): string
    {
        $desc = $product->getDescription() ?: $product->getName();
        $desc = html_entity_decode((string)$desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $desc = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $desc = preg_replace('/<br\s*\/?>/i', "\n", $desc);
        $desc = str_replace(['</p>', '</div>'], "\n\n", $desc);
        $desc = strip_tags($desc);
        $desc = preg_replace("/\n\n+/", "\n\n", $desc);
        return trim($desc);
    }

    private function getEtsyTaxonomyId(Product $product)
    {
        $categoryIds = $product->getCategoryIds();
        foreach ($categoryIds as $categoryId) {
            $category = $this->categoryRepository->get($categoryId);
            $taxId = $category->getData('etsy_taxonomy_id');
            if (!empty($taxId)) {
                return $taxId;
            }
        }
        return null;
    }
}