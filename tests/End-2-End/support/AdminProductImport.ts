import { expect, type Locator, type Page } from '@playwright/test';
import { openAdminDashboard } from './AdminDashboard';

export const STOP_ON_ERROR = 'validation-stop-on-errors';
export const SKIP_ERROR_ENTRIES = 'validation-skip-errors';

/**
 * System > Data Transfer > Import, used the way a merchant does: pick the entity, upload a CSV,
 * press "Check Data", then "Import".
 *
 * Admin URLs carry a secret key, so the page is reached through the menu instead of by URL. The
 * behavior fields exist twice, for entities with the basic and with a custom behavior set; products
 * use the basic one, so those are addressed by id.
 */
export class AdminProductImport {
    constructor(private readonly page: Page) {}

    async open(): Promise<void> {
        await openAdminDashboard(this.page);
        await this.page.locator('#menu-magento-backend-system > a').click();
        await this.page
            .locator('#menu-magento-backend-system .submenu')
            .getByRole('link', { name: 'Import', exact: true })
            .click();
        await expect(this.page.getByRole('heading', { name: 'Import', exact: true })).toBeVisible();
    }

    async uploadProducts(fileName: string, csv: string, validationStrategy: string): Promise<void> {
        await this.chooseProducts();
        await this.page.locator('#basic_behavior').selectOption('append');
        await this.page.locator('#basic_behaviorvalidation_strategy').selectOption(validationStrategy);
        await this.page.getByLabel('Select File to Import').setInputFiles({
            name: fileName,
            mimeType: 'text/csv',
            buffer: Buffer.from(csv),
        });
    }

    /**
     * Presses "Check Data" and hands back the validation result once it is on the page.
     */
    async checkData(): Promise<Locator> {
        await this.page.locator('#upload_button').click();
        const result = this.validationResult();
        await expect(result).toContainText(/Checked rows|validation failed|File is valid/i, { timeout: 60_000 });

        return result;
    }

    /**
     * Presses "Import", waits until the import has finished, whether it succeeded or not, and hands
     * back what the page reported, including the error details folded away behind "Show more".
     */
    async startImport(): Promise<string> {
        await this.validationResult().getByRole('button', { name: 'Import', exact: true }).click();
        await expect(this.validationResult()).toContainText(/Import successfully done|Following Error\(s\)/, {
            timeout: 120_000,
        });

        return (await this.validationResult().textContent()) ?? '';
    }

    /**
     * The behavior fields are enabled by the page's script once it sees the entity change. A change
     * made before that script has loaded goes unnoticed, so the choice is repeated until it lands.
     */
    private async chooseProducts(): Promise<void> {
        await expect(async () => {
            await this.page.locator('#entity').selectOption('catalog_product');
            await expect(this.page.locator('#basic_behavior')).toBeEnabled({ timeout: 2_000 });
        }).toPass({ timeout: 30_000 });
    }

    private validationResult(): Locator {
        return this.page.locator('#import_validation_messages');
    }
}
