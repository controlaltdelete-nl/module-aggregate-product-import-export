import { expect, test } from '@playwright/test';
import { ADMIN_SESSION_PATH } from '../global-setup';
import { AdminProductImport, STOP_ON_ERROR } from '../support/AdminProductImport';
import { CatalogFixture } from '../support/CatalogFixture';

const catalog = new CatalogFixture();

const CSV_HEADER =
    'sku,attribute_set_code,product_type,product_websites,name,price,product_online,visibility,qty,aggregate_children';

test.use({ storageState: ADMIN_SESSION_PATH });

test.describe('Importing aggregate products', () => {
    test.afterAll(() => {
        catalog.removeAll();
    });

    test('a file with new children and a new pack creates the pack with those children', async ({ page }) => {
        const childA = catalog.sku('new-a');
        const childB = catalog.sku('new-b');
        const pack = catalog.sku('new-pack');
        const importPage = new AdminProductImport(page);
        const csv = [
            CSV_HEADER,
            `${childA},Default,simple,base,E2E imported child A,10,1,"Not Visible Individually",100,`,
            `${childB},Default,simple,base,E2E imported child B,5,1,"Not Visible Individually",100,`,
            `${pack},Default,aggregate,base,E2E imported pack,25,1,"Catalog, Search",,"${childA}=2,${childB}=3"`,
        ].join('\n');

        await importPage.open();
        await importPage.uploadProducts(`${catalog.prefix}-new.csv`, csv, STOP_ON_ERROR);
        await expect(await importPage.checkData()).toContainText('File is valid!');
        expect(await importPage.startImport()).toContain('Import successfully done');

        expect(catalog.aggregateChildren(pack)).toEqual([
            { sku: childA, qty: 2 },
            { sku: childB, qty: 3 },
        ]);
    });

    test('a file for an existing pack replaces its children with the ones in the file', async ({ page }) => {
        const childA = catalog.sku('existing-a');
        const childB = catalog.sku('existing-b');
        const childC = catalog.sku('existing-c');
        const pack = catalog.sku('existing-pack');
        catalog.createSimpleProduct(childA, 'E2E existing child A');
        catalog.createSimpleProduct(childB, 'E2E existing child B');
        catalog.createSimpleProduct(childC, 'E2E existing child C');
        catalog.createAggregateProduct(pack, 'E2E existing pack', [
            { sku: childA, qty: 2 },
            { sku: childB, qty: 3 },
        ]);
        const importPage = new AdminProductImport(page);
        const csv = ['sku,product_type,attribute_set_code,aggregate_children', `${pack},aggregate,Default,"${childA}=5,${childC}=1"`].join(
            '\n',
        );

        await importPage.open();
        await importPage.uploadProducts(`${catalog.prefix}-update.csv`, csv, STOP_ON_ERROR);
        await expect(await importPage.checkData()).toContainText('File is valid!');
        expect(await importPage.startImport()).toContain('Import successfully done');

        expect(catalog.aggregateChildren(pack)).toEqual([
            { sku: childA, qty: 5 },
            { sku: childC, qty: 1 },
        ]);
    });
});
