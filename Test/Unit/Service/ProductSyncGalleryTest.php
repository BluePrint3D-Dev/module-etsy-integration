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
namespace BluePrint3D\EtsyIntegration\Test\Unit\Service;

use BluePrint3D\EtsyIntegration\Model\Sync\GalleryImageProcessor;
use BluePrint3D\EtsyIntegration\Model\Sync\InventoryPayloadBuilder;
use BluePrint3D\EtsyIntegration\Model\Sync\PayloadBuilder;
use BluePrint3D\EtsyIntegration\Service\EtsyClient;
use BluePrint3D\EtsyIntegration\Service\EtsyCustomOptionManager;
use BluePrint3D\EtsyIntegration\Service\ProductSync;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Data\Collection;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The gallery hash and the upload loop are private - reached via ReflectionMethod, as in
 * PayloadBuilderTest, so the tests don't widen the class's public API.
 */
class ProductSyncGalleryTest extends TestCase
{
    /** @var GalleryImageProcessor|MockObject */
    private $processor;

    /** @var EtsyClient|MockObject */
    private $client;

    private ProductSync $sync;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/etsy_sync_test_' . uniqid();
        mkdir($this->dir);

        $media = $this->createMock(ReadInterface::class);
        $media->method('isFile')->willReturn(true);
        $media->method('getAbsolutePath')->willReturnCallback(
            function ($path = null): string {
                return $this->dir . '/' . basename((string)$path);
            }
        );
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturn($media);

        $this->processor = $this->createMock(GalleryImageProcessor::class);
        $this->client = $this->createMock(EtsyClient::class);

        $this->sync = new ProductSync(
            $this->client,
            $this->createMock(ScopeConfigInterface::class),
            $filesystem,
            $this->createMock(ProductAction::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(EtsyCustomOptionManager::class),
            $this->createMock(PayloadBuilder::class),
            $this->createMock(InventoryPayloadBuilder::class),
            $this->createMock(StockRegistryInterface::class),
            $this->processor
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function product(array $files): Product
    {
        $items = [];
        foreach ($files as $position => $file) {
            $items[] = new DataObject(['file' => $file, 'position' => $position]);
        }
        // Newest-position-first on purpose: the sync must sort by position itself.
        $items = array_reverse($items);

        $collection = $this->createMock(Collection::class);
        $collection->method('getSize')->willReturn(count($items));
        $collection->method('getItems')->willReturn($items);

        $product = $this->createMock(Product::class);
        $product->method('getMediaGalleryImages')->willReturn($collection);

        return $product;
    }

    private function call(string $method, array $args)
    {
        $reflection = new \ReflectionMethod(ProductSync::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->sync, $args);
    }

    public function testHashIsUnchangedWhenNoProcessorContributesASignature(): void
    {
        $this->processor->method('getSignature')->willReturn('');

        $hash = $this->call('getGalleryImagesHash', [$this->product(['/a.jpg', '/b.jpg'])]);

        // Must equal the pre-processor formula, or free users would re-upload every image.
        $this->assertSame(md5('/a.jpg|/b.jpg'), $hash);
    }

    public function testSignatureIsFoldedIntoTheHash(): void
    {
        $this->processor->method('getSignature')->willReturn('crop-v1:auto');

        $hash = $this->call('getGalleryImagesHash', [$this->product(['/a.jpg', '/b.jpg'])]);

        $this->assertSame(md5('/a.jpg|/b.jpg#crop-v1:auto'), $hash);
    }

    public function testDerivedFileIsUploadedAndThenDeleted(): void
    {
        $original = $this->dir . '/a.jpg';
        $derived = $this->dir . '/derived.jpg';
        file_put_contents($original, 'orig');
        file_put_contents($derived, 'crop');

        $this->processor->expects($this->once())->method('prepare')
            ->with($this->anything(), $original, 1)->willReturn($derived);
        $this->client->expects($this->once())->method('uploadImage')
            ->with('shops/1/listings/2/images', $derived, 1);

        $this->call('uploadGalleryImages', [$this->product(['/a.jpg']), 1, 2]);

        $this->assertFileDoesNotExist($derived);
        $this->assertFileExists($original, 'the Magento gallery file is never touched');
    }

    public function testDerivedFileIsDeletedEvenWhenTheUploadFails(): void
    {
        $original = $this->dir . '/a.jpg';
        $derived = $this->dir . '/derived.jpg';
        file_put_contents($original, 'orig');
        file_put_contents($derived, 'crop');

        $this->processor->method('prepare')->willReturn($derived);
        $this->client->method('uploadImage')->willThrowException(new \RuntimeException('Etsy said no'));

        try {
            $this->call('uploadGalleryImages', [$this->product(['/a.jpg']), 1, 2]);
            $this->fail('The upload error should propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Etsy said no', $e->getMessage());
        }

        $this->assertFileDoesNotExist($derived);
        $this->assertFileExists($original);
    }

    public function testUntouchedImageIsUploadedAsIs(): void
    {
        $original = $this->dir . '/a.jpg';
        file_put_contents($original, 'orig');

        $this->processor->method('prepare')->willReturnArgument(1);
        $this->client->expects($this->once())->method('uploadImage')->with('shops/1/listings/2/images', $original, 1);

        $this->call('uploadGalleryImages', [$this->product(['/a.jpg']), 1, 2]);

        $this->assertFileExists($original, 'must not delete the original when nothing was derived');
    }
}
