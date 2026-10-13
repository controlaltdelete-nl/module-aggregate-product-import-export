import { runMagentoCli } from './MagentoCommand';

/**
 * Writes the file for the export the admin just queued. Nothing runs queue consumers in the test
 * stores, so a spec processes the one message it queued itself.
 */
export function processExportQueue(): void {
    runMagentoCli(['queue:consumers:start', 'exportProcessor', '--max-messages=1', '--single-thread']);
}
