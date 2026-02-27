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
 * @param CollectLogs $module
 * @return bool
 * @throws PrestaShopDatabaseException
 * @throws PrestaShopException
 */
function upgrade_module_1_4_0($module)
{
    // Create/repair the JS error table.
    if (!$module->ensureJsErrorsTable()) {
        return false;
    }

    // Register FO hooks needed for JS error logging.
    $module->registerHook('header');
    $module->registerHook('displayHeader');

    // Ensure the JS Errors Back-Office tab exists and has the correct class name.
    if (!$module->installJsErrorsTab()) {
        return false;
    }

    return true;
}
