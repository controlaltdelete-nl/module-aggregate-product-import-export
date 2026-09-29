<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProductImportExport\Plugin\StockItemImporter;

use Magento\CatalogImportExport\Model\StockItemImporterInterface;
use Nfourteen\AggregateProduct\Model\Inventory\ChangeParentStockStatus;

/**
 * Recomputes aggregate parent stock status after a stock import.
 *
 * Core StockItemImporter::import() bulk-writes legacy CatalogInventory stock rows in a single query,
 * bypassing the per-item save events that would otherwise trigger parent recompute. This plugin covers
 * that legacy non-MSI bulk-import path.
 */
class UpdateAggregateStockStatusPlugin
{
    public function __construct(
        private readonly ChangeParentStockStatus $changeParentStockStatus
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterImport(
        StockItemImporterInterface $subject,
        $result,
        array $stockData
    ): void {
        $productIds = array_column($stockData, 'product_id');
        $this->changeParentStockStatus->execute(array_map('intval', $productIds));
    }
}
