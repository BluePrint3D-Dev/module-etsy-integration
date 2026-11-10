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
namespace BluePrint3D\EtsyIntegration\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Class ResetAutoRenewObserver
 * Auto-renew only means something for a product that syncs to Etsy: when sync is off, clear it
 * before the product is saved, so it is written in the same save rather than a second one.
 */
class ResetAutoRenewObserver implements ObserverInterface
{
    /**
     * Clear auto-renew on products that do not sync to Etsy
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $product = $observer->getEvent()->getProduct();
        if ($product && !(bool)$product->getData('etsy_sync_enabled') && $product->getData('etsy_auto_renew')) {
            $product->setData('etsy_auto_renew', 0);
        }
    }
}
