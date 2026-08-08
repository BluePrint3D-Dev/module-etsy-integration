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
namespace BluePrint3D\EtsyIntegration\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Catalog\Model\Product;
use Magento\Framework\Escaper;

/**
 * Class EtsyStatus
 * UI Grid Column for displaying Etsy synchronization status badges in catalog product lists.
 */
class EtsyStatus extends Column
{
    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var EavConfig
     */
    protected $eavConfig;

    /**
     * @var Escaper
     */
    protected $escaper;

    /**
     * EtsyStatus constructor.
     *
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param ResourceConnection $resourceConnection
     * @param EavConfig $eavConfig
     * @param Escaper $escaper
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        ResourceConnection $resourceConnection,
        EavConfig $eavConfig,
        Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->eavConfig = $eavConfig;
        $this->escaper = $escaper;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Prepare data source for rendering status badges in grid
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            $productIds = [];
            foreach ($dataSource['data']['items'] as $item) {
                $productIds[] = $item['entity_id'];
            }

            if (empty($productIds)) {
                return $dataSource;
            }

            $connection = $this->resourceConnection->getConnection();

            // 1. GET QUEUE STATUSES
            $queueTable = $connection->getTableName('blueprint3d_etsy_queue');
            $queueSelect = $connection->select()
                ->from($queueTable, ['product_id', 'status', 'message'])
                ->where('product_id IN (?)', $productIds);

            $queueData = $connection->fetchAll($queueSelect);
            $queueMap = [];
            foreach ($queueData as $row) {
                $queueMap[$row['product_id']] = $row;
            }

            // 2. GET ETSY LISTING IDs
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, 'etsy_listing_id');
            $attrTable = $attribute->getBackend()->getTable();

            $listingSelect = $connection->select()
                ->from($attrTable, ['entity_id', 'value'])
                ->where('attribute_id = ?', $attribute->getId())
                ->where('entity_id IN (?)', $productIds)
                ->where('value IS NOT NULL')
                ->where('value != ?', '')
                ->order('store_id ASC');

            $listingIds = $connection->fetchPairs($listingSelect);

            // 3. MAP THE BADGES
            foreach ($dataSource['data']['items'] as &$item) {
                $productId = $item['entity_id'];
                $listingId = $listingIds[$productId] ?? null;

                $isPending = false;
                $isError = false;
                $errorMsg = '';

                // Check the queue state first
                if (isset($queueMap[$productId])) {
                    $status = $queueMap[$productId]['status'];
                    if ($status === 'pending') {
                        $isPending = true;
                    } elseif ($status === 'error') {
                        $isError = true;
                        $errorMsg = $this->escaper->escapeHtml((string)$queueMap[$productId]['message']);
                    }
                }

                // Render the correct badge based on state
                if ($isPending) {
                    $item[$this->getData('name')] = $this->getBadgeHtml(
                        'Queued',
                        'minor',
                        'Waiting for background sync...'
                    );
                } elseif ($isError) {
                    $item[$this->getData('name')] = $this->getBadgeHtml(
                        'Error',
                        'critical',
                        $errorMsg
                    );
                } elseif (!empty($listingId)) {
                    $item[$this->getData('name')] = $this->getBadgeHtml(
                        'Synced',
                        'notice',
                        'Listing ID: ' . $listingId
                    );
                } else {
                    $item[$this->getData('name')] = $this->getBadgeHtml('Not Synced', 'default');
                }
            }
        }

        return $dataSource;
    }

    /**
     * Helper method to build status badge HTML
     *
     * @param string $label
     * @param string $severity
     * @param string $tooltip
     * @return string
     */
    private function getBadgeHtml(string $label, string $severity, string $tooltip = ''): string
    {
        $titleAttr = $tooltip ? 'title="' . $tooltip . '"' : '';

        if ($severity === 'default') {
            return '<span ' . $titleAttr . ' style="display: inline-block; padding: 2px 8px; ' .
                'border-radius: 12px; background-color: #f4f4f4; color: #7d7d7d; ' .
                'font-size: 11px; font-weight: 600; cursor: default;">' . $label . '</span>';
        }

        return '<span ' . $titleAttr . ' class="grid-severity-' . $severity . '"><span>' .
            $label . '</span></span>';
    }
}
