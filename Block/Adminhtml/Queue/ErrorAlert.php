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
namespace BluePrint3D\EtsyIntegration\Block\Adminhtml\Queue;

use Magento\Backend\Block\Template;
use Magento\Framework\App\ResourceConnection;
use BluePrint3D\EtsyIntegration\Service\QueueManager;

/**
 * Class ErrorAlert
 * Block for rendering queue errors and subscription limit alerts in the admin panel.
 */
class ErrorAlert extends Template
{
    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var QueueManager
     */
    protected $queueManager;

    /**
     * ErrorAlert constructor.
     *
     * @param Template\Context $context
     * @param ResourceConnection $resourceConnection
     * @param QueueManager $queueManager
     * @param array $data
     */
    public function __construct(
        Template\Context $context,
        ResourceConnection $resourceConnection,
        QueueManager $queueManager,
        array $data = []
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->queueManager = $queueManager;
        parent::__construct($context, $data);
    }

    /**
     * Get the total count of products that failed to sync
     *
     * @return int
     */
    public function getErrorCount(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $connection->getTableName('blueprint3d_etsy_queue');

        if (!$connection->isTableExists($tableName)) {
            return 0;
        }

        $select = $connection->select()
            ->from($tableName, new \Zend_Db_Expr('COUNT(queue_id)'))
            ->where('status = ?', 'error');

        return (int)$connection->fetchOne($select);
    }

    /**
     * Check if the merchant has hit their freemium limit
     *
     * @return bool
     */
    public function isLimitReached(): bool
    {
        return $this->queueManager->hasReachedLimit();
    }

    /**
     * Retrieves the specific products and error messages that failed to sync.
     *
     * @return array
     */
    public function getErrorDetails(): array
    {
        $errors = [];

        try {
            $connection = $this->resourceConnection->getConnection();
            $queueTable = $connection->getTableName('blueprint3d_etsy_queue');
            $productTable = $connection->getTableName('catalog_product_entity');

            if (!$connection->isTableExists($queueTable)) {
                return [];
            }

            $select = $connection->select()
                ->from(['q' => $queueTable], ['product_id', 'message'])
                ->joinLeft(
                    ['p' => $productTable],
                    'q.product_id = p.entity_id',
                    ['sku']
                )
                ->where('q.status = ?', 'error')
                ->limit(20);

            $results = $connection->fetchAll($select);

            foreach ($results as $row) {
                $sku = !empty($row['sku'])
                    ? $row['sku']
                    : 'Deleted Product (ID: ' . $row['product_id'] . ')';

                $errorMessage = !empty($row['message'])
                    ? $row['message']
                    : 'Unknown API Error';

                $errors[] = [
                    'sku' => $sku,
                    'message' => $errorMessage
                ];
            }
        } catch (\Exception $e) {
            $errors[] = [
                'sku' => 'System Error',
                'message' => 'Could not load error details: ' . $e->getMessage()
            ];
        }

        return $errors;
    }
    /**
     * Get URL for the retry action controller
     *
     * @return string
     */
    public function getRetryUrl(): string
    {
        // Make sure 'etsy' matches your actual module's router ID in routes.xml
        return $this->getUrl('etsy/queue/retry');
    }
}
