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
    // Create the new JS error table
    if (!$module->executeSqlScript('version_1_4_0')) {
        return false;
    }

    // Register FO hooks needed for JS error logging
    $module->registerHook('header');
    $module->registerHook('displayHeader');

    // Add the JS errors Back-Office tab if it does not already exist
    if (Tab::getIdFromClassName('AdminCollectLogsJsErrors') === false) {
        $parentId = Tab::getIdFromClassName('AdminCollectLogsBackend');
        if ($parentId === false) {
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
