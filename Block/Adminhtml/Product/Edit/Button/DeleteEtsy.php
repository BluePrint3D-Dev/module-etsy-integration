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
namespace BluePrint3D\EtsyIntegration\Block\Adminhtml\Product\Edit\Button;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Framework\UrlInterface;

/**
 * Class DeleteEtsy
 * Renders the "Delete from Etsy" action button on the product edit screen in Adminhtml.
 */
class DeleteEtsy implements ButtonProviderInterface
{
    /**
     * @var LocatorInterface
     */
    protected $locator;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * DeleteEtsy constructor.
     *
     * @param LocatorInterface $locator
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        LocatorInterface $locator,
        UrlInterface $urlBuilder
    ) {
        $this->locator = $locator;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * Get button data configuration
     *
     * @return array
     */
    public function getButtonData()
    {
        $product = $this->locator->getProduct();

        // Hide the button completely if this product isn't on Etsy!
        if (!$product->getData('etsy_listing_id')) {
            return [];
        }

        // phpcs:ignore Magento2.i18n.TextFunctions.Concatenate
        $confirmMessage = __(
            'Are you sure you want to permanently delete this listing from your Etsy store? ' .
            'This will immediately free up a slot in your sync limit.'
        );

        return [
            'label' => __('Delete from Etsy'),
            'class' => 'action-secondary',
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s')",
                $confirmMessage,
                $this->urlBuilder->getUrl(
                    'blueprint3d_etsy/product/deleteEtsy',
                    ['id' => $product->getId()]
                )
            ),
            'sort_order' => 20
        ];
    }
}
