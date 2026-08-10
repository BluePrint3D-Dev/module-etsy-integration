<?php
namespace BluePrint3D\EtsyIntegration\Controller\Adminhtml\Queue;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use BluePrint3D\EtsyIntegration\Service\QueueManager;

class Retry extends Action
{
    /**
     * Authorization level of a basic admin session
     */
    const ADMIN_RESOURCE = 'BluePrint3D_EtsyIntegration::config';

    protected $queueManager;

    public function __construct(
        Context $context,
        QueueManager $queueManager
    ) {
        parent::__construct($context);
        $this->queueManager = $queueManager;
    }

    public function execute()
    {
        try {
            $updatedCount = $this->queueManager->requeueAllErrors();
            $this->messageManager->addSuccessMessage(
                __('%1 errored product(s) have been successfully re-queued for the next sync.', $updatedCount)
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Failed to re-queue errors: %1', $e->getMessage()));
        }

        // Redirect back to the page the user clicked the button from
        $resultRedirect = $this->resultRedirectFactory->create();
        return $resultRedirect->setRefererUrl();
    }
}