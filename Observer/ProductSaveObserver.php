<?php
/**
 * Copyright (c) 2026 BluePrint3D Ltd. All rights reserved.
 *
 * Commercial Software License (EULA)
 * ...
 */
namespace BluePrint3D\EtsyIntegration\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use BluePrint3D\EtsyIntegration\Service\ProductSync;
use BluePrint3D\EtsyIntegration\Service\QueueManager;
use Magento\Framework\Message\ManagerInterface;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Psr\Log\LoggerInterface;

class ProductSaveObserver implements ObserverInterface
{
    protected $scopeConfig;
    protected $productSync;
    protected $queueManager;
    protected $messageManager;
    protected $productAction;
    protected $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ProductSync $productSync,
        QueueManager $queueManager,
        ManagerInterface $messageManager,
        ProductAction $productAction,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->productSync = $productSync;
        $this->queueManager = $queueManager;
        $this->messageManager = $messageManager;
        $this->productAction = $productAction;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        $product = $observer->getEvent()->getProduct();

        // 1. Grab the incoming values from the save action
        $syncEnabled = (bool)$product->getData('etsy_sync_enabled');

        if (!$syncEnabled) {
            // Force auto-renew off in memory
            $product->setData('etsy_auto_renew', 0);

            // Surgically update the database without triggering another full product save
            if ($product->getId()) {
                $this->productAction->updateAttributes([$product->getId()], ['etsy_auto_renew' => 0], 0);
            }
        }

        // 2. Stop execution if sync is disabled
        if (!$syncEnabled) {
            return;
        }

        // 3. Handle Active Syncs
        $syncMode = $this->scopeConfig->getValue('etsy_integration/sync_settings/mode');

        if ($syncMode === 'realtime') {
            try {
                $listingId = $this->productSync->syncRealTime($product);
                $this->messageManager->addSuccessMessage(__("Successfully created/updated Draft Listing on Etsy! (ID: %1)", $listingId));
            } catch (\Exception $e) {
                $this->messageManager->addErrorMessage(__("Etsy Sync Error: %1", $e->getMessage()));
            }
        } else {
            // FIRE INTO THE BACKGROUND QUEUE
            $this->queueManager->addToQueue($product->getId());
            $this->logger->info("ETSY SYNC: Queuing Product ID " . $product->getId() . " for background sync.");
            $this->messageManager->addNoticeMessage(__("Product queued for Etsy background sync."));
        }
    }
}