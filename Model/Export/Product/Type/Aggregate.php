<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProductImportExport\Model\Export\Product\Type;

use Magento\CatalogImportExport\Model\Export\Product\Type\AbstractType;

/**
 * Intentionally empty: export.xml must register a type model or aggregate products are
 * silently excluded from product export.
 */
class Aggregate extends AbstractType
{
}
