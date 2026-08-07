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
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class EtsyCustomOptionManager
{
    protected $scopeConfig;
    protected $logger;
    protected $resourceConnection;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        ResourceConnection $resourceConnection
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
    }

    public function extractEtsyPersonalizations(Product $product)
    {
        $handlingMode = $this->scopeConfig->getValue('etsy_integration/sync_settings/unsupported_options') ?: 'strict';
        $etsyQuestions = [];

        // 1. Process Native Magento Options
        $nativeOptions = $product->getOptions() ?: [];
        foreach ($nativeOptions as $option) {
            $this->processOptionItem(
                $option->getType(),
                $option->getTitle(),
                (bool)$option->getIsRequire(),
                $option->getData('placeholder'),
                $option->getValues(),
                $etsyQuestions,
                $handlingMode
            );
        }

        // 2. Process Shared Product Options (Bypasses Admin/Cron Area Restrictions)
        $sharedOptions = $this->loadSharedOptionsFromDb((int)$product->getId());
        foreach ($sharedOptions as $option) {
            $this->processOptionItem(
                $option['type'],
                $option['title'],
                (bool)$option['is_required'],
                $option['placeholder'] ?? null,
                $option['values'] ?? [],
                $etsyQuestions,
                $handlingMode
            );
        }

        return $etsyQuestions;
    }

    private function processOptionItem($type, $title, $isRequired, $placeholder, $values, &$etsyQuestions, $handlingMode)
    {
        if (count($etsyQuestions) >= 5) {
            $this->handleUnsupported("Etsy API limits listings to a maximum of 5 Custom Options.", $handlingMode);
            return;
        }

        $question = [
            'question_text' => substr($title, 0, 45),
            'required' => $isRequired
        ];

        if (!empty($placeholder)) {
            $question['instructions'] = substr($placeholder, 0, 120);
        }

        // 1. Text Fields & Areas -> text_input
        if ($type === 'field' || $type === 'area') {
            $question['question_type'] = 'text_input';
            $question['max_allowed_characters'] = 256;
            $etsyQuestions[] = $question;
            $this->logger->info("ETSY SYNC: Mapped Custom Option to Text Input -> " . $title);
        }
        // 2. Dropdowns -> dropdown
        elseif ($type === 'drop_down' || $type === 'radio') {
            if (empty($values)) {
                return;
            }

            $dropdownOptions = [];
            foreach ($values as $val) {
                // Handle both Value objects (native) and arrays (shared options)
                $valTitle = is_object($val) ? $val->getTitle() : ($val['title'] ?? '');
                if (!empty($valTitle)) {
                    $dropdownOptions[] = ['label' => substr($valTitle, 0, 20)];
                }
            }

            if (!empty($dropdownOptions)) {
                $question['question_type'] = 'dropdown';
                $question['options'] = $dropdownOptions;
                $etsyQuestions[] = $question;
                $this->logger->info("ETSY SYNC: Mapped Custom Option to Dropdown -> " . $title);
            }
        }
        // 3. Unsupported Types
        else {
            $this->handleUnsupported("Unsupported custom option type detected: " . $type, $handlingMode);
        }
    }

    /**
     * Directly queries Shared Product Option tables to bypass Admin/CLI/Cron guards
     */
    private function loadSharedOptionsFromDb(int $productId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $linkTable = $connection->getTableName('blueprint3d_shared_option_product');

        if (!$connection->isTableExists($linkTable)) {
            $this->logger->warning("ETSY SYNC: Link table {$linkTable} does not exist.");
            return [];
        }

        // Fetch assigned group IDs for this product
        $select = $connection->select()
            ->from($linkTable, 'group_id')
            ->where('product_id = ?', $productId);

        $groupIds = $connection->fetchCol($select);
        if (empty($groupIds)) {
            return [];
        }

        // UPDATED: Using the correct table name!
        $optionTable = $connection->getTableName('blueprint3d_shared_option_item');
        if (!$connection->isTableExists($optionTable)) {
            $this->logger->warning("ETSY SYNC: Item table {$optionTable} does not exist.");
            return [];
        }

        // Fetch master rows (parent_id = 0)
        $selectMaster = $connection->select()
            ->from($optionTable)
            ->where('group_id IN (?)', $groupIds)
            ->where('parent_id = ?', 0)
            ->order('sort_order ASC');

        $masterItems = $connection->fetchAll($selectMaster);
        $sharedOptions = [];

        foreach ($masterItems as $item) {
            $optionData = [
                'type' => $item['type'],
                'title' => $item['title'],
                'is_required' => (bool)($item['is_required'] ?? false),
                'placeholder' => $item['placeholder'] ?? null,
                'values' => []
            ];

            // Account for primary key being either 'item_id' or 'id'
            $primaryKey = $item['item_id'] ?? $item['id'] ?? null;

            if ($primaryKey && in_array($item['type'], ['drop_down', 'multiple', 'radio'])) {
                $selectSub = $connection->select()
                    ->from($optionTable)
                    ->where('parent_id = ?', (int)$primaryKey)
                    ->order('sort_order ASC');

                $optionData['values'] = $connection->fetchAll($selectSub);
            }

            $sharedOptions[] = $optionData;
        }

        return $sharedOptions;
    }

    private function handleUnsupported($message, $mode)
    {
        if ($mode === 'strict') {
            throw new \Exception("ETSY SYNC ABORTED: " . $message . " (Set handling to 'Lenient' in settings to bypass).");
        } else {
            $this->logger->warning("ETSY SYNC LENIENT MODE STRIPPED OPTION: " . $message);
        }
    }
}