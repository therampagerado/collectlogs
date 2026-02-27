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
 * @throws PrestaShopException
 */
function upgrade_module_1_4_0($module)
{
    return $module->executeSqlScript('version_1_4_0');
}
