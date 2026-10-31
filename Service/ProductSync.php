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
use BluePrint3D\EtsyIntegration\Model\Sync\GalleryImageProcessor;

/**
 * Class ProductSync
 * Orchestrates pushing product data and images to Etsy.
 */
class ProductSync
{
    /**
     * Most images Etsy lets a listing hold (raised from 10 to 20 by Etsy in 2026).
     */
    private const ETSY_MAX_IMAGES = 20;

    /**
     * Etsy's earlier limit. A listing that already holds this many images when an upload fails is
     * treated as full (the API can lag behind the dashboard), rather than as an unrelated error.
     */
    private const ETSY_LEGACY_IMAGE_LIMIT = 10;

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
     * @var GalleryImageProcessor
     */
    protected $galleryImageProcessor;

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
     * @param GalleryImageProcessor $galleryImageProcessor
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
        StockRegistryInterface $stockRegistry,
        GalleryImageProcessor $galleryImageProcessor
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
        $this->galleryImageProcessor = $galleryImageProcessor;
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
     * images once that's fully succeeded - if the upload fails partway the listing keeps
     * its original images instead of being left with fewer than before. The one exception
     * is a listing that is already full: there the previous images are removed to make room
     * (see uploadImageMakingRoom()), since the alternative is failing on every sync.
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
        $previousImages = $currentListing['images'] ?? [];

        $this->uploadGalleryImages($product, $shopId, $listingId, $previousImages);
        $this->deleteListingImages($shopId, $listingId, $previousImages);

        $this->productAction->updateAttributes([$product->getId()], ['etsy_images_hash' => $currentHash], 0);
    }

    /**
     * Fingerprint of the product's gallery images (file + order).
     *
     * Used to detect whether anything actually changed since the last Etsy sync.
     *
     * @param Product $product
     * @return string
     */
    private function getGalleryImagesHash(Product $product): string
    {
        $files = array_map(function ($image) {
            return $image->getFile();
        }, $this->getSortedGalleryImages($product));

        $fingerprint = implode('|', $files);

        // Appended only when a processor (e.g. Pro auto-crop) contributes something, so the
        // hash - and therefore the "skip re-upload" decision - is unchanged for everyone else.
        $signature = $this->galleryImageProcessor->getSignature($product);
        if ($signature !== '') {
            $fingerprint .= '#' . $signature;
        }

        // Change-detection fingerprint, not a security hash. MD5 output is kept so hashes already
        // stored on products stay valid (a different algorithm would re-upload every listing once).
        return hash('md5', $fingerprint);
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
     * Upload up to ETSY_MAX_IMAGES product gallery images to the Etsy listing.
     *
     * @param Product $product
     * @param string|int $shopId
     * @param string|int $listingId
     * @param array $previousImages Images already on the listing that this upload replaces. Passed by
     *     reference: any removed to make room are taken out of the list so they aren't deleted twice.
     * @return void
     */
    private function uploadGalleryImages(Product $product, $shopId, $listingId, array &$previousImages = [])
    {
        $rank = 1;

        foreach ($this->getSortedGalleryImages($product) as $image) {
            if ($rank > self::ETSY_MAX_IMAGES) {
                break;
            }

            $imagePath = $this->getGalleryImagePath($image);
            if ($imagePath === null) {
                continue;
            }

            $this->logger->info("Uploading gallery image to Etsy at Rank {$rank}: " . $imagePath);

            if (!$this->uploadGalleryImage($product, $shopId, $listingId, $imagePath, $rank, $previousImages)) {
                return;
            }
            $rank++;
        }
    }

    /**
     * Absolute path of a gallery image in Magento's media directory, or null when it has no file on disk.
     *
     * @param \Magento\Catalog\Model\Product\Gallery\Entry|\Magento\Framework\DataObject $image
     * @return string|null
     */
    private function getGalleryImagePath($image): ?string
    {
        $imageFile = $image->getFile();
        if (!$imageFile) {
            return null;
        }

        $relativePath = 'catalog/product' . $imageFile;

        return $this->mediaDirectory->isFile($relativePath)
            ? $this->mediaDirectory->getAbsolutePath($relativePath)
            : null;
    }

    /**
     * Send one gallery image to Etsy, via a processor's derived copy when it provides one.
     *
     * @param Product $product
     * @param string|int $shopId
     * @param string|int $listingId
     * @param string $imagePath
     * @param int $rank
     * @param array $previousImages Passed by reference, see uploadImageMakingRoom().
     * @return bool False when Etsy's limit was reached and no more images should be sent.
     */
    private function uploadGalleryImage(
        Product $product,
        $shopId,
        $listingId,
        string $imagePath,
        int $rank,
        array &$previousImages
    ): bool {
        // A processor may hand back a derived (temp) copy for Etsy only;
        // the Magento gallery file itself is never modified.
        $uploadPath = $this->galleryImageProcessor->prepare($product, $imagePath, $rank);

        try {
            return $this->uploadImageMakingRoom($shopId, $listingId, $uploadPath, $rank, $rank - 1, $previousImages);
        } finally {
            if ($uploadPath !== $imagePath) {
                $this->discardTemporaryCopy($uploadPath);
            }
        }
    }

    /**
     * Remove a derived image created for one upload. Only ever called on a temp copy, never a gallery file.
     *
     * @param string $path
     * @return void
     */
    private function discardTemporaryCopy(string $path): void
    {
        // phpcs:disable Magento2.Functions.DiscouragedFunction -- temp copy outside the media directory
        if (is_file($path)) {
            unlink($path);
        }
        // phpcs:enable Magento2.Functions.DiscouragedFunction
    }

    /**
     * Upload one image, coping with a listing that Etsy says is full.
     *
     * Etsy's image limit has changed and its API can lag behind its dashboard, so the limit is
     * discovered rather than assumed. If an upload fails while the listing already holds
     * ETSY_LEGACY_IMAGE_LIMIT or more images, it is treated as "full": the images being replaced are
     * removed to make room and this image is tried once more, and if there is nothing left to remove
     * Etsy has simply reached its limit, so the sync stops there instead of failing on every run.
     * A failure on a smaller listing is a genuine error and is rethrown.
     *
     * @param string|int $shopId
     * @param string|int $listingId
     * @param string $uploadPath
     * @param int $rank
     * @param int $uploaded Images of this refresh already uploaded.
     * @param array $previousImages Images being replaced that are still on the listing (by reference).
     * @return bool False when Etsy's limit was reached and no more images should be sent.
     * @throws \Exception
     */
    private function uploadImageMakingRoom(
        $shopId,
        $listingId,
        string $uploadPath,
        int $rank,
        int $uploaded,
        array &$previousImages
    ): bool {
        $endpoint = "shops/{$shopId}/listings/{$listingId}/images";

        try {
            $this->etsyClient->uploadImage($endpoint, $uploadPath, $rank);

            return true;
        } catch (\Exception $e) {
            if (count($previousImages) + $uploaded < self::ETSY_LEGACY_IMAGE_LIMIT) {
                throw $e;
            }

            if ($previousImages === []) {
                $this->logger->warning(sprintf(
                    'Etsy accepted %d images for listing %s and refused the next, so the rest are not sent: %s',
                    $uploaded,
                    $listingId,
                    $e->getMessage()
                ));

                return false;
            }

            $this->logger->warning(sprintf(
                'Etsy refused image %d for listing %s while it holds %d images; removing the %d replaced '
                . 'images to make room and retrying: %s',
                $rank,
                $listingId,
                count($previousImages) + $uploaded,
                count($previousImages),
                $e->getMessage()
            ));
            $this->deleteListingImages($shopId, $listingId, $previousImages);
            $this->etsyClient->uploadImage($endpoint, $uploadPath, $rank);

            return true;
        }
    }

    /**
     * Delete images from the Etsy listing, taking each out of the list as it goes.
     *
     * @param string|int $shopId
     * @param string|int $listingId
     * @param array $images Etsy image records (needs listing_image_id); emptied as they are deleted.
     * @return void
     */
    private function deleteListingImages($shopId, $listingId, array &$images): void
    {
        foreach ($images as $key => $image) {
            $this->etsyClient->request(
                "shops/{$shopId}/listings/{$listingId}/images/{$image['listing_image_id']}",
                'DELETE'
            );
            unset($images[$key]);
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
