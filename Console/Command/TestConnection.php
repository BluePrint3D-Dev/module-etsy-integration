<?php
namespace BluePrint3D\EtsyIntegration\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use BluePrint3D\EtsyIntegration\Service\EtsyClient;

class TestConnection extends Command
{
    protected $etsyClient;

    public function __construct(EtsyClient $etsyClient)
    {
        $this->etsyClient = $etsyClient;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('blueprint3d:etsy:test')
            ->setDescription('Test Etsy API Connection and fetch active Shop ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Pinging Etsy API v3...</info>');

        try {
            // 1. Ask Etsy who we are to get the User ID
            $userData = $this->etsyClient->request('users/me');

            if (!isset($userData['user_id'])) {
                throw new \Exception("Could not retrieve user_id from Etsy.");
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
                throw new \Exception("No shop found for this user.");
            }

            $output->writeln("<info>SUCCESS! Connected to shop: {$shopName} (Shop ID: {$shopId})</info>");

            return \Magento\Framework\Console\Cli::RETURN_SUCCESS;

        } catch (\Exception $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
            return \Magento\Framework\Console\Cli::RETURN_FAILURE;
        }
    }
}