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
use Magento\CatalogImportExport\Model\Export\Product as ExportProduct;
use Magento\CatalogImportExport\Model\Import\Product;
use Magento\CatalogImportExport\Model\Import\Product\SkuStorage;
use Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType;
use Magento\CatalogImportExport\Model\Import\ProductFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\ObjectManagerInterface;
use Magento\ImportExport\Model\Export\Adapter\Csv as ExportCsv;
use Magento\ImportExport\Model\Import;
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
class ExportAggregateChildrenTest extends TestCase
{
    private ?ObjectManagerInterface $objectManager = null;
    private ?Filesystem $filesystem = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->filesystem = $this->objectManager->get(Filesystem::class);
        $this->fixtures = DataFixtureStorageManager::getStorage();
        $this->resetImportState();
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'export-simple-1', 'price' => 10.0])]
    public function testExportIncludesAggregateChildrenColumn(): void
    {
        $csvContent = $this->runExport();
        $headers = $this->parseCsvHeaders($csvContent);

        $this->assertContains('aggregate_children', $headers, 'Export should include aggregate_children column');
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'export-child-1', 'price' => 10.0], as: 'child1'),
        DataFixture(ProductFixture::class, ['sku' => 'export-child-2', 'price' => 20.0], as: 'child2'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'export-agg-1',
            'price' => 30.0,
            '_children' => [
                ['product_id' => '$child1.id$', 'qty' => 2],
                ['product_id' => '$child2.id$', 'qty' => 3],
            ],
        ]),
    ]
    public function testExportFormatsChildrenCorrectly(): void
    {
        $csvContent = $this->runExport();
        $rows = $this->parseCsv($csvContent);
        $aggregateRow = $this->findRowBySku($rows, 'export-agg-1');

        $this->assertNotNull($aggregateRow, 'Aggregate product should appear in export');

        $parsedChildren = $this->parseExportedChildren((string)($aggregateRow['aggregate_children'] ?? ''));
        $this->assertEquals(
            [
                'export-child-1' => 2.0,
                'export-child-2' => 3.0,
            ],
            $parsedChildren,
            'Exported aggregate_children must contain exactly the linked children with their qtys'
        );
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'export-plain-simple', 'price' => 10.0])]
    public function testExportNonAggregateProductHasEmptyColumn(): void
    {
        $csvContent = $this->runExport();
        $rows = $this->parseCsv($csvContent);
        $simpleRow = $this->findRowBySku($rows, 'export-plain-simple');

        $this->assertNotNull($simpleRow, 'Simple product should appear in export');
        $this->assertArrayHasKey('aggregate_children', $simpleRow, 'Column must exist for every exported row');
        $this->assertSame(
            '',
            $simpleRow['aggregate_children'],
            'Non-aggregate product should have empty aggregate_children'
        );
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'rt-child-1', 'price' => 10.0], as: 'child1'),
        DataFixture(ProductFixture::class, ['sku' => 'rt-child-2', 'price' => 20.0], as: 'child2'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'rt-agg-1',
            'price' => 30.0,
            '_children' => [
                ['product_id' => '$child1.id$', 'qty' => 2],
                ['product_id' => '$child2.id$', 'qty' => 4],
            ],
        ]),
    ]
    public function testExportImportRoundTrip(): void
    {
        $linksBefore = $this->getAggregateLinks('rt-agg-1');

        // The export must keep producing aggregate_children in the format the static import fixture
        // expects; assert that here so a drift in export format still fails the round-trip.
        $aggregateRow = $this->findRowBySku($this->parseCsv($this->runExport()), 'rt-agg-1');
        $this->assertNotNull($aggregateRow, 'Aggregate product should appear in export');
        $this->assertEquals(
            ['rt-child-1' => 2.0, 'rt-child-2' => 4.0],
            $this->parseExportedChildren((string)($aggregateRow['aggregate_children'] ?? '')),
            'Exported aggregate_children must match the round-trip import fixture'
        );

        $importer = $this->createImporter(__DIR__ . '/_files/aggregate_import_roundtrip.csv');
        $errors = $importer->validateData();
        $this->assertEquals(0, $errors->getErrorsCount(), 'Round-trip re-import validation should pass');
        $importer->importData();

        $linksAfter = $this->getAggregateLinks('rt-agg-1');

        $this->assertCount(count($linksBefore), $linksAfter, 'Link count should match after round-trip');

        $beforeByChildSku = $this->indexLinksByChildSku($linksBefore);
        $afterByChildSku = $this->indexLinksByChildSku($linksAfter);

        foreach ($beforeByChildSku as $childSku => $beforeLink) {
            $this->assertArrayHasKey($childSku, $afterByChildSku, "Child $childSku should exist after round-trip");
            $this->assertEquals(
                (float)$beforeLink['qty'],
                (float)$afterByChildSku[$childSku]['qty'],
                "Qty for $childSku should match after round-trip"
            );
        }
    }

    private function createImporter(string $pathToFile, string $behavior = Import::BEHAVIOR_ADD_UPDATE): Product
    {
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $source = $this->objectManager->get(CsvFactory::class)->create([
            'file' => $pathToFile,
            'directory' => $directory,
        ]);

        $productImporter = $this->objectManager->get(ProductFactory::class)->create();
        $productImporter->setParameters([
            'behavior' => $behavior,
            'entity' => CatalogProduct::ENTITY,
        ])->setSource($source);

        return $productImporter;
    }

    /**
     * @return array<string, float> Map of child SKU to qty parsed from an exported aggregate_children value.
     */
    private function parseExportedChildren(string $value): array
    {
        $children = [];
        foreach (explode(Import::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR, $value) as $child) {
            $parts = explode('=', $child, 2);
            $this->assertCount(2, $parts, 'Each child should be sku=qty format');
            $children[$parts[0]] = (float)$parts[1];
        }

        return $children;
    }

    private function runExport(): string
    {
        /** @var ExportProduct $export */
        $export = $this->objectManager->create(ExportProduct::class);
        $export->setParameters([]);

        /** @var ExportCsv $writer */
        $writer = $this->objectManager->create(ExportCsv::class);
        $export->setWriter($writer);

        return $export->export();
    }

    /**
     * @return string[]
     */
    private function parseCsvHeaders(string $csvContent): array
    {
        $lines = explode("\n", $csvContent);
        if (empty($lines[0])) {
            return [];
        }
        return str_getcsv($lines[0]);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseCsv(string $csvContent): array
    {
        $lines = explode("\n", trim($csvContent));
        if (count($lines) < 2) {
            return [];
        }
        $headers = str_getcsv(array_shift($lines));
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line);
            if (count($values) === count($headers)) {
                $rows[] = array_combine($headers, $values);
            }
        }
        return $rows;
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @return array<string, string>|null
     */
    private function findRowBySku(array $rows, string $sku): ?array
    {
        foreach ($rows as $row) {
            if (($row['sku'] ?? '') === $sku) {
                return $row;
            }
        }
        return null;
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
            ->where('link.parent_id = ?', (int)$product->getId());

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
