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
use BluePrint3D\EtsyIntegration\Service\EtsyClient;

class TaxonomySync
{
    protected $etsyClient;
    protected $resourceConnection;

    public function __construct(
        EtsyClient $etsyClient,
        ResourceConnection $resourceConnection
    ) {
        $this->etsyClient = $etsyClient;
        $this->resourceConnection = $resourceConnection;
    }

    public function execute()
    {
        $response = $this->etsyClient->request('seller-taxonomy/nodes', 'GET');

        if (empty($response['results'])) {
            throw new \Exception("No taxonomy data returned from Etsy.");
        }

        $flattenedData = [];
        $this->parseNodes($response['results'], null, '', 1, $flattenedData);

        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('blueprint3d_etsy_taxonomy');

        $connection->insertOnDuplicate($tableName, $flattenedData, ['name', 'path', 'parent_id', 'level']);

        return count($flattenedData);
    }

    private function parseNodes($nodes, $parentId, $parentPath, $level, &$result)
    {
        foreach ($nodes as $node) {
            $currentPath = $parentPath ? $parentPath . ' > ' . $node['name'] : $node['name'];
            $result[] = [
                'taxonomy_id' => $node['id'],
                'parent_id'   => $parentId,
                'name'        => $node['name'],
                'path'        => $currentPath,
                'level'       => $level
            ];
            if (!empty($node['children'])) {
                $this->parseNodes($node['children'], $node['id'], $currentPath, $level + 1, $result);
            }
        }
    }
}