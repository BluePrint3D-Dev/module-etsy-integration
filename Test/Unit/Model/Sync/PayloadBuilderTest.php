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

use BluePrint3D\EtsyIntegration\Model\Sync\PayloadBuilder;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * getEtsyTags() and formatDescription() are private - reached via
 * ReflectionMethod rather than changing their visibility, so the tests
 * don't force a change to the class's public API just to be testable.
 */
class PayloadBuilderTest extends TestCase
{
    private PayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PayloadBuilder(
            $this->createMock(StockRegistryInterface::class),
            $this->createMock(CategoryRepositoryInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function invokePrivate(string $method, array $args)
    {
        $reflection = new \ReflectionMethod(PayloadBuilder::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($this->builder, $args);
    }

    // ---------- getCalculatedPrice() ----------
    // Public specifically so EtsyIntegrationPro's ApplyPriceRulePlugin can
    // intercept it - covered here to prove the base figure it works from
    // is right before any markup is applied on top.

    public function testCalculatedPriceUsesFinalPriceWhenAvailable(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getFinalPrice')->willReturn(17.5);
        $product->method('getPrice')->willReturn(25.0);

        $this->assertSame(17.5, $this->builder->getCalculatedPrice($product));
    }

    public function testCalculatedPriceFallsBackToPriceWhenFinalPriceIsZero(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getFinalPrice')->willReturn(0.0);
        $product->method('getPrice')->willReturn(25.0);

        $this->assertSame(25.0, $this->builder->getCalculatedPrice($product));
    }

    // ---------- getEtsyTags() ----------

    public function testTagsAreDerivedFromMetaKeywordsWhenPresent(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->with('meta_keyword')->willReturn('Handmade, Wedding Gift!!, Rustic-Decor');

        $tags = $this->invokePrivate('getEtsyTags', [$product]);

        $this->assertSame(['handmade', 'wedding gift', 'rustic decor'], $tags);
    }

    public function testTagsFallBackToProductNameWhenNoMetaKeyword(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->with('meta_keyword')->willReturn('');
        $product->method('getName')->willReturn('Oak Wood Coasters');

        $tags = $this->invokePrivate('getEtsyTags', [$product]);

        $this->assertSame(['oak wood coasters', 'oak', 'wood', 'coasters'], $tags);
    }

    public function testTagsAreCappedAtTwentyCharacters(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->with('meta_keyword')->willReturn('ThisKeywordIsDefinitelyOverTwentyCharacters');

        $tags = $this->invokePrivate('getEtsyTags', [$product]);

        $this->assertSame(['thiskeywordisdefinit'], $tags);
        $this->assertSame(20, strlen($tags[0]));
    }

    public function testTagsAreDeduplicatedCaseInsensitively(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->with('meta_keyword')->willReturn('Gift, GIFT, gift');

        $tags = $this->invokePrivate('getEtsyTags', [$product]);

        $this->assertSame(['gift'], $tags);
    }

    public function testTagsAreCappedAtThirteen(): void
    {
        $keywords = implode(',', array_map(static fn ($i) => "tag$i", range(1, 20)));
        $product = $this->createMock(Product::class);
        $product->method('getData')->with('meta_keyword')->willReturn($keywords);

        $tags = $this->invokePrivate('getEtsyTags', [$product]);

        $this->assertCount(13, $tags);
        $this->assertSame('tag13', $tags[12]);
    }

    public function testHyphensAndUnderscoresAreTreatedAsWordBoundaries(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->with('meta_keyword')->willReturn('Rustic-Decor, gift_wrap');

        $tags = $this->invokePrivate('getEtsyTags', [$product]);

        $this->assertSame(['rustic decor', 'gift wrap'], $tags);
    }

    // ---------- formatDescription() ----------

    public function testDescriptionDecodesHtmlEntities(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getDescription')->willReturn('<p>Hello &amp; welcome</p>');

        $result = $this->invokePrivate('formatDescription', [$product]);

        $this->assertStringContainsString('Hello & welcome', $result);
    }

    public function testDescriptionStripsStyleAndScriptBlocks(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getDescription')->willReturn(
            '<style>.x{color:red}</style><p>Real content</p><script>alert(1)</script>'
        );

        $result = $this->invokePrivate('formatDescription', [$product]);

        $this->assertSame('Real content', $result);
    }

    public function testDescriptionStripsWidgetShortcodes(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getDescription')->willReturn('Before {{widget type="Foo"}} After');

        $result = $this->invokePrivate('formatDescription', [$product]);

        $this->assertSame('Before After', $result);
    }

    public function testDescriptionFallsBackToProductNameWhenEmpty(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getDescription')->willReturn('');
        $product->method('getName')->willReturn('Fallback Product Name');

        $result = $this->invokePrivate('formatDescription', [$product]);

        $this->assertSame('Fallback Product Name', $result);
    }

    public function testDescriptionConvertsBreaksToNewlines(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getDescription')->willReturn('Line one<br>Line two<br/>Line three');

        $result = $this->invokePrivate('formatDescription', [$product]);

        $this->assertSame("Line one\nLine two\nLine three", $result);
    }

    public function testDescriptionSeparatesListItemsWithNewlines(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getDescription')->willReturn('<ul><li>One</li><li>Two</li><li>Three</li></ul>');

        $result = $this->invokePrivate('formatDescription', [$product]);

        $this->assertSame("\xE2\x80\xA2 One\n\xE2\x80\xA2 Two\n\xE2\x80\xA2 Three", $result);
    }
}
