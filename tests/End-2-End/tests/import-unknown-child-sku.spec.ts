import { expect, test } from '@playwright/test';
import { ADMIN_SESSION_PATH } from '../global-setup';
import { AdminProductImport, SKIP_ERROR_ENTRIES } from '../support/AdminProductImport';
import { CatalogFixture } from '../support/CatalogFixture';

const catalog = new CatalogFixture();

const childA = catalog.sku('a');
const childB = catalog.sku('b');
const pack = catalog.sku('pack');

test.use({ storageState: ADMIN_SESSION_PATH });

test.describe('Importing an aggregate whose children column has a typo', () => {
    test.beforeAll(() => {
        catalog.createSimpleProduct(childA, 'E2E import child A');
        catalog.createSimpleProduct(childB, 'E2E import child B');
        catalog.createAggregateProduct(pack, 'E2E import pack', [
            { sku: childA, qty: 2 },
            { sku: childB, qty: 3 },
        ]);
    });

    test.afterAll(() => {
        catalog.removeAll();
    });

    test('the mistyped SKU is reported and the pack keeps the children it had', async ({ page }) => {
        const importPage = new AdminProductImport(page);
        const csv = [
            'sku,product_type,attribute_set_code,name,aggregate_children',
            `${childA},simple,Default,E2E import child A renamed,`,
            `${pack},aggregate,Default,E2E import pack,"${childA}=4,${childB}x=6"`,
        ].join('\n');

        await importPage.open();
        await importPage.uploadProducts(`${catalog.prefix}.csv`, csv, SKIP_ERROR_ENTRIES);
        const validationReport = await (await importPage.checkData()).innerText();
        await importPage.startImport();

        expect(catalog.aggregateChildren(pack)).toEqual([
            { sku: childA, qty: 2 },
            { sku: childB, qty: 3 },
        ]);
        expect(validationReport).toContain('Aggregate child SKU was not found');
    });
});
