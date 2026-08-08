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
namespace BluePrint3D\EtsyIntegration\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use BluePrint3D\EtsyIntegration\Service\EtsyClient;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;

/**
 * Class SyncTaxonomy
 * CLI command to sync and flatten the Etsy taxonomy category tree into the database.
 */
class SyncTaxonomy extends Command
{
    /**
     * @var EtsyClient
     */
    protected $etsyClient;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * SyncTaxonomy constructor.
     *
     * @param EtsyClient $etsyClient
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        EtsyClient $etsyClient,
        ResourceConnection $resourceConnection
    ) {
        $this->etsyClient = $etsyClient;
        $this->resourceConnection = $resourceConnection;
        parent::__construct();
    }

    /**
     * Configure CLI command name and description.
     *
     * @return void
     */
    protected function configure()
    {
        $this->setName('blueprint3d:etsy:sync-taxonomy')
            ->setDescription('Downloads and caches the Etsy category taxonomy tree');
    }

    /**
     * Execute CLI command logic.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Requesting Taxonomy Tree from Etsy...</info>');

        try {
            // 1. Fetch the raw tree from Etsy
            $response = $this->etsyClient->request('seller-taxonomy/nodes', 'GET');

            if (empty($response['results'])) {
                $output->writeln('<error>Error: No taxonomy data returned from Etsy.</error>');
                return Cli::RETURN_FAILURE;
            }

            $output->writeln('<info>Parsing nested tree...</info>');

            // 2. Flatten the tree into an array
            $flattenedData = [];
            $this->parseNodes($response['results'], null, '', 1, $flattenedData);

            $count = count($flattenedData);
            $output->writeln("<info>Found {$count} categories. Saving to database...</info>");

            // 3. Perform a lightning-fast bulk insert
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('blueprint3d_etsy_taxonomy');

            // We use insertOnDuplicate so running this command again just updates existing rows
            $connection->insertOnDuplicate($tableName, $flattenedData, ['name', 'path', 'parent_id', 'level']);

            $output->writeln("<info>SUCCESS! Taxonomy sync complete.</info>");

            return Cli::RETURN_SUCCESS;

        } catch (\Exception $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
            return Cli::RETURN_FAILURE;
        }
    }

    /**
     * Recursively flattens the Etsy category tree and builds the breadcrumb path.
     *
     * @param array $nodes
     * @param int|string|null $parentId
     * @param string $parentPath
     * @param int $level
     * @param array $result
     * @return void
     */
    private function parseNodes(array $nodes, $parentId, string $parentPath, int $level, array &$result): void
    {
        foreach ($nodes as $node) {
            // Build the breadcrumb (e.g., "Art & Collectibles > Prints > Digital Prints")
            $currentPath = $parentPath ? $parentPath . ' > ' . $node['name'] : $node['name'];

            $result[] = [
                'taxonomy_id' => $node['id'],
                'parent_id'   => $parentId,
                'name'        => $node['name'],
                'path'        => $currentPath,
                'level'       => $level
            ];

            // If this category has children, dive deeper into the tree
            if (!empty($node['children'])) {
                $this->parseNodes($node['children'], $node['id'], $currentPath, $level + 1, $result);
            }
        }
    }
}
