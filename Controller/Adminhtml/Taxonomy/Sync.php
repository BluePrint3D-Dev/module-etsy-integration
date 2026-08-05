<?php
namespace BluePrint3D\EtsyIntegration\Controller\Adminhtml\Taxonomy;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use BluePrint3D\EtsyIntegration\Service\TaxonomySync;

class Sync extends Action
{
    const ADMIN_RESOURCE = 'Magento_Backend::all';
    protected $taxonomySync;

    public function __construct(
        Context $context,
        TaxonomySync $taxonomySync
    ) {
        parent::__construct($context);
        $this->taxonomySync = $taxonomySync;
    }

    public function execute()
    {
        try {
            // Run the exact same logic as your CLI command
            $count = $this->taxonomySync->execute();
            $this->messageManager->addSuccessMessage(__("Success! Synced %1 Etsy categories to the database.", $count));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__("Error syncing categories: %1", $e->getMessage()));
        }

        // Bounce them back to the config page
        $resultRedirect = $this->resultRedirectFactory->create();
        return $resultRedirect->setPath('adminhtml/system_config/edit', ['section' => 'etsy_integration']);
    }
}