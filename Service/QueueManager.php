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

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

class QueueManager
{
    /**
     * Maximum allowed products for the Free / Freemium tier.
     * Change this single constant to switch between dev testing (5) and production (20).
     */
    private const FREE_TIER_PRODUCT_LIMIT = 5;

    protected $resourceConnection;
    protected $scopeConfig;
    protected $logger;

    public function __construct(
        ResourceConnection $resourceConnection,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * Add a product to the Etsy background sync queue
     */
    public function addToQueue(int $productId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $connection->getTableName('blueprint3d_etsy_queue');

        // 1. CHECK FREEMIUM LIMIT
        if (!$this->canQueueProduct($productId)) {
            $handlingMode = $this->scopeConfig->getValue('etsy_integration/sync_settings/unsupported_options') ?: 'strict';
            $message = sprintf(
                "Freemium limit reached (%d products). Upgrade to Etsy Integration Pro at blueprint3d.dev for unlimited product syncs!",
                self::FREE_TIER_PRODUCT_LIMIT
            );

            $this->logger->warning("ETSY SYNC LIMIT REACHED: Product ID {$productId} skipped.");

            if ($handlingMode === 'strict') {
                throw new \Exception($message);
            }

            return false;
        }

        // 2. CHECK IF PRODUCT IS ALREADY IN THE QUEUE
        $select = $connection->select()
            ->from($tableName, 'queue_id')
            ->where('product_id = ?', $productId);

        $existingQueueId = $connection->fetchOne($select);

        if ($existingQueueId) {
            // PRODUCT EXISTS: Reset it to pending and clear the error message
            $connection->update(
                $tableName,
                [
                    'status' => 'pending',
                    'message' => null
                ],
                ['queue_id = ?' => $existingQueueId]
            );
            $this->logger->info("ETSY QUEUE: Product ID {$productId} re-queued (error cleared).");
        } else {
            // NEW PRODUCT: Insert a brand new row
            $connection->insert(
                $tableName,
                [
                    'product_id' => $productId,
                    'status' => 'pending',
                    'message' => null,
                    'created_at' => date('Y-m-d H:i:s')
                ]
            );
            $this->logger->info("ETSY QUEUE: Product ID {$productId} queued for background sync.");
        }

        return true;
    }

    /**
     * Verifies if the product can be queued based on unique synced products count
     */
    public function canQueueProduct(int $productId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $connection->getTableName('blueprint3d_etsy_queue');

        // Allow re-syncing if product is already in the queue table
        $selectExisting = $connection->select()
            ->from($tableName, 'queue_id')
            ->where('product_id = ?', $productId);

        if ($connection->fetchOne($selectExisting)) {
            return true;
        }

        // Count distinct product IDs currently registered in queue
        $selectCount = $connection->select()
            ->from($tableName, new \Zend_Db_Expr('COUNT(DISTINCT product_id)'));

        $totalQueuedProducts = (int)$connection->fetchOne($selectCount);

        return $totalQueuedProducts < self::FREE_TIER_PRODUCT_LIMIT;
    }

    /**
     * Retrieve all pending queue items up to a specified limit
     *
     * @param int $limit
     * @return array
     */
    public function getPendingItems(int $limit = 50): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $connection->getTableName('blueprint3d_etsy_queue');

        $select = $connection->select()
            ->from($tableName)
            ->where('status = ?', 'pending')
            ->order('created_at ASC')
            ->limit($limit);

        return $connection->fetchAll($select);
    }

    /**
     * Update the processing status and message of a queue item
     *
     * @param int $queueId
     * @param string $status
     * @param string|null $message
     * @return void
     */
    public function updateStatus(int $queueId, string $status, ?string $message = null): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $connection->getTableName('blueprint3d_etsy_queue');

        $connection->update(
            $tableName,
            [
                'status' => $status,
                'message' => $message
            ],
            ['queue_id = ?' => $queueId]
        );
    }

    /**
     * Manually delete an item from the sync queue table
     *
     * @param int $queueId
     * @return void
     */
    public function removeFromQueue(int $queueId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $connection->getTableName('blueprint3d_etsy_queue');

        $connection->delete(
            $tableName,
            ['queue_id = ?' => $queueId]
        );
    }

    /**
     * Helper to mark a queue item as failed with an error message
     *
     * @param int $queueId
     * @param string $message
     * @return void
     */
    public function markAsError(int $queueId, string $message): void
    {
        $this->updateStatus($queueId, 'error', $message);
    }

    /**
     * Remove pending queue items for a specific product when "Sync Enabled" is toggled off
     *
     * @param int $productId
     * @return void
     */
    public function removePendingByProductId(int $productId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $connection->getTableName('blueprint3d_etsy_queue');

        $connection->delete(
            $tableName,
            [
                'product_id = ?' => $productId,
                'status = ?' => 'pending'
            ]
        );
    }

    /**
     * Completely wipe a product from the queue table to free up a freemium limit slot
     *
     * @param int $productId
     * @return void
     */
    public function purgeProductEntirely(int $productId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $connection->getTableName('blueprint3d_etsy_queue');

        $connection->delete(
            $tableName,
            ['product_id = ?' => $productId]
        );
    }

    /**
     * Check if the queue table has hit the freemium limit
     *
     * @return bool
     */
    public function hasReachedLimit(): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $connection->getTableName('blueprint3d_etsy_queue');

        if (!$connection->isTableExists($tableName)) {
            return false;
        }

        $select = $connection->select()
            ->from($tableName, new \Zend_Db_Expr('COUNT(DISTINCT product_id)'));

        $count = (int)$connection->fetchOne($select);

        return $count >= self::FREE_TIER_PRODUCT_LIMIT;
    }
}