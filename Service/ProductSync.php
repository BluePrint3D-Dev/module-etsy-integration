<?php
namespace BluePrint3D\EtsyIntegration\Service;

use Magento\Catalog\Model\Product;
use BluePrint3D\EtsyIntegration\Service\EtsyClient;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Psr\Log\LoggerInterface;

class ProductSync
{
    protected $etsyClient;
    protected $scopeConfig;
    protected $stockRegistry;
    protected $categoryRepository;
    protected $mediaDirectory;
    protected $productAction;
    protected $logger;

    public function __construct(
        EtsyClient $etsyClient,
        ScopeConfigInterface $scopeConfig,
        StockRegistryInterface $stockRegistry,
        CategoryRepositoryInterface $categoryRepository,
        Filesystem $filesystem,
        ProductAction $productAction,
        LoggerInterface $logger
    ) {
        $this->etsyClient = $etsyClient;
        $this->scopeConfig = $scopeConfig;
        $this->stockRegistry = $stockRegistry;
        $this->categoryRepository = $categoryRepository;
        $this->mediaDirectory = $filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $this->productAction = $productAction;
        $this->logger = $logger;
    }

    public function syncRealTime(Product $product)
    {
        try {
            $shopId = $this->scopeConfig->getValue('etsy_integration/api/shop_id');
            if (!$shopId) throw new \Exception("Shop ID not found.");

            $stockItem = $this->stockRegistry->getStockItem($product->getId());
            $qty = max(1, (int)$stockItem->getQty());

            $taxonomyId = $this->getEtsyTaxonomyId($product);
            if (!$taxonomyId) throw new \Exception("No Etsy Category mapped.");

            $shippingProfileId = $this->getDefaultShippingProfileId($shopId);
            $readinessStateId = $this->getDefaultReadinessStateId($shopId);

            // 1. Build Payload
            $payload = [
                'quantity' => $qty,
                'title' => substr($product->getName(), 0, 140),
                'description' => $this->formatDescription($product),
                'price' => (float)$product->getPrice(),
                'who_made' => $product->getData('etsy_who_made'),
                'when_made' => $product->getData('etsy_when_made'),
                'taxonomy_id' => (int)$taxonomyId,
                'is_supply' => (bool)$product->getData('etsy_is_supply'),
                'should_auto_renew' => (bool)$product->getData('etsy_auto_renew'),
                'tags' => $this->getEtsyTags($product),
                'shipping_profile_id' => (int)$shippingProfileId,
                'readiness_state_id' => (int)$readinessStateId
            ];

            // 2. CREATE or UPDATE?
            $etsyListingId = $product->getData('etsy_listing_id');

            if ($etsyListingId) {
                // UPDATE EXISTING
                $this->logger->info("Updating existing Etsy Listing: " . $etsyListingId);
                $this->etsyClient->request("shops/{$shopId}/listings/{$etsyListingId}", 'PATCH', $payload);
                return $etsyListingId;

            } else {
                // CREATE NEW
                $this->logger->info("Creating brand new Etsy Listing.");
                $response = $this->etsyClient->request("shops/{$shopId}/listings", 'POST', $payload);

                if (isset($response['listing_id'])) {
                    $newListingId = $response['listing_id'];
                    $this->productAction->updateAttributes([$product->getId()], ['etsy_listing_id' => $newListingId], 0);

                    // Upload Multiple Images (Etsy Max is 10)
                    $this->uploadGalleryImages($product, $shopId, $newListingId);

                    return $newListingId;
                }
            }

        } catch (\Exception $e) {
            $this->logger->error("ETSY SYNC FAILED: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Extracts Meta Keywords, strips invalid characters, truncates to 20 chars, and caps at 13 tags.
     * If Meta Keywords are empty, intelligently falls back to generating tags from the Product Name.
     */
    private function getEtsyTags(Product $product)
    {
        $tags = [];
        $metaKeywords = (string)$product->getData('meta_keyword');

        // SMART FALLBACK: If the merchant forgot Meta Keywords, use the Product Name!
        if (empty(trim($metaKeywords))) {
            $name = $product->getName();
            $this->logger->info("Meta Keywords empty! Auto-generating tags from Product Name: " . $name);

            // Create a combo of the full name, plus individual words
            $metaKeywords = $name . ', ' . str_replace(' ', ', ', $name);
        } else {
            $this->logger->info("Raw Magento Meta Keywords: " . $metaKeywords);
        }

        // Split by comma, semicolon, or new line
        $keywordArray = preg_split('/[,;\n]+/', $metaKeywords);

        foreach ($keywordArray as $keyword) {
            // Etsy only allows alphanumeric characters and spaces in tags
            $clean = preg_replace('/[^a-zA-Z0-9\s]/', '', $keyword);
            $clean = trim($clean);

            if ($clean !== '') {
                // Force the tag to fit Etsy's 20-character limit
                if (strlen($clean) > 20) {
                    $clean = substr($clean, 0, 20);
                }

                $clean = strtolower($clean);

                // Prevent duplicate tags
                if (!in_array($clean, $tags)) {
                    $tags[] = $clean;
                }
            }

            if (count($tags) >= 13) break;
        }

        $this->logger->info("Final Etsy Tags: ", $tags);

        return $tags;
    }

    /**
     * Loops through the Magento Media Gallery, sorts by position, and uploads up to 10 photos
     */
    private function uploadGalleryImages(Product $product, $shopId, $listingId)
    {
        $galleryImages = $product->getMediaGalleryImages();

        if ($galleryImages && $galleryImages->getSize() > 0) {

            // 1. Convert to an array and sort explicitly by Magento's 'position' attribute
            $imagesArray = $galleryImages->getItems();
            usort($imagesArray, function($a, $b) {
                return (int)$a->getPosition() <=> (int)$b->getPosition();
            });

            $rank = 1; // Etsy ranks start at 1

            foreach ($imagesArray as $image) {
                if ($rank > 10) break; // Hard stop at Etsy's maximum

                $imageFile = $image->getFile();
                if ($imageFile) {
                    $imagePath = $this->mediaDirectory->getAbsolutePath('catalog/product' . $imageFile);
                    if (file_exists($imagePath)) {
                        $this->logger->info("Uploading gallery image to Etsy at Rank {$rank}: " . $imagePath);

                        // Pass the $rank to our updated client
                        $this->etsyClient->uploadImage("shops/{$shopId}/listings/{$listingId}/images", $imagePath, $rank);

                        $rank++;
                    }
                }
            }
        }
    }

    private function formatDescription(Product $product)
    {
        $desc = $product->getDescription() ?: $product->getName();
        $desc = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
            if (!empty($taxId)) return $taxId;
        }
        return null;
    }

    private function getDefaultShippingProfileId($shopId)
    {
        $response = $this->etsyClient->request("shops/{$shopId}/shipping-profiles", 'GET');
        if (!empty($response['results'])) return $response['results'][0]['shipping_profile_id'];
        throw new \Exception("No Shipping Profiles found.");
    }

    private function getDefaultReadinessStateId($shopId)
    {
        $response = $this->etsyClient->request("shops/{$shopId}/readiness-state-definitions", 'GET');
        if (!empty($response['results'])) return $response['results'][0]['readiness_state_id'];
        throw new \Exception("No Processing Profiles found.");
    }
}