<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\AggregateProductImportExport\Model\Import\Product\Type;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as ProductAttributeCollectionFactory;
use Magento\CatalogImportExport\Model\Import\Product;
use Magento\CatalogImportExport\Model\Import\Product\SkuStorage;
use Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory as AttributeSetCollectionFactory;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterfaceFactory;
use Nfourteen\AggregateProduct\Model\Product\AllowedChildTypes;
use Nfourteen\AggregateProduct\Model\RelationReindexer;
use Nfourteen\AggregateProduct\Model\ResourceModel\RelationMetadata as RelationMetadataResource;

class Aggregate extends AbstractType
{
    private const COLUMN_NAME = 'aggregate_children';
    private const SKU_QTY_DELIMITER = '=';
    private const DEFAULT_QTY = 1.0;
    private const PRODUCT_ID_FIELD = 'entity_id';

    private const ERROR_INVALID_QTY = 'aggregateInvalidQty';
    private const ERROR_DUPLICATE_CHILD = 'aggregateDuplicateChild';
    private const ERROR_INVALID_CHILD_TYPE = 'aggregateInvalidChildType';
    private const ERROR_CHILD_NOT_FOUND = 'aggregateChildNotFound';

    /**
     * @var string[]
     */
    protected $_messageTemplates = [
        self::ERROR_INVALID_QTY => 'Aggregate child qty must be numeric and >= 1',
        self::ERROR_DUPLICATE_CHILD => 'Duplicate child SKU in aggregate_children',
        self::ERROR_INVALID_CHILD_TYPE => 'Aggregate child product type is invalid',
        self::ERROR_CHILD_NOT_FOUND => 'Aggregate child SKU was not found',
    ];

    /** @var string[] */
    private array $allowedProductTypes;

    private SkuStorage $skuStorage;

    public function __construct(
        AttributeSetCollectionFactory $attrSetColFac,
        ProductAttributeCollectionFactory $prodAttrColFac,
        ResourceConnection $resource,
        array $params,
        private readonly RelationMetadataResource $relationMetadataResource,
        private readonly RelationMetadataInterfaceFactory $relationMetadataFactory,
        private readonly RelationReindexer $relationReindexer,
        AllowedChildTypes $allowedChildTypes,
        ?SkuStorage $skuStorage = null
    ) {
        // Single source of truth for the child-type policy, shared with the repository.
        $this->allowedProductTypes = $allowedChildTypes->get();
        $this->skuStorage = $skuStorage ?: ObjectManager::getInstance()->get(SkuStorage::class);
        parent::__construct($attrSetColFac, $prodAttrColFac, $resource, $params);
    }

    protected function _isParticularAttributesValid(array $rowData, $rowNum)
    {
        if ($this->_type !== ($rowData[Product::COL_TYPE] ?? null)) {
            return true;
        }

        if (!isset($rowData[self::COLUMN_NAME]) || $rowData[self::COLUMN_NAME] === '') {
            return true;
        }

        $newSku = $this->_entityModel->getNewSku();
        $pairs = $this->parseSkuQtyPairs($rowData[self::COLUMN_NAME]);
        $seenSkus = [];
        $valid = true;

        foreach ($pairs as $pair) {
            $sku = $pair['sku'];
            if (isset($seenSkus[$sku])) {
                $this->_entityModel->addRowError(self::ERROR_DUPLICATE_CHILD, $rowNum, self::COLUMN_NAME);
                $valid = false;
                continue;
            }
            $seenSkus[$sku] = true;

            $qty = $pair['qty'];
            if ($qty !== null && (!is_numeric($qty) || (float)$qty < 1)) {
                $this->_entityModel->addRowError(self::ERROR_INVALID_QTY, $rowNum, self::COLUMN_NAME);
                $valid = false;
            }

            $resolution = $this->resolveChild($sku, $newSku);
            if (($resolution['error'] ?? null) === self::ERROR_INVALID_CHILD_TYPE) {
                $this->_entityModel->addRowError(self::ERROR_INVALID_CHILD_TYPE, $rowNum, self::COLUMN_NAME);
                $valid = false;
            }
        }

        return $valid;
    }

    public function saveData()
    {
        $newSku = $this->_entityModel->getNewSku();

        while ($bunch = $this->_entityModel->getNextBunch()) {
            $parents = $this->collectParents($bunch, $newSku);
            if (empty($parents)) {
                continue;
            }

            $relations = [];
            foreach ($parents as $parentId => $links) {
                // Replace semantics: clear the parent's current children (and their mirrored
                // catalog_product_relation rows) before persisting the desired set. An empty $links
                // set therefore removes every child.
                $this->relationMetadataResource->deleteByParentId($parentId);

                foreach ($links as $link) {
                    $relation = $this->relationMetadataFactory->create();
                    $relation->setParentId($parentId);
                    $relation->setProductId($link['product_id']);
                    $relation->setQty($link['qty']);
                    $relations[] = $relation;
                }
            }

            if (!empty($relations)) {
                $this->relationMetadataResource->saveAggregateLinks($relations);
            }

            // On-save index mode has no mview triggers on the link table, so the reindex must
            // happen here; in scheduled mode it no-ops and the mview subscriptions take over.
            $this->relationReindexer->reindex(array_keys($parents));
        }

        return $this;
    }

    /**
     * Resolve every aggregate parent referenced in the bunch to its desired child link set.
     * Invalid children are dropped with a row error rather than silently skipped.
     *
     * @param array<int|string, array<string, mixed>> $bunch
     * @param array<string, array<string, mixed>> $newSku
     * @return array<int, array<int, array{product_id: int, qty: float}>> parentId => links
     */
    private function collectParents(array $bunch, array $newSku): array
    {
        $parents = [];

        foreach ($bunch as $rowNum => $rowData) {
            if ($this->_type !== ($rowData[Product::COL_TYPE] ?? null)) {
                continue;
            }
            if (!$this->_entityModel->isRowAllowedToImport($rowData, $rowNum)) {
                continue;
            }
            if (Product::SCOPE_DEFAULT !== $this->_entityModel->getRowScope($rowData)) {
                continue;
            }

            // Column absent from the file entirely -> preserve existing links.
            if (!array_key_exists(self::COLUMN_NAME, $rowData)) {
                continue;
            }

            $parentSku = strtolower($rowData[Product::COL_SKU]);
            $parentSkuData = $newSku[$parentSku] ?? null;
            if (!$parentSkuData) {
                continue;
            }
            $parentId = (int)($parentSkuData[self::PRODUCT_ID_FIELD] ?? 0);
            if ($parentId <= 0) {
                continue;
            }

            // Present-but-empty value -> delete all children (links stays empty).
            $links = [];
            $aggregateChildren = $rowData[self::COLUMN_NAME];
            if ($aggregateChildren !== '' && $aggregateChildren !== null) {
                foreach ($this->normalizeSkusAndQty($aggregateChildren) as $childSku => $qty) {
                    $resolution = $this->resolveChild((string)$childSku, $newSku);
                    if (isset($resolution['error'])) {
                        $this->_entityModel->addRowError($resolution['error'], $rowNum, self::COLUMN_NAME);
                        continue;
                    }

                    $links[] = ['product_id' => $resolution['id'], 'qty' => (float)$qty];
                }
            }

            $parents[$parentId] = $links;
        }

        return $parents;
    }

    /**
     * @param string $value
     * @return array<string, float|int>
     */
    private function normalizeSkusAndQty(string $value): array
    {
        $result = [];
        foreach ($this->parseSkuQtyPairs($value) as $pair) {
            $result[$pair['sku']] = $pair['qty'] === null ? self::DEFAULT_QTY : (float)$pair['qty'];
        }

        return $result;
    }

    /**
     * Parse sku=qty pairs from CSV value while preserving duplicate entries for validation.
     *
     * @param string $value
     * @return array<int, array{sku: string, qty: string|null}>
     */
    private function parseSkuQtyPairs(string $value): array
    {
        $result = [];
        $entries = explode($this->_entityModel->getMultipleValueSeparator(), $value);

        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            $parts = explode(self::SKU_QTY_DELIMITER, $entry, 2);
            $sku = strtolower(trim($parts[0]));
            $qty = isset($parts[1]) ? trim($parts[1]) : null;
            if ($qty === '') {
                $qty = null;
            }

            $result[] = [
                'sku' => $sku,
                'qty' => $qty,
            ];
        }

        return $result;
    }

    /**
     * Resolve a child SKU to its entity id, distinguishing "unknown SKU" from "disallowed type"
     * so the caller can raise the correct row error.
     *
     * @param array<string, array<string, mixed>> $newSku
     * @return array{id: int}|array{error: string}
     */
    private function resolveChild(string $sku, array $newSku): array
    {
        $sku = strtolower($sku);

        if (isset($newSku[$sku])) {
            $type = $newSku[$sku]['type_id'] ?? null;
            if ($type !== null && !in_array($type, $this->allowedProductTypes, true)) {
                return ['error' => self::ERROR_INVALID_CHILD_TYPE];
            }
            $entityId = (int)($newSku[$sku][self::PRODUCT_ID_FIELD] ?? 0);
            if ($entityId > 0) {
                return ['id' => $entityId];
            }
            // entity_id not yet populated for a freshly-created product — fall through.
        }

        if ($this->skuStorage->has($sku)) {
            $existing = $this->skuStorage->get($sku);
            $type = $existing['type_id'] ?? null;
            if ($type !== null && !in_array($type, $this->allowedProductTypes, true)) {
                return ['error' => self::ERROR_INVALID_CHILD_TYPE];
            }
            $entityId = (int)($existing[self::PRODUCT_ID_FIELD] ?? 0);
            if ($entityId > 0) {
                return ['id' => $entityId];
            }
        }

        return ['error' => self::ERROR_CHILD_NOT_FOUND];
    }
}
