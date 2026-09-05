# Nfourteen_AggregateProductImportExport Implementation Plan

## Objective
Implement product CSV import/export support for the `aggregate` product type so merchants can define child product links in one column, `aggregate_children`, and have those children linked automatically during import.

## Reference Analysis (Completed)
The plan is based on patterns already used in Magento core and local modules:

1. `vendor/magento/module-grouped-import-export/Model/Import/Product/Type/Grouped.php`
2. `vendor/magento/module-grouped-import-export/Model/Export/RowCustomizer.php`
3. `vendor/magento/module-grouped-import-export/Model/Export/Product/Type/Grouped.php`
4. `vendor/magento/module-grouped-import-export/Plugin/.../UpdateGroupedProductStockStatusPlugin.php`
5. `app/code/Nfourteen/WallpapersImportExport/etc/` — XML config patterns
6. `app/code/Nfourteen/AggregateProduct/Model/Product/Type/Aggregate.php`
7. `app/code/Nfourteen/AggregateProduct/Model/ResourceModel/RelationMetadata.php`
8. `app/code/Nfourteen/AggregateProduct/etc/db_schema.xml`

## CSV Contract for `aggregate_children`

### Column
`aggregate_children`

### Format
Use the grouped product pattern: `child-sku-1=2,child-sku-2=1.5`

- `,` between children (`Import::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR`)
- `=` between SKU and qty (same as `Grouped::SKU_QTY_DELIMITER`)

### Rules
1. `sku` is required per child entry.
2. `qty` is optional; default to `1` when omitted.
3. `qty` must be numeric and `>= 1` (matches `RelationMetadata::saveAggregateLinks()` enforcement).
4. Duplicate child SKUs in one row are invalid.
5. Child SKU can reference:
   - Existing products.
   - Products created in the same import file (resolved through `getNewSku()` data).
6. Child product types must be composable (`simple`, `virtual`).
7. **Omitted vs empty distinction:**
   - Column not present in CSV headers: no relation changes for any row.
   - Column present but cell is empty string: delete all children for that parent.
   - Column present with value: sync children to provided list.

### Example CSV snippet
```csv
sku,attribute_set_code,product_type,name,price,product_online,aggregate_children
agg-kit-001,Default,aggregate,Starter Kit,24.99,1,"card-a=2,card-b=3"
```

## Phased Implementation

### Phase 1: Module Skeleton and Wiring

**Files to create:**

1. `registration.php` — standard module registration
2. `etc/module.xml` — dependencies: `Magento_ImportExport`, `Magento_CatalogImportExport`, `Nfourteen_AggregateProduct`
3. `composer.json` — package metadata
4. `etc/import.xml` — register `entityType entity="catalog_product" name="aggregate"` with import adapter model
5. `etc/export.xml` — register `entityType entity="catalog_product" name="aggregate"` with export type model
6. `etc/di.xml`:
   - Register RowCustomizer in `Magento\CatalogImportExport\Model\Export\RowCustomizer\Composite`
   - Register StockItemImporter plugin

**Acceptance criteria:**
1. `bin/magento module:status` shows module enabled.
2. Import/export config resolves aggregate adapter classes without DI errors.

### Phase 2: Import Adapter

**File:** `Model/Import/Product/Type/Aggregate.php`
- Extends `Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType`
- Inject `ResourceConnection`, `ConfigInterface` (for composable types), `SkuStorage`
- No separate Links helper needed — aggregate has one simple table (`catalog_product_aggregate_link`)

**CSV column:** `aggregate_children`
**Format:** `child-sku-1=2,child-sku-2=1.5` (comma-separated `sku=qty` pairs)

**Methods:**

`saveData()` — Follow `Grouped::saveData()` pattern:
1. Iterate bunches via `$this->_entityModel->getNextBunch()`
2. Skip rows where type != `aggregate` or row not allowed
3. Skip rows where `aggregate_children` is not set (omitted = no change)
4. Parse `aggregate_children` using `normalizeSkusAndQty()` method (mirror grouped pattern)
5. Resolve child product IDs via `$this->_entityModel->getNewSku()` and `SkuStorage`
6. Validate child types are composable (`simple`, `virtual`)
7. Collect parent IDs that have data + link rows `[parent_id, product_id, qty]`
8. Delete existing links: `DELETE FROM catalog_product_aggregate_link WHERE parent_id IN (?)`
9. `insertOnDuplicate()` new links
10. Handle empty `aggregate_children` value: delete all links for that parent (sync to empty)

`isRowValid()` — Validate `aggregate_children` format:
1. Parse entries, validate each `sku=qty` pair
2. Validate qty is numeric and >= 1
3. Check for duplicate child SKUs within one row
4. Add row errors via `$this->_entityModel->addRowError()`

**Key distinction (omitted vs empty):**
- Column not present in CSV headers -> no relation changes for any row
- Column present but cell is empty string -> delete all children for that parent
- Column present with value -> sync children to provided list

### Phase 3: Export Type + RowCustomizer

**File:** `Model/Export/Product/Type/Aggregate.php`
- Extends `Magento\CatalogImportExport\Model\Export\Product\Type\AbstractType`
- Empty stub (no type-specific export logic needed beyond the base class)

**File:** `Model/Export/RowCustomizer.php`
- Implements `Magento\CatalogImportExport\Model\Export\RowCustomizerInterface`
- Inject `ResourceConnection`

**Note:** Unlike grouped products (where core `Product.php::appendMultirowData()` handles links from `catalog_product_link`), aggregate links live in `catalog_product_aggregate_link`. The RowCustomizer must handle both the header AND the data population.

`prepareData($collection, $productIds)`:
- Query `catalog_product_aggregate_link` joined with `catalog_product_entity` (for child SKU)
- Filter by `parent_id IN ($productIds)`
- Store as `[parentId => [['sku' => ..., 'qty' => ...], ...]]`

`addHeaderColumns($columns)`:
- Append `aggregate_children`

`addData($dataRow, $productId)`:
- If product has preloaded children, format as `sku1=qty1,sku2=qty2`
- Set into `$dataRow['aggregate_children']`
- Non-aggregate products get empty string

`getAdditionalRowsCount($additionalRowsCount, $productId)`:
- Return input unchanged (no additional rows needed — all children fit in one cell)

### Phase 4: Stock Status Plugin

**File:** `Plugin/StockItemImporter/UpdateAggregateStockStatusPlugin.php`
- Mirror `vendor/magento/module-grouped-import-export/Plugin/.../UpdateGroupedProductStockStatusPlugin.php`
- Inject `Nfourteen\AggregateProduct\Model\Inventory\ChangeParentStockStatus`
- `afterImport()`: extract product IDs from stock data, call `changeParentStockStatus->execute()` for each

**Note:** MSI-based stock updates are already handled by `InventoryAggregateProduct\Service\StockStatusProcessor` via `CompositeProductStockStatusProcessorPool`. This plugin covers the legacy `cataloginventory` stock system used during import.

### Phase 5: Tests

#### Integration Tests

**File:** `Test/Integration/ImportAggregateChildrenTest.php` (`@magentoDbIsolation enabled`)

| Test | Description |
|------|-------------|
| `testImportCreatesChildLinks` | Import CSV with aggregate parent + existing simple children -> assert rows in `catalog_product_aggregate_link` |
| `testImportWithChildrenInSameFile` | Import CSV containing both the aggregate parent and its simple children -> assert links created (newSku resolution) |
| `testImportEmptyColumnRemovesAllLinks` | Create aggregate with children via fixture, import with empty `aggregate_children` -> assert zero rows for that parent |
| `testImportOmittedColumnPreservesLinks` | Create aggregate with children via fixture, import CSV without `aggregate_children` column -> assert existing links unchanged |
| `testImportReplaceBehavior` | Import with REPLACE behavior -> assert old links replaced with new ones |
| `testImportAppendBehavior` | Import with APPEND behavior -> assert new links added without removing existing |
| `testImportUpdatesExistingLinkQty` | Import with changed qty for existing child -> assert qty updated |
| `testImportSkipsInvalidChildSku` | Import with non-existent child SKU -> assert row error logged |
| `testImportRejectsQtyBelowOne` | Import with qty=0.5 -> assert validation error |
| `testImportRejectsDuplicateChildSku` | Import with same child SKU twice -> assert validation error |

**File:** `Test/Integration/ExportAggregateChildrenTest.php` (`@magentoDbIsolation enabled`)

| Test | Description |
|------|-------------|
| `testExportIncludesAggregateChildrenColumn` | Export products -> assert `aggregate_children` column exists |
| `testExportFormatsChildrenCorrectly` | Create aggregate with children -> export -> assert `sku1=qty1,sku2=qty2` format |
| `testExportNonAggregateProductHasEmptyColumn` | Export simple product -> assert `aggregate_children` is empty |
| `testExportImportRoundTrip` | Create aggregate -> export -> re-import -> assert links identical |

**File:** `Test/Integration/StockStatusAfterImportTest.php` (`@magentoDbIsolation enabled`)

| Test | Description |
|------|-------------|
| `testParentStockStatusUpdatedAfterChildImport` | Create aggregate with in-stock children -> import child as out-of-stock -> assert parent stock status changed |

**Test fixtures:** Use `Magento\Catalog\Test\Fixture\Product` DataFixture for simple products and `Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct` for aggregate products. Use `Magento\CatalogImportExport\Model\Import\ProductFactory` for import operations with `Magento\ImportExport\Model\Import\Source\Csv`.

### Phase 6: Documentation + Sample CSV

**Files:**
- `README.md` — column format, examples, behavior notes
- `Files/Sample/aggregate_products.csv` — sample import file

## File Summary

| File | Purpose |
|------|---------|
| `registration.php` | Module registration |
| `etc/module.xml` | Module declaration + dependencies |
| `composer.json` | Composer metadata |
| `etc/import.xml` | Register aggregate import adapter |
| `etc/export.xml` | Register aggregate export type |
| `etc/di.xml` | RowCustomizer composite + StockItemImporter plugin |
| `Model/Import/Product/Type/Aggregate.php` | Import adapter (parsing, validation, persistence) |
| `Model/Export/Product/Type/Aggregate.php` | Export type stub |
| `Model/Export/RowCustomizer.php` | Export data formatter |
| `Plugin/StockItemImporter/UpdateAggregateStockStatusPlugin.php` | Post-import stock sync |

## Key Reusable Code

| What | Where |
|------|-------|
| Import adapter pattern | `vendor/magento/module-grouped-import-export/Model/Import/Product/Type/Grouped.php` |
| SKU/qty parsing | `Grouped::normalizeSkusAndQty()` (line 167-186) |
| Child SKU resolution | `Grouped::saveData()` (lines 117-128) — newSku + SkuStorage lookup |
| Stock plugin pattern | `vendor/magento/module-grouped-import-export/Plugin/.../UpdateGroupedProductStockStatusPlugin.php` |
| Link table | `catalog_product_aggregate_link` (parent_id, product_id, qty) |
| ChangeParentStockStatus | `Nfourteen\AggregateProduct\Model\Inventory\ChangeParentStockStatus` |
| WallpapersImportExport XML | `app/code/Nfourteen/WallpapersImportExport/etc/` — XML config patterns |

## Verification

1. `bin/magento module:status` — module appears enabled
2. Import a CSV with aggregate products and `aggregate_children` column -> verify `catalog_product_aggregate_link` rows created
3. Import with empty `aggregate_children` -> verify links removed
4. Export aggregate products -> verify `aggregate_children` column has `sku=qty` format
5. Export -> re-import the exported file -> verify links unchanged (round-trip)
6. Import child product stock changes -> verify parent aggregate stock status updates
7. Run integration test suite
