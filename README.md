# Nfourteen_AggregateProductImportExport

CSV import and export of aggregate product child links, via an `aggregate_children` column.

## The `aggregate_children` column

Comma-separates children, `=` separates SKU from quantity. Quantity must be numeric and >= 1, defaulting to 1.

```csv
sku,attribute_set_code,product_type,name,price,product_online,aggregate_children
card-a,Default,simple,Card A,5.00,1,
card-b,Default,simple,Card B,8.00,1,
agg-kit-001,Default,aggregate,Starter Kit,24.99,1,"card-a=2,card-b=3"
```

| Column state | Result |
|---|---|
| Present with a value | Children replaced by the provided list |
| Present but empty | All existing children removed |
| Absent from the CSV | Existing children untouched |

Export emits the same `sku=qty` format, so exported files re-import unmodified. After import, parent stock status is recalculated from its children - any child out of stock marks the parent out of stock.

## Troubleshooting

| Issue | Cause |
|---|---|
| Validation error: qty must be >= 1 | Child qty is below 1 or not numeric |
| Validation error: duplicate child SKU | Same child SKU twice in one row |
| Child link not created | Child SKU doesn't exist, or its type is not simple/virtual |
| Links unchanged after import | `aggregate_children` is missing from the CSV headers |

## Installation

Part of the Aggregate Product suite. Install it through [`nfourteen/aggregate-product-metapackage`](https://github.com/nfourteen/aggregate-product-metapackage), whose README covers the Composer repositories these packages need and the MSI requirement. Requires PHP >= 8.3 and Magento Open Source 2.4.x with MSI enabled.
