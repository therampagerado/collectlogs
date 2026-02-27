<?php
/**
 * Copyright (C) 2017-2024 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 */

/**
 * Ensure JS error logging is fully set up on stores that were already at
 * version 1.4.0 before this feature was introduced (they would have skipped
 * upgrade-1.4.0.php). All steps are guarded so running this on a store that
 * already completed the 1.4.0 upgrade is a safe no-op.
 *
 * @param CollectLogs $module
 * @return bool
 * @throws PrestaShopDatabaseException
 * @throws PrestaShopException
 */
function upgrade_module_1_4_1($module)
{
    // Create/repair the JS error table.
    if (!$module->ensureJsErrorsTable()) {
        return false;
    }

    // Register FO hooks required for JS error capture.
    $module->registerHook('header');
    $module->registerHook('displayHeader');

    // Ensure the JS Errors Back-Office tab exists and has the correct class name.
    if (!$module->installJsErrorsTab()) {
        return false;
    }

    return true;
}
