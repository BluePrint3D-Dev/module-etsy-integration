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
namespace BluePrint3D\EtsyIntegration\Service;

use Magento\Catalog\Model\Product;
use BluePrint3D\EtsyIntegration\Service\EtsyClient;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use BluePrint3D\EtsyIntegration\Model\Sync\PayloadBuilder;
use BluePrint3D\EtsyIntegration\Model\Sync\InventoryPayloadBuilder;

/**
 * Class ProductSync
 * Orchestrates pushing product data and images to Etsy.
 */
class ProductSync
{
    /**
     * @var EtsyClient
     */
    protected $etsyClient;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Framework\Filesystem\Directory\ReadInterface
     */
    protected $mediaDirectory;

    /**
     * @var ProductAction
     */
    protected $productAction;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var EtsyCustomOptionManager
     */
    protected $customOptionManager;

    /**
     * @var PayloadBuilder
     */
    protected $payloadBuilder;

    /**
     * @var InventoryPayloadBuilder
     */
    protected $inventoryPayloadBuilder;

    /**
     * @var StockRegistryInterface
     */
    protected $stockRegistry;

    /**
     * ProductSync constructor.
     *
     * @param EtsyClient $etsyClient
     * @param ScopeConfigInterface $scopeConfig
     * @param Filesystem $filesystem
     * @param ProductAction $productAction
     * @param LoggerInterface $logger
     * @param EtsyCustomOptionManager $customOptionManager
     * @param PayloadBuilder $payloadBuilder
     * @param InventoryPayloadBuilder $inventoryPayloadBuilder
     * @param StockRegistryInterface $stockRegistry
     */
    public function __construct(
        EtsyClient $etsyClient,
        ScopeConfigInterface $scopeConfig,
        Filesystem $filesystem,
        ProductAction $productAction,
        LoggerInterface $logger,
        EtsyCustomOptionManager $customOptionManager,
        PayloadBuilder $payloadBuilder,
        InventoryPayloadBuilder $inventoryPayloadBuilder,
        StockRegistryInterface $stockRegistry
    ) {
        $this->etsyClient = $etsyClient;
        $this->scopeConfig = $scopeConfig;
        $this->mediaDirectory = $filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $this->productAction = $productAction;
        $this->logger = $logger;
        $this->customOptionManager = $customOptionManager;
        $this->payloadBuilder = $payloadBuilder;
        $this->inventoryPayloadBuilder = $inventoryPayloadBuilder;
        $this->stockRegistry = $stockRegistry;
    }

    /**
     * Synchronize a single product to Etsy immediately.
     *
     * @param Product $product
     * @return int|null
     * @throws LocalizedException
     */
    public function syncRealTime(Product $product)
    {
        $shopId = $this->scopeConfig->getValue('etsy_integration/api/shop_id');

        // Throwing outside the try/catch resolves the PHPCS "Same Function Catch" warning
        if (!$shopId) {
            throw new LocalizedException(__('Shop ID not found in configuration.'));
        }

        try {
            $shippingProfileId = $this->getDefaultShippingProfileId($shopId);
            $readinessStateId = $this->getDefaultReadinessStateId($shopId);

            // 1. Build Payload (Delegated to PayloadBuilder for Pro Price Rule plugin interception)
            $payload = $this->payloadBuilder->build($product, (int)$shippingProfileId, (int)$readinessStateId);

            // 2. CREATE or UPDATE Listing
            $etsyListingId = $product->getData('etsy_listing_id');
            $activeListingId = null;

            if ($etsyListingId) {
                try {
                    // TRY UPDATING EXISTING LISTING
                    $this->logger->info("Updating existing Etsy Listing: " . $etsyListingId);
                    $this->etsyClient->request("shops/{$shopId}/listings/{$etsyListingId}", 'PATCH', $payload);
                    $activeListingId = $etsyListingId;

                } catch (\Exception $e) {
                    // CATCH GHOST LISTING (Deleted or Removed on Etsy directly)
                    $errMsg = strtolower($e->getMessage());

                    $isGhost = str_contains($errMsg, '404')
                        || str_contains($errMsg, 'not found')
                        || str_contains($errMsg, 'does not exist')
                        || str_contains($errMsg, 'state: removed');

                    if ($isGhost) {
                        $this->logger->warning(
                            "Etsy Listing ID {$etsyListingId} is a Ghost Listing (Removed/Not Found). "
                            . "Clearing local attribute and recreating..."
                        );

                        // Wipe dead attribute from DB
                        $this->productAction->updateAttributes(
                            [$product->getId()],
                            ['etsy_listing_id' => null],
                            0
                        );

                        // Clear from current product object so the POST block below triggers
                        $product->setData('etsy_listing_id', null);
                        $etsyListingId = null;
                    } else {
                        // If it's a genuine API failure, throw it back up to the main catch block
                        throw $e;
                    }
                }
            }

            // FALLBACK TO CREATE NEW (if no listing ID existed or if ghost ID was just wiped above)
            if (!$etsyListingId) {
                $this->logger->info("Creating brand new Etsy Listing.");
                $response = $this->etsyClient->request("shops/{$shopId}/listings", 'POST', $payload);

                if (isset($response['listing_id'])) {
                    $activeListingId = $response['listing_id'];
                    $this->productAction->updateAttributes(
                        [$product->getId()],
                        ['etsy_listing_id' => $activeListingId],
                        0
                    );
                }
            }

            // 2b. SYNC IMAGES - runs on both create AND update, so Magento image changes
            // actually reach Etsy instead of only landing at initial listing creation.
            if ($activeListingId) {
                $this->syncGalleryImages($product, $shopId, $activeListingId);
            }

            // 3. SYNC CUSTOM OPTIONS (Personalizations)
            if ($activeListingId) {
                $personalizationQs = $this->customOptionManager->extractEtsyPersonalizations($product);

                if (!empty($personalizationQs)) {
                    $this->logger->info("Syncing " . count($personalizationQs) . " Custom Options to Etsy.");
                    $this->etsyClient->updatePersonalization($shopId, $activeListingId, $personalizationQs);
                }
            }

            // 4. SYNC PRICED DROPDOWNS (Variations - the only Etsy mechanism that supports per-value pricing)
            if ($activeListingId) {
                $pricedDropdowns = $this->customOptionManager->extractPricedDropdowns($product);

                if (!empty($pricedDropdowns)) {
                    $basePrice = $this->payloadBuilder->getCalculatedPrice($product);
                    $qty = max(1, (int)$this->stockRegistry->getStockItem($product->getId())->getQty());

                    $inventoryPayload = $this->inventoryPayloadBuilder->build(
                        $product,
                        $pricedDropdowns,
                        $basePrice,
                        (int)$readinessStateId,
                        $qty
                    );

                    if ($inventoryPayload) {
                        $this->logger->info(
                            "Syncing " . count($pricedDropdowns) . " priced dropdown(s) as Etsy Variations."
                        );
                        $this->etsyClient->updateInventory($activeListingId, $inventoryPayload);
                    }
                }
            }

            return $activeListingId;

        } catch (\Exception $e) {
            $this->logger->error("ETSY SYNC FAILED: " . $e->getMessage());
            throw new LocalizedException(__('ETSY SYNC FAILED: %1', $e->getMessage()));
        }
    }

    /**
     * Refresh the Etsy listing's images to match Magento's current gallery.
     *
     * Skips the refresh entirely when the gallery hasn't changed since the last sync
     * (tracked via the etsy_images_hash attribute), so routine syncs don't re-upload and
     * re-delete images that haven't moved. Etsy has no "replace all images" endpoint, so
     * an actual refresh uploads the current gallery first and only deletes the previous
     * images once that's fully succeeded - if the upload fails partway (e.g. hitting
     * Etsy's 10-image cap), the listing keeps its original images instead of being left
     * with fewer than before.
     *
     * @param Product $product
     * @param string|int $shopId
     * @param string|int $listingId
     * @return void
     */
    private function syncGalleryImages(Product $product, $shopId, $listingId)
    {
        $currentHash = $this->getGalleryImagesHash($product);

        if ($currentHash === $product->getData('etsy_images_hash')) {
            $this->logger->info("Etsy images unchanged since last sync - skipping image refresh.");
            return;
        }

        $currentListing = $this->etsyClient->request("listings/{$listingId}", 'GET', ['includes' => 'Images']);

        $this->uploadGalleryImages($product, $shopId, $listingId);

        foreach ($currentListing['images'] ?? [] as $image) {
            $this->etsyClient->request(
                "shops/{$shopId}/listings/{$listingId}/images/{$image['listing_image_id']}",
                'DELETE'
            );
        }

        $this->productAction->updateAttributes([$product->getId()], ['etsy_images_hash' => $currentHash], 0);
    }

    /**
     * Fingerprint of the product's gallery images (file + order), used to detect
     * whether anything actually changed since the last Etsy sync.
     *
     * @param Product $product
     * @return string
     */
    private function getGalleryImagesHash(Product $product): string
    {
        $files = array_map(function ($image) {
            return $image->getFile();
        }, $this->getSortedGalleryImages($product));

        return md5(implode('|', $files));
    }

    /**
     * Product gallery images sorted by position, as used for both hashing and upload rank.
     *
     * @param Product $product
     * @return \Magento\Catalog\Model\Product\Gallery\Entry[]
     */
    private function getSortedGalleryImages(Product $product): array
    {
        $galleryImages = $product->getMediaGalleryImages();

        if (!$galleryImages || $galleryImages->getSize() === 0) {
            return [];
        }

        $imagesArray = $galleryImages->getItems();
        usort($imagesArray, function ($a, $b) {
            return (int)$a->getPosition() <=> (int)$b->getPosition();
        });

        return $imagesArray;
    }

    /**
     * Upload up to 10 product gallery images to the Etsy listing.
     *
     * @param Product $product
     * @param string|int $shopId
     * @param string|int $listingId
     * @return void
     */
    private function uploadGalleryImages(Product $product, $shopId, $listingId)
    {
        $imagesArray = $this->getSortedGalleryImages($product);

        if (!empty($imagesArray)) {
            $rank = 1;

            foreach ($imagesArray as $image) {
                if ($rank > 10) {
                    break;
                }

                $imageFile = $image->getFile();
                if ($imageFile) {
                    $relativePath = 'catalog/product' . $imageFile;

                    // Replaced file_exists() with Magento's DirectoryRead object validation
                    if ($this->mediaDirectory->isFile($relativePath)) {
                        $imagePath = $this->mediaDirectory->getAbsolutePath($relativePath);
                        $this->logger->info("Uploading gallery image to Etsy at Rank {$rank}: " . $imagePath);

                        $this->etsyClient->uploadImage(
                            "shops/{$shopId}/listings/{$listingId}/images",
                            $imagePath,
                            $rank
                        );
                        $rank++;
                    }
                }
            }
        }
    }

    /**
     * Retrieve the first available shipping profile ID for the shop.
     *
     * @param string|int $shopId
     * @return int
     * @throws LocalizedException
     */
    private function getDefaultShippingProfileId($shopId)
    {
        $response = $this->etsyClient->request("shops/{$shopId}/shipping-profiles", 'GET');
        if (!empty($response['results'])) {
            return (int)$response['results'][0]['shipping_profile_id'];
        }

        throw new LocalizedException(__('No Shipping Profiles found on this Etsy account.'));
    }

    /**
     * Retrieve the first available readiness state definition ID for the shop.
     *
     * @param string|int $shopId
     * @return int
     * @throws LocalizedException
     */
    private function getDefaultReadinessStateId($shopId)
    {
        $response = $this->etsyClient->request("shops/{$shopId}/readiness-state-definitions", 'GET');
        if (!empty($response['results'])) {
            return (int)$response['results'][0]['readiness_state_id'];
        }

        throw new LocalizedException(__('No Processing Profiles found on this Etsy account.'));
    }
}
