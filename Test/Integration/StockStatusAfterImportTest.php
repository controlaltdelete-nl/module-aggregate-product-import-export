<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProductImportExport\Test\Integration;

use Magento\Catalog\Model\Product as CatalogProduct;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\CatalogImportExport\Model\Import\Product;
use Magento\CatalogImportExport\Model\Import\Product\SkuStorage;
use Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType;
use Magento\CatalogImportExport\Model\Import\ProductFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\ObjectManagerInterface;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\Source\CsvFactory;
use Magento\ImportExport\Model\ResourceModel\Import\Data;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

#[
    DbIsolation(true),
    AppArea('adminhtml'),
]
class StockStatusAfterImportTest extends TestCase
{
    private ?ObjectManagerInterface $objectManager = null;
    private ?Filesystem $filesystem = null;
    private ?ProductFactory $productImporterFactory = null;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->filesystem = $this->objectManager->get(Filesystem::class);
        $this->productImporterFactory = $this->objectManager->get(ProductFactory::class);
        $this->resetImportState();
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'stock-child-1', 'price' => 10.0], as: 'child'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'stock-agg-1',
            'price' => 30.0,
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 2],
            ],
        ]),
    ]
    public function testParentStockStatusUpdatedAfterChildImport(): void
    {
        /** @var StockRegistryInterface $stockRegistry */
        $stockRegistry = $this->objectManager->get(StockRegistryInterface::class);
        $parentStockBefore = $stockRegistry->getStockItemBySku('stock-agg-1');
        $this->assertTrue((bool)$parentStockBefore->getIsInStock(), 'Parent should be in stock initially');

        $importer = $this->createImporter(__DIR__ . '/_files/stock_import_child_out_of_stock.csv');
        $errors = $importer->validateData();
        $this->assertEquals(0, $errors->getErrorsCount(), 'Stock import validation should pass');
        $importer->importData();
        $this->objectManager->get(StockRegistryStorage::class)->clean();

        $parentStockAfter = $stockRegistry->getStockItemBySku('stock-agg-1');
        $this->assertFalse(
            (bool)$parentStockAfter->getIsInStock(),
            'Parent should be out of stock after child goes out of stock'
        );
    }

    private function createImporter(string $pathToFile, string $behavior = Import::BEHAVIOR_ADD_UPDATE): Product
    {
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->get(CsvFactory::class)->create([
            'file' => $pathToFile,
            'directory' => $directory,
        ]);

        $productImporter = $this->productImporterFactory->create();
        $productImporter->setParameters([
            'behavior' => $behavior,
            'entity' => CatalogProduct::ENTITY,
        ])->setSource($source);

        return $productImporter;
    }

    protected function tearDown(): void
    {
        $this->objectManager->get(StockRegistryStorage::class)->clean();
        $this->resetImportState();
    }

    private function resetImportState(): void
    {
        /** @var Data $dataSourceModel */
        $dataSourceModel = $this->objectManager->create(Data::class);
        $dataSourceModel->cleanBunches();
        $this->objectManager->get(SkuStorage::class)->reset();
        AbstractType::$commonAttributesCache = [];
        AbstractType::$invAttributesCache = [];
        AbstractType::$attributeCodeToId = [];
    }
}
