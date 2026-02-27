<?php

/**
 * @param CollectLogs $module
 * @return bool
 * @throws PrestaShopException
 */
function upgrade_module_1_5_0($module)
{
    $module->executeSqlScript('version_1_5_0');
    $module->installTab();
    $module->registerHook('header');
    return true;
}
