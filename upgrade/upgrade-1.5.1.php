<?php

function upgrade_module_1_5_1($module)
{
    return $module->executeSqlScript('version_1_5_1');
}
