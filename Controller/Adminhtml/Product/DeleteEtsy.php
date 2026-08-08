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
namespace BluePrint3D\EtsyIntegration\Controller\Adminhtml\Product;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use BluePrint3D\EtsyIntegration\Service\EtsyClient;
use BluePrint3D\EtsyIntegration\Service\QueueManager;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;

/**
 * Class DeleteEtsy
 * Handles the manual deletion of an Etsy listing directly from the Magento Product Grid/Edit page.
 */
class DeleteEtsy extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Catalog::products';

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var EtsyClient
     */
    protected $etsyClient;

    /**
     * @var QueueManager
     */
    protected $queueManager;

    /**
     * @var ProductAction
     */
    protected $productAction;

    /**
     * DeleteEtsy constructor.
     *
     * @param Context $context
     * @param ProductRepositoryInterface $productRepository
     * @param EtsyClient $etsyClient
     * @param QueueManager $queueManager
     * @param ProductAction $productAction
     */
    public function __construct(
        Context $context,
        ProductRepositoryInterface $productRepository,
        EtsyClient $etsyClient,
        QueueManager $queueManager,
        ProductAction $productAction
    ) {
        $this->productRepository = $productRepository;
        $this->etsyClient = $etsyClient;
        $this->queueManager = $queueManager;
        $this->productAction = $productAction;
        parent::__construct($context);
    }

    /**
     * Execute action
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $id = $this->getRequest()->getParam('id');
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$id) {
            $this->messageManager->addErrorMessage(__('Product not found.'));
            return $resultRedirect->setPath('catalog/product/index');
        }

        try {
            $product = $this->productRepository->getById($id);
            $listingId = $product->getData('etsy_listing_id');

            if ($listingId) {
                // 1. DELETE FROM ETSY
                // Note: Etsy API v3 endpoint
                $this->etsyClient->request("listings/{$listingId}", 'DELETE');

                // 2. WIPE MAGENTO ATTRIBUTES
                $this->productAction->updateAttributes(
                    [$product->getId()],
                    [
                        'etsy_listing_id' => null,
                        'etsy_sync_enabled' => 0,
                        'etsy_auto_renew' => 0
                    ],
                    0
                );

                // 3. FREE UP FREEMIUM SLOT
                $this->queueManager->purgeProductEntirely($product->getId());

                $this->messageManager->addSuccessMessage(
                    __('Successfully deleted the listing from Etsy and freed up a sync slot!')
                );
            } else {
                $this->messageManager->addNoticeMessage(
                    __('This product is not currently linked to an Etsy listing.')
                );
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Failed to delete from Etsy: %1', $e->getMessage()));
        }

        // Send them right back to the product edit page they were just on
        return $resultRedirect->setPath('catalog/product/edit', ['id' => $id]);
    }
}
