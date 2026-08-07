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
namespace BluePrint3D\EtsyIntegration\Cron;

use BluePrint3D\EtsyIntegration\Service\QueueManager;
use BluePrint3D\EtsyIntegration\Service\ProductSync;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Psr\Log\LoggerInterface;

class ProcessQueue
{
    protected $queueManager;
    protected $productSync;
    protected $productRepository;
    protected $logger;

    public function __construct(
        QueueManager $queueManager,
        ProductSync $productSync,
        ProductRepositoryInterface $productRepository,
        LoggerInterface $logger
    ) {
        $this->queueManager = $queueManager;
        $this->productSync = $productSync;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
    }

    public function execute()
    {
        // Grab up to 5 pending items from the waiting room
        $pendingItems = $this->queueManager->getPendingItems(5);

        if (empty($pendingItems)) {
            return;
        }

        $this->logger->info("ETSY CRON: Processing " . count($pendingItems) . " queued products.");

        foreach ($pendingItems as $item) {
            try {
                // Load the full product data
                $product = $this->productRepository->getById($item['product_id']);

                // Fire the exact same sync engine we used for real-time
                $this->productSync->syncRealTime($product);

                // If successful, mark status as complete to enforce the 20 lifetime product limit
                $this->queueManager->updateStatus($item['queue_id'], 'complete');

            } catch (\Exception $e) {
                // If it fails, mark it as an error so we can view it later
                $this->queueManager->markAsError($item['queue_id'], $e->getMessage());
                $this->logger->error("ETSY CRON FAILED for Product ID {$item['product_id']}: " . $e->getMessage());
            }
        }
    }
}