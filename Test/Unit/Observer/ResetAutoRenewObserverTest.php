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
namespace BluePrint3D\EtsyIntegration\Test\Unit\Observer;

use BluePrint3D\EtsyIntegration\Observer\ResetAutoRenewObserver;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

class ResetAutoRenewObserverTest extends TestCase
{
    private function observe(array $data): DataObject
    {
        $product = new DataObject($data);
        (new ResetAutoRenewObserver())->execute(new Observer(['event' => new Event(['product' => $product])]));

        return $product;
    }

    public function testAutoRenewIsClearedWhenSyncIsOff(): void
    {
        $product = $this->observe(['etsy_sync_enabled' => 0, 'etsy_auto_renew' => 1]);

        $this->assertSame(0, $product->getData('etsy_auto_renew'));
    }

    public function testAutoRenewIsKeptWhenSyncIsOn(): void
    {
        $product = $this->observe(['etsy_sync_enabled' => 1, 'etsy_auto_renew' => 1]);

        $this->assertSame(1, $product->getData('etsy_auto_renew'));
    }

    public function testNothingIsSetWhenAutoRenewWasNeverOn(): void
    {
        $this->assertFalse($this->observe(['etsy_sync_enabled' => 0])->hasData('etsy_auto_renew'));
    }
}
