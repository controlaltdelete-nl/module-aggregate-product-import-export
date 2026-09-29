<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProductImportExport\Model\Export;

use Magento\CatalogImportExport\Model\Export\RowCustomizerInterface;
use Magento\ImportExport\Model\Import;
use Nfourteen\AggregateProduct\Api\LinkedProductProviderInterface;

class RowCustomizer implements RowCustomizerInterface
{
    private const COLUMN_NAME = 'aggregate_children';
    private const SKU_QTY_DELIMITER = '=';

    /** @var array<int, array<int, array{sku: string, qty: float}>> */
    private array $aggregateData = [];

    public function __construct(
        private readonly LinkedProductProviderInterface $linkedProductProvider
    ) {
    }

    public function prepareData($collection, $productIds)
    {
        $this->aggregateData = [];

        // Read membership through the provider so export honors the same required_options = 0 filter
        // the rest of the system applies, which pulls children via the type instance rather
        // than a raw link-table join.
        $linkedByParent = $this->linkedProductProvider->getForProducts(array_map('intval', $productIds));

        foreach ($linkedByParent as $parentId => $linkedProducts) {
            foreach ($linkedProducts as $linkedProduct) {
                $this->aggregateData[(int)$parentId][] = [
                    'sku' => $linkedProduct->getProductSku(),
                    'qty' => $linkedProduct->getQty(),
                ];
            }
        }
    }

    public function addHeaderColumns($columns)
    {
        return array_merge($columns, [self::COLUMN_NAME]);
    }

    public function addData($dataRow, $productId)
    {
        if (!isset($this->aggregateData[$productId])) {
            $dataRow[self::COLUMN_NAME] = '';
            return $dataRow;
        }

        $parts = [];
        foreach ($this->aggregateData[$productId] as $child) {
            $qty = $child['qty'];
            $formattedQty = rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
            $parts[] = $child['sku'] . self::SKU_QTY_DELIMITER . $formattedQty;
        }

        $dataRow[self::COLUMN_NAME] = implode(Import::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR, $parts);

        return $dataRow;
    }

    public function getAdditionalRowsCount($additionalRowsCount, $productId)
    {
        return $additionalRowsCount;
    }
}
