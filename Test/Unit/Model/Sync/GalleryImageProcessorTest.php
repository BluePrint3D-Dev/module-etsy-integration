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
namespace BluePrint3D\EtsyIntegration\Test\Unit\Model\Sync;

use BluePrint3D\EtsyIntegration\Model\Sync\GalleryImageProcessor;
use Magento\Catalog\Model\Product;
use PHPUnit\Framework\TestCase;

/**
 * The free tier must pass images straight through: any change here would alter what free
 * merchants upload to Etsy, or their stored gallery hash (forcing a pointless re-upload).
 */
class GalleryImageProcessorTest extends TestCase
{
    public function testPrepareReturnsTheOriginalPathForEveryRank(): void
    {
        $processor = new GalleryImageProcessor();
        $product = $this->createMock(Product::class);

        $this->assertSame('/media/a.jpg', $processor->prepare($product, '/media/a.jpg', 1));
        $this->assertSame('/media/b.jpg', $processor->prepare($product, '/media/b.jpg', 4));
    }

    public function testSignatureIsEmpty(): void
    {
        $this->assertSame('', (new GalleryImageProcessor())->getSignature($this->createMock(Product::class)));
    }
}
