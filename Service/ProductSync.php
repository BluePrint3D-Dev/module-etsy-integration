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
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Psr\Log\LoggerInterface;
use BluePrint3D\EtsyIntegration\Model\Sync\PayloadBuilder;

class ProductSync
{
    protected $etsyClient;
    protected $scopeConfig;
    protected $mediaDirectory;
    protected $productAction;
    protected $logger;
    protected $customOptionManager;
    protected $payloadBuilder;

    public function __construct(
        EtsyClient $etsyClient,
        ScopeConfigInterface $scopeConfig,
        Filesystem $filesystem,
        ProductAction $productAction,
        LoggerInterface $logger,
        EtsyCustomOptionManager $customOptionManager,
        PayloadBuilder $payloadBuilder
    ) {
        $this->etsyClient = $etsyClient;
        $this->scopeConfig = $scopeConfig;
        $this->mediaDirectory = $filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $this->productAction = $productAction;
        $this->logger = $logger;
        $this->customOptionManager = $customOptionManager;
        $this->payloadBuilder = $payloadBuilder;
    }

    public function syncRealTime(Product $product)
    {
        try {
            $shopId = $this->scopeConfig->getValue('etsy_integration/api/shop_id');
            if (!$shopId) {
                throw new \Exception("Shop ID not found.");
            }

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
                        $this->logger->warning("Etsy Listing ID {$etsyListingId} is a Ghost Listing (Removed/Not Found). Clearing local attribute and recreating...");

                        // Wipe dead attribute from DB
                        $this->productAction->updateAttributes([$product->getId()], ['etsy_listing_id' => null], 0);

                        // Clear from current product object so the POST block below triggers
                        $product->setData('etsy_listing_id', null);
                        $etsyListingId = null;
                    } else {
                        // If it's a 500 error, auth error, or invalid payload, throw it back up
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
                    $this->productAction->updateAttributes([$product->getId()], ['etsy_listing_id' => $activeListingId], 0);

                    // Upload Multiple Images (Etsy Max is 10)
                    $this->uploadGalleryImages($product, $shopId, $activeListingId);
                }
            }

            // 3. SYNC CUSTOM OPTIONS (Personalizations)
            if ($activeListingId) {
                $personalizationQuestions = $this->customOptionManager->extractEtsyPersonalizations($product);

                if (!empty($personalizationQuestions)) {
                    $this->logger->info("Syncing " . count($personalizationQuestions) . " Custom Options to Etsy.");
                    $this->etsyClient->updatePersonalization($shopId, $activeListingId, $personalizationQuestions);
                }
            }

            return $activeListingId;

        } catch (\Exception $e) {
            $this->logger->error("ETSY SYNC FAILED: " . $e->getMessage());
            throw $e;
        }
    }

    private function uploadGalleryImages(Product $product, $shopId, $listingId)
    {
        $galleryImages = $product->getMediaGalleryImages();

        if ($galleryImages && $galleryImages->getSize() > 0) {
            $imagesArray = $galleryImages->getItems();
            usort($imagesArray, function($a, $b) {
                return (int)$a->getPosition() <=> (int)$b->getPosition();
            });

            $rank = 1;

            foreach ($imagesArray as $image) {
                if ($rank > 10) {
                    break;
                }

                $imageFile = $image->getFile();
                if ($imageFile) {
                    $imagePath = $this->mediaDirectory->getAbsolutePath('catalog/product' . $imageFile);
                    if (file_exists($imagePath)) {
                        $this->logger->info("Uploading gallery image to Etsy at Rank {$rank}: " . $imagePath);
                        $this->etsyClient->uploadImage("shops/{$shopId}/listings/{$listingId}/images", $imagePath, $rank);
                        $rank++;
                    }
                }
            }
        }
    }

    private function getDefaultShippingProfileId($shopId)
    {
        $response = $this->etsyClient->request("shops/{$shopId}/shipping-profiles", 'GET');
        if (!empty($response['results'])) {
            return $response['results'][0]['shipping_profile_id'];
        }
        throw new \Exception("No Shipping Profiles found.");
    }

    private function getDefaultReadinessStateId($shopId)
    {
        $response = $this->etsyClient->request("shops/{$shopId}/readiness-state-definitions", 'GET');
        if (!empty($response['results'])) {
            return $response['results'][0]['readiness_state_id'];
        }
        throw new \Exception("No Processing Profiles found.");
    }
}