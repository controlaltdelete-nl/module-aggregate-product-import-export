<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\AggregateProductImportExport\Test\Integration;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product as CatalogProduct;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\CatalogImportExport\Model\Import\Product;
use Magento\CatalogImportExport\Model\Import\Product\SkuStorage;
use Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType;
use Magento\CatalogImportExport\Model\Import\ProductFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use Magento\ImportExport\Model\Import\Source\CsvFactory;
use Magento\ImportExport\Model\ResourceModel\Import\Data;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

#[
    DbIsolation(true),
    AppArea('adminhtml'),
]
class ImportAggregateChildrenTest extends TestCase
{
    private ?ObjectManagerInterface $objectManager = null;
    private ?Filesystem $filesystem = null;
    private ?ProductFactory $productImporterFactory = null;
    private ?DataFixtureStorage $fixtures = null;
    /** @var string[] */
    private ?array $importedProducts = [];

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->filesystem = $this->objectManager->get(Filesystem::class);
        $this->productImporterFactory = $this->objectManager->get(ProductFactory::class);
        $this->fixtures = DataFixtureStorageManager::getStorage();
        $this->resetImportState();
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'child-simple-1', 'price' => 10.0]),
        DataFixture(ProductFixture::class, ['sku' => 'child-simple-2', 'price' => 20.0]),
    ]
    public function testImportCreatesChildLinks(): void
    {
        $importer = $this->createImporter(__DIR__ . '/_files/aggregate_import_existing_children.csv');
        $errors = $importer->validateData();
        $this->assertEquals(0, $errors->getErrorsCount(), 'Import validation should pass');
        $importer->importData();
        $this->importedProducts = ['agg-parent-1'];

        $links = $this->getAggregateLinks('agg-parent-1');
        $this->assertCount(2, $links, 'Should have 2 child links');

        $linksByChildSku = $this->indexLinksByChildSku($links);
        $this->assertArrayHasKey('child-simple-1', $linksByChildSku);
        $this->assertArrayHasKey('child-simple-2', $linksByChildSku);
        $this->assertEquals(2.0, (float)$linksByChildSku['child-simple-1']['qty']);
        $this->assertEquals(3.0, (float)$linksByChildSku['child-simple-2']['qty']);
    }

    public function testImportWithChildrenInSameFile(): void
    {
        $importer = $this->createImporter(__DIR__ . '/_files/aggregate_import_basic.csv');
        $errors = $importer->validateData();
        $this->assertEquals(0, $errors->getErrorsCount(), 'Import validation should pass');
        $importer->importData();
        $this->importedProducts = ['child-simple-1', 'child-simple-2', 'agg-parent-1'];

        $links = $this->getAggregateLinks('agg-parent-1');
        $actualQtyBySku = array_map(
            static fn (array $link): float => (float)$link['qty'],
            $this->indexLinksByChildSku($links)
        );
        ksort($actualQtyBySku);
        $this->assertSame(
            ['child-simple-1' => 2.0, 'child-simple-2' => 3.0],
            $actualQtyBySku,
            'Same-file children should be linked with their configured quantities'
        );
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'child-simple-is-removed', 'price' => 10.0], as: 'child'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-parent-empty',
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 2],
            ],
        ], as: 'aggregate'),
    ]
    public function testImportEmptyColumnRemovesAllLinks(): void
    {
        $aggregate = $this->fixtures->get('aggregate');
        $linksBefore = $this->getAggregateLinksById((int)$aggregate->getId());
        $this->assertNotEmpty($linksBefore, 'Should have links before import');

        $importer = $this->createImporter(__DIR__ . '/_files/aggregate_import_empty_children.csv');
        $errors = $importer->validateData();
        $this->assertEquals(0, $errors->getErrorsCount());
        $importer->importData();

        $linksAfter = $this->getAggregateLinksById((int)$aggregate->getId());
        $this->assertEmpty($linksAfter, 'All links should be removed');
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'child-simple-preserved', 'price' => 10.0], as: 'child'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-parent-omitted',
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 2],
            ],
        ], as: 'aggregate'),
    ]
    public function testImportOmittedColumnPreservesLinks(): void
    {
        $aggregate = $this->fixtures->get('aggregate');
        $linksBefore = $this->getAggregateLinksById((int)$aggregate->getId());
        $this->assertNotEmpty($linksBefore, 'Should have links before import');

        $importer = $this->createImporter(__DIR__ . '/_files/aggregate_import_no_column.csv');
        $errors = $importer->validateData();
        $this->assertEquals(0, $errors->getErrorsCount());
        $importer->importData();

        $linksAfter = $this->getAggregateLinksById((int)$aggregate->getId());
        $this->assertCount(count($linksBefore), $linksAfter, 'Links should be preserved when column is omitted');
        $linksAfterBySku = $this->indexLinksByChildSku($linksAfter);
        $this->assertArrayHasKey(
            'child-simple-preserved',
            $linksAfterBySku,
            'Existing child link should survive when the column is omitted'
        );
        $this->assertSame(2.0, (float)$linksAfterBySku['child-simple-preserved']['qty'], 'Link qty should be unchanged');
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'child-simple-existing', 'price' => 10.0], as: 'child'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-parent-update',
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 2],
            ],
        ]),
    ]
    public function testImportUpdatesExistingLinkQty(): void
    {
        $importer = $this->createImporter(__DIR__ . '/_files/aggregate_import_update_qty.csv');
        $errors = $importer->validateData();
        $this->assertEquals(0, $errors->getErrorsCount());
        $importer->importData();

        $links = $this->getAggregateLinks('agg-parent-update');
        $this->assertCount(1, $links);
        $linksByChildSku = $this->indexLinksByChildSku($links);
        $this->assertEquals(5.0, (float)$linksByChildSku['child-simple-existing']['qty']);
    }

    public function testImportSkipsInvalidChildSku(): void
    {
        $importer = $this->createImporter(__DIR__ . '/_files/aggregate_import_invalid_child_sku.csv');
        $errors = $importer->validateData();
        $this->assertEquals(0, $errors->getErrorsCount(), 'Validation passes — unknown SKU is silently skipped during save');
        $importer->importData();
        $this->importedProducts = ['agg-parent-1'];

        $links = $this->getAggregateLinks('agg-parent-1');
        $this->assertEmpty($links, 'No links should be created for nonexistent child SKU');
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'child-simple-1', 'price' => 10.0])]
    public function testImportRejectsQtyBelowOne(): void
    {
        $importer = $this->createImporter(__DIR__ . '/_files/aggregate_import_invalid_qty.csv');
        $errors = $importer->validateData();
        $this->assertGreaterThan(0, $errors->getErrorsCount(), 'Import validation should fail for qty < 1');
        $this->assertValidationErrorMessage($errors, 'Aggregate child qty must be numeric and >= 1');
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'child-simple-1', 'price' => 10.0])]
    public function testImportRejectsDuplicateChildSku(): void
    {
        $importer = $this->createImporter(__DIR__ . '/_files/aggregate_import_duplicate_child.csv');
        $errors = $importer->validateData();
        $this->assertGreaterThan(0, $errors->getErrorsCount(), 'Import validation should fail for duplicate child SKU');
        $this->assertValidationErrorMessage($errors, 'Duplicate child SKU in aggregate_children');
    }

    private function assertValidationErrorMessage(
        ProcessingErrorAggregatorInterface $errors,
        string $expectedMessage
    ): void {
        $messages = array_map(
            static fn ($error): string => (string)$error->getErrorMessage(),
            $errors->getAllErrors()
        );
        $matches = array_filter(
            $messages,
            static fn (string $message): bool => str_contains($message, $expectedMessage)
        );
        $this->assertNotEmpty(
            $matches,
            sprintf('Expected validation message "%s", got: %s', $expectedMessage, implode('; ', $messages))
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

    /**
     * @return array<int, array{parent_id: string, product_id: string, qty: string, child_sku: string}>
     */
    private function getAggregateLinks(string $parentSku): array
    {
        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        try {
            $product = $productRepository->get($parentSku);
        } catch (NoSuchEntityException $e) {
            return [];
        }

        return $this->getAggregateLinksById((int)$product->getId());
    }

    /**
     * @return array<int, array{parent_id: string, product_id: string, qty: string, child_sku: string}>
     */
    private function getAggregateLinksById(int $parentId): array
    {
        $connection = $this->objectManager->get(ResourceConnection::class)->getConnection();
        $select = $connection->select()
            ->from(
                ['link' => $connection->getTableName('catalog_product_aggregate_link')],
                ['parent_id', 'product_id', 'qty']
            )
            ->join(
                ['cpe' => $connection->getTableName('catalog_product_entity')],
                'cpe.entity_id = link.product_id',
                ['child_sku' => 'cpe.sku']
            )
            ->where('link.parent_id = ?', $parentId);

        return $connection->fetchAll($select);
    }

    /**
     * @param array<int, array{child_sku: string, qty: string}> $links
     * @return array<string, array{child_sku: string, qty: string}>
     */
    private function indexLinksByChildSku(array $links): array
    {
        $indexed = [];
        foreach ($links as $link) {
            $indexed[$link['child_sku']] = $link;
        }
        return $indexed;
    }

    protected function tearDown(): void
    {
        if (!empty($this->importedProducts)) {
            /** @var ProductRepositoryInterface $productRepository */
            $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
            $registry = $this->objectManager->get(Registry::class);
            $registry->unregister('isSecureArea');
            $registry->register('isSecureArea', true);

            foreach ($this->importedProducts as $sku) {
                try {
                    $productRepository->deleteById($sku);
                } catch (NoSuchEntityException $e) {
                    // product already deleted
                }
            }

            $registry->unregister('isSecureArea');
            $registry->register('isSecureArea', false);
        }

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
