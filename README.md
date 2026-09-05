# Nfourteen_AggregateProductImportExport

Import/export support for aggregate product child links via CSV.

## CSV Column: `aggregate_children`

Defines child products and their quantities for an aggregate product.

### Format

```
child-sku-1=2,child-sku-2=1.5
```

- Comma (`,`) separates children
- Equals (`=`) separates SKU from qty
- Qty must be numeric and >= 1 (default: 1)

### Example

```csv
sku,attribute_set_code,product_type,name,price,product_online,aggregate_children
card-a,Default,simple,Card A,5.00,1,
card-b,Default,simple,Card B,8.00,1,
agg-kit-001,Default,aggregate,Starter Kit,24.99,1,"card-a=2,card-b=3"
```

## Behavior

| Scenario | Result |
|----------|--------|
| `aggregate_children` column present with value | Children synced to provided list (existing links replaced) |
| `aggregate_children` column present but empty | All existing children removed |
| `aggregate_children` column not in CSV | No changes to existing children |

## Export

Aggregate products export with the `aggregate_children` column in the same `sku=qty` format. Exported files can be re-imported without modification (round-trip compatible).

## Stock Status

After import, parent aggregate product stock status is automatically updated based on child product stock status. If any child goes out of stock, the parent is marked out of stock.

## Troubleshooting

| Issue | Cause |
|-------|-------|
| Validation error: qty must be >= 1 | Child qty value is less than 1 or not numeric |
| Validation error: duplicate child SKU | Same child SKU appears twice in one row |
| Child link not created | Child SKU doesn't exist, or child product type is not simple/virtual |
| Links unchanged after import | The `aggregate_children` column is missing from the CSV headers |
