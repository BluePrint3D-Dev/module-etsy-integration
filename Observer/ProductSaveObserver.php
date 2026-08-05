<?php
namespace BluePrint3D\EtsyIntegration\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use BluePrint3D\EtsyIntegration\Service\ProductSync;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

class ProductSaveObserver implements ObserverInterface
{
    protected $scopeConfig;
    protected $productSync;
    protected $messageManager;
    protected $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ProductSync $productSync,
        ManagerInterface $messageManager,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->productSync = $productSync;
        $this->messageManager = $messageManager;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        $product = $observer->getEvent()->getProduct();

        if (!$product->getData('etsy_sync_enabled')) {
            return;
        }

        $syncMode = $this->scopeConfig->getValue('etsy_integration/sync_settings/mode');

        if ($syncMode === 'realtime') {
            try {
                // Call our new sync engine!
                $listingId = $this->productSync->syncRealTime($product);
                $this->messageManager->addSuccessMessage(__("Successfully created/updated Draft Listing on Etsy! (ID: %1)", $listingId));
            } catch (\Exception $e) {
                // Display the API error directly on the Magento screen so the merchant knows what to fix
                $this->messageManager->addErrorMessage(__("Etsy Sync Error: %1", $e->getMessage()));
            }
        } else {
            $this->logger->info("ETSY SYNC: Queuing Product ID " . $product->getId() . " for background sync.");
            $this->messageManager->addNoticeMessage(__("Product queued for Etsy background sync."));
        }
    }
}