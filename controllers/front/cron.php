<?php
/**
 * Copyright (C) 2017-2024 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <modules@thirtybees.com>
 * @copyright 2017-2024 thirty bees
 * @license   Academic Free License (AFL 3.0)
 */

if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/../../../../config/config.inc.php';
    try {
        /** @var CollectLogs | false $module */
        $module = Module::getInstanceByName('collectlogs');
        if ($module) {
            $module->processCron();
        } else {
            die("Failed to instantiate collectlogs module");
        }
    } catch (Exception $e) {
        die("Failed to start cron: ".$e);
    }
    exit;
}

class CollectLogsCronModuleFrontController extends ModuleFrontController
{

    /** @var CollectLogs */
    public $module;

    /**
     * @throws PrestaShopException
     */
    public function initContent()
    {
        if (Tools::getValue('secure_key') === $this->module->getSettings()->getSecret()) {
            @set_time_limit(0);
            $interactive = !!Tools::getValue('sync');
            if (! $interactive) {
                ob_start();

                echo $this->module->name . " cron\n";
                header('Connection: close');
                header('Content-Length: ' . ob_get_length());

                // flush
                if (ob_get_length() > 0) {
                    ob_end_flush();
                }
                flush();

                // abort
                ignore_user_abort(true);
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
            }
            $this->module->processCron();
            die();
        }
        die('error');
    }

}
