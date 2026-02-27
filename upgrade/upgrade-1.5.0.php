<?php

function upgrade_module_1_5_0($module)
{
    return (
        $module->executeSqlScript('version_1_5_0') &&
        $module->registerHook('header') &&
        $module->installTab()
    );
}
