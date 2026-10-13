import { expect, type Page } from '@playwright/test';
import fs from 'fs';
import { openAdminDashboard } from './AdminDashboard';

/**
 * System > Data Transfer > Export, used the way a merchant does: pick the entity, filter the
 * products, press "Continue", then download the file from the grid once it has been written.
 *
 * Admin URLs carry a secret key, so the page is reached through the menu instead of by URL.
 */
export class AdminProductExport {
    constructor(private readonly page: Page) {}

    async open(): Promise<void> {
        await openAdminDashboard(this.page);
        await this.page.locator('#menu-magento-backend-system > a').click();
        await this.page
            .locator('#menu-magento-backend-system .submenu')
            .getByRole('link', { name: 'Export', exact: true })
            .click();
        await expect(this.page.getByRole('heading', { name: 'Export', exact: true })).toBeVisible();
    }

    /**
     * Queues an export of the products whose SKU contains the given text. Magento writes the file
     * later, from the `exportProcessor` queue consumer.
     *
     * The filter grid is loaded by the page's script once it sees the entity change. A change made
     * before that script has loaded goes unnoticed, so the choice is repeated until it lands.
     */
    async queueProductsWithSkuContaining(sku: string): Promise<void> {
        const filters = this.page.locator('#export_filter_container');
        const skuFilter = filters.locator('input[name="export_filter[sku]"]');
        await expect(async () => {
            await this.page.locator('#entity').selectOption('catalog_product');
            await expect(skuFilter).toBeVisible({ timeout: 5_000 });
        }).toPass({ timeout: 30_000 });
        await skuFilter.fill(sku);
        await filters.getByRole('button', { name: 'Continue' }).click();
        await expect(this.page.getByText('Message is added to queue, wait to get your file soon.')).toBeVisible({
            timeout: 30_000,
        });
    }

    /**
     * Downloads the newest file in the grid, the one the last processed export wrote. The grid lists
     * files newest first, and shows names in the admin's timezone rather than as they are on disk.
     */
    async downloadNewestFile(): Promise<string> {
        const row = this.page.locator('tr.data-row').first();
        await expect(row).toBeVisible({ timeout: 30_000 });
        await row.getByRole('button', { name: 'Select' }).click();
        const download = this.page.waitForEvent('download');
        await row.getByRole('link', { name: 'Download' }).click();

        return fs.readFileSync(await (await download).path(), 'utf8');
    }
}
