import { expect, test } from '@playwright/test';
import { ADMIN_SESSION_PATH } from '../global-setup';
import { AdminProductExport } from '../support/AdminProductExport';
import { AdminProductImport, STOP_ON_ERROR } from '../support/AdminProductImport';
import { CatalogFixture } from '../support/CatalogFixture';
import { processExportQueue } from '../support/ExportQueue';

const catalog = new CatalogFixture();

const childA = catalog.sku('a');
const childB = catalog.sku('b');
const pack = catalog.sku('pack');

test.use({ storageState: ADMIN_SESSION_PATH });

test.describe('Exporting and re-importing an aggregate product', () => {
    test.beforeAll(() => {
        catalog.createSimpleProduct(childA, 'E2E round trip child A');
        catalog.createSimpleProduct(childB, 'E2E round trip child B');
        catalog.createAggregateProduct(pack, 'E2E round trip pack', [
            { sku: childA, qty: 2 },
            { sku: childB, qty: 3 },
        ]);
    });

    test.afterAll(() => {
        catalog.removeAll();
    });

    test('the exported file restores the pack after its children were changed', async ({ page }) => {
        const exportPage = new AdminProductExport(page);
        const importPage = new AdminProductImport(page);

        await exportPage.open();
        await exportPage.queueProductsWithSkuContaining(catalog.prefix);
        processExportQueue();
        await exportPage.open();
        const csv = await exportPage.downloadNewestFile();
        expect(csv).toContain(pack);

        catalog.replaceAggregateChildren(pack, [{ sku: childA, qty: 1 }]);

        await importPage.open();
        await importPage.uploadProducts(`${catalog.prefix}-export.csv`, csv, STOP_ON_ERROR);
        await expect(await importPage.checkData()).toContainText('File is valid!');
        expect(await importPage.startImport()).toContain('Import successfully done');

        expect(catalog.aggregateChildren(pack)).toEqual([
            { sku: childA, qty: 2 },
            { sku: childB, qty: 3 },
        ]);
    });
});
