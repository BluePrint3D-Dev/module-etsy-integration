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
namespace BluePrint3D\EtsyIntegration\Controller\Adminhtml\Queue;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use BluePrint3D\EtsyIntegration\Service\QueueManager;

/**
 * Class Retry
 * Re-queues every errored product so the next cron run tries them again.
 */
class Retry extends Action
{
    /**
     * Authorization level of a basic admin session
     */
    public const ADMIN_RESOURCE = 'BluePrint3D_EtsyIntegration::config';

    /**
     * @var QueueManager
     */
    protected $queueManager;

    /**
     * @param Context $context
     * @param QueueManager $queueManager
     */
    public function __construct(
        Context $context,
        QueueManager $queueManager
    ) {
        parent::__construct($context);
        $this->queueManager = $queueManager;
    }

    /**
     * Reset errored queue items to pending, then return to the page the button was clicked on.
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
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
