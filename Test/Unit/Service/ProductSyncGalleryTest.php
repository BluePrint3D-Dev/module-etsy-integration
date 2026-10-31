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

    /** @var ProductAction|MockObject */
    private $productAction;

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
        $this->productAction = $this->createMock(ProductAction::class);

        $this->sync = new ProductSync(
            $this->client,
            $this->createMock(ScopeConfigInterface::class),
            $filesystem,
            $this->productAction,
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

    /**
     * A product whose stored hash differs, so syncGalleryImages() does a refresh.
     *
     * @param string[] $files
     */
    private function staleProduct(array $files): Product
    {
        $product = $this->product($files);
        $product->method('getId')->willReturn(7);
        $product->method('getData')->with('etsy_images_hash')->willReturn('stale');
        $this->processor->method('getSignature')->willReturn('');
        $this->processor->method('prepare')->willReturnArgument(1);

        return $product;
    }

    /**
     * Wire the client mock: the listing starts with $existing images, uploads go through $onUpload
     * (return normally to accept, throw to refuse), and every call is recorded in order.
     *
     * @param int $existing
     * @param callable $onUpload fn(int $rank, int $onListing): void
     * @return \ArrayObject the ordered log: "upload:N" and "delete:ID"
     */
    private function listing(int $existing, callable $onUpload): \ArrayObject
    {
        $log = new \ArrayObject();
        $onListing = $existing;
        $images = [];
        for ($i = 1; $i <= $existing; $i++) {
            $images[] = ['listing_image_id' => 100 + $i];
        }

        $this->client->method('request')->willReturnCallback(
            function (string $endpoint, string $method = 'GET') use ($log, &$onListing, $images): array {
                if ($method === 'DELETE') {
                    $log[] = 'delete:' . basename($endpoint);
                    $onListing--;
                    return [];
                }

                return ['images' => $images];
            }
        );
        $this->client->method('uploadImage')->willReturnCallback(
            function (string $endpoint, string $path, int $rank) use ($log, &$onListing, $onUpload): array {
                $onUpload($rank, $onListing);
                $log[] = 'upload:' . $rank;
                $onListing++;
                return [];
            }
        );

        return $log;
    }

    private function files(int $count): array
    {
        $files = [];
        for ($i = 1; $i <= $count; $i++) {
            $files[] = "/img{$i}.jpg";
        }

        return $files;
    }

    public function testNoMoreThanTwentyImagesAreSent(): void
    {
        $log = $this->listing(0, static function (): void {
        });

        $this->call('syncGalleryImages', [$this->staleProduct($this->files(25)), 1, 2]);

        $uploads = array_values(array_filter((array)$log, static fn ($e) => str_starts_with($e, 'upload:')));
        $this->assertCount(20, $uploads);
        $this->assertSame('upload:20', end($uploads));
    }

    public function testPreviousImagesAreDeletedOnlyAfterTheNewSetIsUploaded(): void
    {
        $log = $this->listing(2, static function (): void {
        });
        $this->productAction->expects($this->once())->method('updateAttributes');

        $this->call('syncGalleryImages', [$this->staleProduct($this->files(3)), 1, 2]);

        $this->assertSame(
            ['upload:1', 'upload:2', 'upload:3', 'delete:101', 'delete:102'],
            (array)$log
        );
    }

    public function testAFullListingHasItsReplacedImagesRemovedToMakeRoom(): void
    {
        // 4 on the listing; Etsy refuses once it holds 10 images (4 old + 6 new).
        $log = $this->listing(4, static function (int $rank, int $onListing): void {
            if ($onListing >= 10) {
                throw new \RuntimeException('Etsy: listing image limit reached');
            }
        });
        $this->productAction->expects($this->once())->method('updateAttributes');

        $this->call('syncGalleryImages', [$this->staleProduct($this->files(8)), 1, 2]);

        $this->assertSame(
            [
                'upload:1', 'upload:2', 'upload:3', 'upload:4', 'upload:5', 'upload:6',
                'delete:101', 'delete:102', 'delete:103', 'delete:104',
                'upload:7', 'upload:8',
            ],
            (array)$log,
            'old images go only when Etsy says full, each is deleted once, and all 8 new images land'
        );
    }

    public function testStopsGracefullyWhenEtsyWillNotTakeMoreThanItsOldLimit(): void
    {
        // Nothing to remove, and Etsy (still on its old limit) refuses an 11th image.
        $log = $this->listing(0, static function (int $rank, int $onListing): void {
            if ($onListing >= 10) {
                throw new \RuntimeException('Etsy: listing image limit reached');
            }
        });
        $this->productAction->expects($this->once())->method('updateAttributes');

        $this->call('syncGalleryImages', [$this->staleProduct($this->files(14)), 1, 2]);

        $uploads = array_filter((array)$log, static fn ($e) => str_starts_with($e, 'upload:'));
        $this->assertCount(10, $uploads, 'keeps what Etsy accepted instead of failing every sync');
    }

    public function testAFailureOnASmallListingIsStillAnError(): void
    {
        $this->listing(0, static function (int $rank): void {
            if ($rank === 2) {
                throw new \RuntimeException('Etsy said no');
            }
        });
        $this->productAction->expects($this->never())->method('updateAttributes');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Etsy said no');
        $this->call('syncGalleryImages', [$this->staleProduct($this->files(3)), 1, 2]);
    }

    public function testASecondFailureAfterMakingRoomIsReported(): void
    {
        $this->listing(4, static function (int $rank, int $onListing): void {
            if ($onListing >= 10 || $rank === 7) {
                throw new \RuntimeException('Etsy still says no');
            }
        });
        $this->productAction->expects($this->never())->method('updateAttributes');

        $this->expectException(\RuntimeException::class);
        $this->call('syncGalleryImages', [$this->staleProduct($this->files(8)), 1, 2]);
    }
}
