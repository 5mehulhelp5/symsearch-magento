<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Test\Unit\Model\Indexer;

use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Model\Indexer\TextBuilder;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use PHPUnit\Framework\TestCase;

class TextBuilderTest extends TestCase
{
    private TextBuilder $builder;

    protected function setUp(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getEmbedAttributes')->willReturn(['name', 'description', 'manufacturer']);
        $this->builder = new TextBuilder($config);
    }

    private function mockProduct(array $data, array $sourceTexts = []): Product
    {
        $resource = $this->createMock(ProductResource::class);
        $resource->method('getAttribute')->willReturnCallback(
            function (string $code) use ($sourceTexts) {
                $attribute = $this->createMock(AbstractAttribute::class);
                $attribute->method('usesSource')->willReturn(isset($sourceTexts[$code]));
                return $attribute;
            }
        );

        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getResource', 'getAttributeText'])
            ->getMock();
        $product->method('getResource')->willReturn($resource);
        $product->method('getData')->willReturnCallback(fn (string $code) => $data[$code] ?? null);
        $product->method('getAttributeText')->willReturnCallback(fn (string $code) => $sourceTexts[$code] ?? false);

        return $product;
    }

    public function testStripsHtmlAndJoinsAttributes(): void
    {
        $product = $this->mockProduct([
            'name'        => 'Atomic Habits',
            'description' => '<p>Tiny changes, <b>remarkable</b>   results.</p>',
        ]);

        $this->assertSame("Atomic Habits\nTiny changes, remarkable results.", $this->builder->build($product));
    }

    public function testUsesSourceLabelsForSelectAttributes(): void
    {
        $product = $this->mockProduct(['name' => 'Book', 'manufacturer' => '42'], ['manufacturer' => 'Penguin']);

        $this->assertSame("Book\nPenguin", $this->builder->build($product));
    }

    public function testSkipsEmptyValues(): void
    {
        $product = $this->mockProduct(['name' => 'Book', 'description' => '   ']);

        $this->assertSame('Book', $this->builder->build($product));
    }
}
