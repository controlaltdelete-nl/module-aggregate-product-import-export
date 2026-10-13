import { execFileSync } from 'child_process';
import path from 'path';

const LOCAL_MAGENTO_ROOT = path.resolve(__dirname, '../../../../../..');

const COMMAND_TIMEOUT_MS = 120_000;

/**
 * Runs a PHP snippet inside a bootstrapped Magento, with `$om` (the object manager) in scope.
 *
 * Locally the suite runs inside the DDEV web container, on the same filesystem as the store, so a
 * plain `php` is enough. In CI the store is a container driven from outside: `E2E_MAGENTO_EXEC`
 * is the command that gets inside it (`docker exec store`) and `E2E_MAGENTO_ROOT` the install's
 * path in there.
 *
 * The command is passed as an argument list, so nothing in the snippet goes through a shell.
 */
export function runMagentoPhp(body: string): string {
    const magentoRoot = magentoRootPath();
    const script = `
        require '${magentoRoot}/app/bootstrap.php';
        $bootstrap = \\Magento\\Framework\\App\\Bootstrap::create('${magentoRoot}', $_SERVER);
        $om = $bootstrap->getObjectManager();
        $om->get(\\Magento\\Framework\\App\\State::class)->setAreaCode('adminhtml');
        ${body}
    `;

    return runInStore(['php', '-r', script]);
}

/**
 * Runs `bin/magento` with the given arguments. A queue consumer blocks until a message arrives, so
 * every command is killed after two minutes and the spec fails instead of hanging the suite.
 */
export function runMagentoCli(args: string[]): string {
    return runInStore(['php', `${magentoRootPath()}/bin/magento`, ...args]);
}

function magentoRootPath(): string {
    return process.env.E2E_MAGENTO_ROOT || LOCAL_MAGENTO_ROOT;
}

function runInStore(command: string[]): string {
    const exec = (process.env.E2E_MAGENTO_EXEC || '').trim();
    const [executable, ...args] = exec === '' ? command : [...exec.split(/\s+/), ...command];

    return execFileSync(executable, args, { encoding: 'utf8', timeout: COMMAND_TIMEOUT_MS });
}
