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
use Magento\Framework\Console\Cli;

/**
 * Class TestConnection
 * CLI command to verify Etsy API connection and retrieve shop details.
 */
class TestConnection extends Command
{
    /**
     * @var EtsyClient
     */
    protected $etsyClient;

    /**
     * TestConnection constructor.
     *
     * @param EtsyClient $etsyClient
     */
    public function __construct(EtsyClient $etsyClient)
    {
        $this->etsyClient = $etsyClient;
        parent::__construct();
    }

    /**
     * Configure CLI command name and description.
     *
     * @return void
     */
    protected function configure()
    {
        $this->setName('blueprint3d:etsy:test')
            ->setDescription('Test Etsy API Connection and fetch active Shop ID');
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
        $output->writeln('<info>Pinging Etsy API v3...</info>');

        try {
            // 1. Ask Etsy who we are to get the User ID
            $userData = $this->etsyClient->request('users/me');

            if (!isset($userData['user_id'])) {
                $output->writeln('<error>Error: Could not retrieve user_id from Etsy.</error>');
                return Cli::RETURN_FAILURE;
            }

            $userId = $userData['user_id'];
            $output->writeln("Authenticated as User ID: " . $userId);

            // 2. Fetch the shop attached to this User ID
            $shopData = $this->etsyClient->request("users/{$userId}/shops");

            if (!empty($shopData['shop_id'])) {
                // Sometimes Etsy returns a single shop object directly
                $shopId = $shopData['shop_id'];
                $shopName = $shopData['shop_name'];
            } elseif (!empty($shopData['results'][0]['shop_id'])) {
                // Other times it returns an array of results
                $shopId = $shopData['results'][0]['shop_id'];
                $shopName = $shopData['results'][0]['shop_name'];
            } else {
                $output->writeln('<error>Error: No shop found for this user.</error>');
                return Cli::RETURN_FAILURE;
            }

            $output->writeln("<info>SUCCESS! Connected to shop: {$shopName} (Shop ID: {$shopId})</info>");

            return Cli::RETURN_SUCCESS;

        } catch (\Exception $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
            return Cli::RETURN_FAILURE;
        }
    }
}
