import { runMagentoPhp } from './MagentoCommand';

export interface ChildLink {
    sku: string;
    qty: number;
}

/**
 * Creates the products an import spec starts from, reads back what the import left behind, and
 * removes everything the run created.
 *
 * Every SKU starts with a per-run prefix, so cleanup removes exactly this run's products and
 * nothing else, even after a failure halfway through. Each spec file has its own fixture, so the
 * prefix also carries a random part.
 */
export class CatalogFixture {
    readonly prefix: string;

    constructor() {
        this.prefix = `e2e-aggr-import-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
    }

    sku(suffix: string): string {
        return `${this.prefix}-${suffix}`;
    }

    createSimpleProduct(sku: string, name: string): void {
        runMagentoPhp(`
            $product = $om->create(\\Magento\\Catalog\\Api\\Data\\ProductInterfaceFactory::class)->create();
            $product->setTypeId('simple')
                ->setAttributeSetId(4)
                ->setSku(${JSON.stringify(sku)})
                ->setName(${JSON.stringify(name)})
                ->setUrlKey(${JSON.stringify(sku)})
                ->setPrice(10)
                ->setWeight(1)
                ->setStatus(\\Magento\\Catalog\\Model\\Product\\Attribute\\Source\\Status::STATUS_ENABLED)
                ->setVisibility(\\Magento\\Catalog\\Model\\Product\\Visibility::VISIBILITY_NOT_VISIBLE)
                ->setWebsiteIds([1])
                ->setStockData(['qty' => 100, 'is_in_stock' => 1, 'manage_stock' => 1]);
            $om->get(\\Magento\\Catalog\\Api\\ProductRepositoryInterface::class)->save($product);
        `);
    }

    createAggregateProduct(sku: string, name: string, children: ChildLink[]): void {
        runMagentoPhp(`
            $product = $om->create(\\Magento\\Catalog\\Api\\Data\\ProductInterfaceFactory::class)->create();
            $product->setTypeId(\\Nfourteen\\AggregateProduct\\Model\\Product\\Type\\Aggregate::TYPE_CODE)
                ->setAttributeSetId(4)
                ->setSku(${JSON.stringify(sku)})
                ->setName(${JSON.stringify(name)})
                ->setUrlKey(${JSON.stringify(sku)})
                ->setPrice(25)
                ->setStatus(\\Magento\\Catalog\\Model\\Product\\Attribute\\Source\\Status::STATUS_ENABLED)
                ->setVisibility(\\Magento\\Catalog\\Model\\Product\\Visibility::VISIBILITY_BOTH)
                ->setWebsiteIds([1])
                ->setStockData(['use_config_manage_stock' => 0, 'manage_stock' => 1, 'is_in_stock' => 1]);
            $om->get(\\Magento\\Catalog\\Api\\ProductRepositoryInterface::class)->save($product);
        `);
        this.replaceAggregateChildren(sku, children);
    }

    /**
     * Links go through the core module's own reconciler, the same path the admin save takes.
     */
    replaceAggregateChildren(sku: string, children: ChildLink[]): void {
        runMagentoPhp(`
            $repository = $om->get(\\Magento\\Catalog\\Api\\ProductRepositoryInterface::class);
            $parentId = (int) $repository->get(${JSON.stringify(sku)})->getId();
            $links = array_map(
                static fn (array $link) => $om->create(\\Nfourteen\\AggregateProduct\\Model\\RelationMetadataFactory::class)
                    ->create()
                    ->setData([
                        'parent_id' => $parentId,
                        'product_id' => (int) $repository->get($link['sku'])->getId(),
                        'qty' => $link['qty'],
                    ]),
                json_decode(${JSON.stringify(JSON.stringify(children))}, true)
            );
            $om->get(\\Nfourteen\\AggregateProduct\\Model\\RelationMetadataReconciler::class)->reconcile($parentId, $links);
        `);
    }

    /**
     * The children linked to an aggregate right now, read from the link table, sorted by SKU.
     */
    aggregateChildren(sku: string): ChildLink[] {
        const children: ChildLink[] = JSON.parse(runMagentoPhp(`
            $parentId = (int) $om->get(\\Magento\\Catalog\\Api\\ProductRepositoryInterface::class)
                ->get(${JSON.stringify(sku)})
                ->getId();
            $connection = $om->get(\\Magento\\Framework\\App\\ResourceConnection::class)->getConnection();
            $select = $connection->select()
                ->from(['link' => $connection->getTableName('catalog_product_aggregate_link')], ['qty'])
                ->join(['child' => $connection->getTableName('catalog_product_entity')], 'child.entity_id = link.product_id', ['sku'])
                ->where('link.parent_id = ?', $parentId);
            echo json_encode(array_map(
                static fn (array $row) => ['sku' => $row['sku'], 'qty' => (float) $row['qty']],
                $connection->fetchAll($select)
            ));
        `));

        return [...children].sort((left, right) => left.sku.localeCompare(right.sku));
    }

    removeAll(): void {
        runMagentoPhp(`
            $om->get(\\Magento\\Framework\\Registry::class)->register('isSecureArea', true);
            $collection = $om->create(\\Magento\\Catalog\\Model\\ResourceModel\\Product\\Collection::class)
                ->addAttributeToFilter('sku', ['like' => ${JSON.stringify(`${this.prefix}-%`)}]);
            $repository = $om->get(\\Magento\\Catalog\\Api\\ProductRepositoryInterface::class);
            array_map(
                static fn ($product) => $repository->delete($product),
                array_reverse($collection->getItems())
            );
        `);
    }
}
