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
 * upgrade-1.4.0.php).  All steps are guarded so running this on a store that
 * already completed the 1.4.0 upgrade is a safe no-op.
 *
 * @param CollectLogs $module
 * @return bool
 * @throws PrestaShopDatabaseException
 * @throws PrestaShopException
 */
function upgrade_module_1_4_1($module)
{
    // Create JS error table (IF NOT EXISTS — safe to re-run)
    if (!$module->executeSqlScript('version_1_4_0')) {
        return false;
    }

    // Register FO hooks required for JS error capture (idempotent)
    $module->registerHook('header');
    $module->registerHook('displayHeader');

    // Add the JS errors BO tab as a sibling of AdminCollectLogsBackend
    if (Tab::getIdFromClassName('AdminCollectLogsJsErrors') === false) {
        $parentId = 0;
        $backendId = Tab::getIdFromClassName('AdminCollectLogsBackend');
        if ($backendId !== false) {
            $backendTab = new Tab((int)$backendId);
            $parentId = (int)$backendTab->id_parent;
        }
        if (!$parentId) {
            $parentId = (int)Tab::getIdFromClassName('AdminTools');
        }

        $tab = new Tab();
        $tab->active     = 1;
        $tab->class_name = 'AdminCollectLogsJsErrors';
        $tab->module     = $module->name;
        $tab->id_parent  = $parentId;
        $tab->name       = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'JS Error logs';
        }
        if (!$tab->add()) {
            return false;
        }
    }

    return true;
}
