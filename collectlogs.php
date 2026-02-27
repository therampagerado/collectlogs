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

use CollectLogsModule\Logger;
use CollectLogsModule\PsrLogger;
use CollectLogsModule\Settings;
use CollectLogsModule\Severity;
use CollectLogsModule\TransformMessage;
use CollectLogsModule\TransformMessageImpl;
use Thirtybees\Core\DependencyInjection\ServiceLocator;

require_once __DIR__ . '/classes/Settings.php';
require_once __DIR__ . '/classes/Severity.php';
require_once __DIR__ . '/classes/TransformMessage.php';
require_once __DIR__ . '/classes/TransformMessageImpl.php';

/**
 * Class CollectLogs
 */
class CollectLogs extends Module
{

    // configuration keys
    const INPUT_SEND_NEW_ERRORS_EMAIL = 'SEND_NEW_ERRORS_EMAIL';
    const INPUT_EMAIL_ADDRESSES = 'EMAIL_ADDRESSES';
    const INPUT_LOG_TO_FILE = 'LOG_TO_FILE';
    const INPUT_LOG_TO_FILE_NEW_ONLY = 'LOG_TO_FILE_NEW_ONLY';
    const INPUT_LOG_TO_FILE_SEVERITY = 'LOG_TO_FILE_SEVERITY';
    const INPUT_OLDER_THAN = 'OLDER_THAN_DAYS';
    const ACTION_DELETE_ALL = 'ACTION_DELETE_ALL';
    const ACTION_SUBMIT_SETTINGS = 'ACTION_SUBMIT_SETTINGS';
    const ACTION_DELETE_OLDER_THAN_DAYS = 'ACTION_DELETE_OLDER_THAN_DAYS';
    const ACTION_UNSUBSCRIBE = 'unsubscribe';
    const MIN_PHP_VERSION = '7.1';

    // JS error logging config keys
    const INPUT_JS_LOGGING_ENABLED = 'JS_LOGGING_ENABLED';
    const INPUT_JS_SAMPLING_RATE   = 'JS_SAMPLING_RATE';
    const INPUT_JS_MAX_EVENTS      = 'JS_MAX_EVENTS';
    const INPUT_JS_INCLUDE_QS      = 'JS_INCLUDE_QS';
    const INPUT_JS_INCLUDE_STACK   = 'JS_INCLUDE_STACK';
    const INPUT_JS_RETENTION_DAYS  = 'JS_RETENTION_DAYS';

    public function __construct()
    {
        $this->name = 'collectlogs';
        $this->tab = 'administaration';
        $this->version = '1.4.1';
        $this->author = 'thirty bees';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();
        $this->displayName = $this->l('Collect Logs');
        $this->description = $this->l('Debugging module that collects PHP and client-side JS logs');
        $this->ps_versions_compliancy = ['min' => '1.6', 'max' => '1.6.999'];
        $this->tb_min_version = '1.4.0';
        $this->controllers = ['cron', 'api', 'jslog'];
    }

    /**
     * @param bool $createTables
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function install($createTables = true)
    {
        $requirements = true;
        if (! $this->checkPhpVersion()) {
            $this->_errors[] = sprintf(Tools::displayError('This module requires PHP version %s or newer'), static::MIN_PHP_VERSION);
            $requirements = false;
        }
        if (! $this->systemSupportsLogger()) {
            $this->_errors[] = Tools::displayError('Your version of thirty bees does not support logger registration. Please update to never version of thirty bees');
            $requirements = false;
        }
        return (
            $requirements &&
            parent::install() &&
            $this->installTab() &&
            $this->installJsErrorsTab() &&
            $this->installDb($createTables) &&
            $this->registerHook('actionRegisterErrorHandlers') &&
            $this->registerHook('header') &&
            $this->registerHook('displayHeader')
        );
    }

    /**
     * @param bool $dropTables
     * @return bool
     * @throws PrestaShopException
     */
    public function uninstall($dropTables = true)
    {
        return (
            $this->removeTab() &&
            $this->getSettings()->cleanup() &&
            $this->uninstallDb($dropTables) &&
            parent::uninstall()
        );
    }

    /**
     * Install Back-office tab for JS error viewer.
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function installJsErrorsTab()
    {
        // Place the tab as a sibling of AdminCollectLogsBackend (same parent),
        // not nested under it — the TB BO menu does not render a 3rd level.
        $parentId = 0;
        $backendId = Tab::getIdFromClassName('AdminCollectLogsBackend');
        if ($backendId !== false) {
            $backendTab = new Tab((int)$backendId);
            $parentId = (int)$backendTab->id_parent;
        }
        if (!$parentId) {
            $parentId = $this->getTabParent();
        }

        $tab = new Tab();
        $tab->active     = 1;
        $tab->class_name = 'AdminCollectLogsJsErrors';
        $tab->module     = $this->name;
        $tab->id_parent  = $parentId;
        $tab->name       = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = $this->l('JS Error logs');
        }
        return $tab->add();
    }

    /**
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function reset()
    {
        return (
            $this->uninstall(false) &&
            $this->install(false)
        );
    }

    /**
     * @param bool $create
     * @return bool
     * @throws PrestaShopException
     */
    private function installDb($create)
    {
        if (! $create) {
            return true;
        }
        return $this->executeSqlScript('install');
    }

    /**
     * @param bool $drop
     * @return bool
     * @throws PrestaShopException
     */
    private function uninstallDb($drop)
    {
        if (! $drop) {
            return true;
        }
        return $this->executeSqlScript('uninstall', false);
    }

    /**
     * @return bool
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function installTab() {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminCollectLogsBackend';
        $tab->module = $this->name;
        $tab->id_parent = $this->getTabParent();
        $tab->name = array();
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = $this->l('Error logs');
        }
        return $tab->add();
    }

    /**
     * @return bool
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function removeTab() {
        $ret = true;
        foreach (Tab::getCollectionFromModule($this->name) as $tab) {
            $ret = $tab->delete() && $ret;
        }
        return $ret;
    }

    /**
     * @return int
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function getTabParent() {
        $id = Tab::getIdFromClassName('AdminTools');
        if ($id !== false) {
            return $id;
        }
        return 0;
    }

    /**
     * @param $script
     * @param bool $check
     * @return bool
     * @throws PrestaShopException
     */
    public function executeSqlScript($script, $check = true)
    {
        $file = dirname(__FILE__) . '/sql/' . $script . '.sql';
        if (!file_exists($file)) {
            PrestaShopLogger::addLog($this->name . ": sql script $file not found");
            return false;
        }
        $sql = file_get_contents($file);
        if (!$sql) {
            return false;
        }
        $sql = str_replace(['PREFIX_', 'ENGINE_TYPE', 'CHARSET_TYPE', 'COLLATE_TYPE'], [_DB_PREFIX_, _MYSQL_ENGINE_, 'utf8mb4', 'utf8mb4_unicode_ci'], $sql);
        $sql = preg_split("/;\s*[\r\n]+/", $sql);
        foreach ($sql as $statement) {
            $stmt = trim($statement);
            if ($stmt) {
                try {
                    if (!Db::getInstance()->execute($stmt)) {
                        PrestaShopLogger::addLog($this->name . ": sql script $script: $stmt: error");
                        if ($check) {
                            return false;
                        }
                    }
                } catch (Exception $e) {
                    PrestaShopLogger::addLog($this->name . ": sql script $script: $stmt: exception: $e");
                    if ($check) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /**
     * @return void
     */
    public function hookActionRegisterErrorHandlers()
    {
        if ($this->checkPhpVersion() && $this->systemSupportsLogger()) {
            require_once(__DIR__ . '/classes/Logger.php');
            $errorHandler = ServiceLocator::getInstance()->getErrorHandler();
            if (defined('Thirtybees\Core\Error\ErrorHandler::LEVEL_DEBUG')) {
                $logger = new Logger($this->getSettings(), $this->getTransformMessage());
            } else {
                // Special logger version for thirty bees 1.4 - it expects LoggerInterface
                require_once(__DIR__ . '/classes/PsrLogger.php');
                $logger = new PsrLogger($this->getSettings(), $this->getTransformMessage());
            }
            $errorHandler->addLogger($logger, true);
        }
    }

    /**
     * hookHeader / hookDisplayHeader – inject JS error capture script on FO pages.
     *
     * Both hooks are registered so the script is injected regardless of the
     * theme's hook naming convention.  Duplicate injection is prevented by a
     * static flag.
     *
     * @return void
     * @throws PrestaShopException
     */
    public function hookHeader()
    {
        $this->injectJsErrorScript();
    }

    /** @return void */
    public function hookDisplayHeader()
    {
        $this->injectJsErrorScript();
    }

    /**
     * Build a short-lived signed token and inject the JS error capture script.
     *
     * Token format:  base64url(id_shop:timestamp:nonce) . '.' . hmac_sha256
     *
     * @return void
     * @throws PrestaShopException
     */
    protected function injectJsErrorScript()
    {
        static $injected = false;
        if ($injected) {
            return;
        }
        $injected = true;

        $settings = $this->getSettings();
        if (!$settings->getJsLoggingEnabled()) {
            return;
        }

        $idShop = (int)$this->context->shop->id;
        $ts     = time();
        $nonce  = Tools::passwdGen(8);
        $rawPayload = $idShop . ':' . $ts . ':' . $nonce;
        $hmac       = hash_hmac('sha256', $rawPayload, _COOKIE_KEY_);
        $token      = strtr(base64_encode($rawPayload), '+/', '-_') . '.' . $hmac;

        $endpoint = $this->context->link->getModuleLink($this->name, 'jslog', [], true);

        $lang = $this->context->language;
        $currency = $this->context->currency;
        $controller = Tools::getValue('controller', '');

        $cfg = [
            'endpoint'     => $endpoint,
            'token'        => $token,
            'shopId'       => $idShop,
            'sampling'     => (int)$settings->getJsSamplingRate(),
            'maxEvents'    => (int)$settings->getJsMaxEvents(),
            'includeQS'    => (bool)$settings->getJsIncludeQueryString(),
            'includeStack' => (bool)$settings->getJsIncludeStackTrace(),
            'tags'         => [
                'tb_version'  => _TB_VERSION_,
                'theme'       => $this->context->shop->theme_name ?? '',
                'page_type'   => $controller,
                'controller'  => $controller,
                'currency'    => $currency ? $currency->iso_code : '',
                'lang'        => $lang ? $lang->iso_code : '',
            ],
        ];

        Media::addJsDef(['collectlogsJsCfg' => $cfg]);
        $this->context->controller->addJs($this->_path . 'views/js/collectlogs-jserrors.js');
    }

    /**
     * @return bool
     */
    protected function systemSupportsLogger()
    {
        if (class_exists('Thirtybees\Core\DependencyInjection\ServiceLocator')) {
            $serviceLocator = ServiceLocator::getInstance();
            return method_exists($serviceLocator, 'getErrorHandler');
        }
        return false;
    }

    /**
     * @return bool|int
     */
    protected function checkPhpVersion()
    {
        return version_compare(phpversion(), static::MIN_PHP_VERSION, '>=');
    }

    /**
     * @return string
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function getContent()
    {
        $this->processPost();

        $this->getTransformMessage()->synchronize();
        $settings = $this->getSettings();
        $cronUrl = $this->context->link->getModuleLink($this->name, 'cron', [
            'secure_key' => $settings->getSecret()
        ]);
        $errorsUrl = $this->context->link->getAdminLink('AdminCollectLogsBackend');
        $errorsTable = $this->getErrorsTable();
        $buttons = null;
        if ($errorsTable) {
            $description = Translate::ppTags(
                $this->l('List of collected errors and warnings can be found in Advaced Parameters > [1]Error Logs[/1]'),
                ['<a href="'.$errorsUrl.'">']
            );
            $buttons = [
                [
                    'type' => 'submit',
                    'class' => 'pull-right',
                    'icon' => 'process-icon-delete',
                    'title' => $this->l('Delete all'),
                    'name' => static::ACTION_DELETE_ALL,
                    'js' => 'if (confirm(\''.$this->l('Delete all error logs?').'\')){return true;}else{event.preventDefault();}',
                ]
            ];
        } else {
            $description = null;
        }
        $infoForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Error logs'),
                    'icon' => 'icon-list',
                ],
                'description' => $description,
                'input' => [
                    [
                        'name' => 'errors_table',
                        'type' => 'errors_table',
                        'errorTypes' => $errorsTable,
                    ]
                ],
                'buttons' => $buttons
            ],
        ];

        $fileLoggingForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('File logging'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Log to file'),
                        'desc' => $this->l('When enabled, errors will be saved inside log file as well'),
                        'name' => static::INPUT_LOG_TO_FILE,
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            ],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Log only new errors'),
                        'desc' => $this->l('If enabled, only new error messages will be saved in error files'),
                        'name' => static::INPUT_LOG_TO_FILE_NEW_ONLY,
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            ],
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Severity level'),
                        'desc' => $this->l('Select minimal severity level to log'),
                        'name' => static::INPUT_LOG_TO_FILE_SEVERITY,
                        'options' => [
                            'id' => 'severity',
                            'name' => 'name',
                            'query' => [
                                [ 'severity' => Severity::SEVERITY_ERROR, 'name' => Severity::getSeverityName(Severity::SEVERITY_ERROR) ],
                                [ 'severity' => Severity::SEVERITY_WARNING, 'name' => Severity::getSeverityName(Severity::SEVERITY_WARNING) ],
                                [ 'severity' => Severity::SEVERITY_DEPRECATION, 'name' => Severity::getSeverityName(Severity::SEVERITY_DEPRECATION) ],
                                [ 'severity' => Severity::SEVERITY_NOTICE, 'name' => Severity::getSeverityName(Severity::SEVERITY_NOTICE) ],
                            ]
                        ],
                    ],

                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'name' => static::ACTION_SUBMIT_SETTINGS,
                ],
            ],
        ];

        $cronForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Cron Settings'),
                    'icon' => 'icon-cogs',
                ],
                'description' => $this->l('You can enable cron job that will send you summarized email with newly detected errors.'),
                'input' => [
                    [
                        'type' => 'html',
                        'label' => $this->l('Cron URL'),
                        'name' => 'COLLECTLOGS_CRON_URL',
                        'desc' => $this->l('Copy and paste this URL to your cron manager. Recommended frequency is every 15 minutes.'),
                        'html_content' => "<code style='display:block;margin-top:7px'>$cronUrl</code>",
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Send email with new errors summary'),
                        'desc' => $this->l('When enabled, cron job will send email with new detected errors'),
                        'name' => static::INPUT_SEND_NEW_ERRORS_EMAIL,
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            ],
                        ],
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Email addressess'),
                        'rows' => 3,
                        'name' => static::INPUT_EMAIL_ADDRESSES,
                        'desc' => $this->l('Email addresses of people that should receive email with new errors. Enter each address on separate line!'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'name' => static::ACTION_SUBMIT_SETTINGS,
                ],
            ],
        ];

        /** @var AdminController $controller */
        $controller = $this->context->controller;

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = (int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');

        $helper->identifier = $this->identifier;
        $helper->submit_action = '';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->languages = $controller->getLanguages();
        $jsErrorsUrl = $this->context->link->getAdminLink('AdminCollectLogsJsErrors');

        $jsLoggingForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Client-side JS Error Logging'),
                    'icon'  => 'icon-bug',
                ],
                'description' => Translate::ppTags(
                    $this->l('When enabled, a small script is injected on every FO page to capture runtime JS errors and send them to [1]JS Error Logs[/1].'),
                    ['<a href="' . $jsErrorsUrl . '">']
                ),
                'input' => [
                    [
                        'type'    => 'switch',
                        'label'   => $this->l('Enable JS error logging'),
                        'desc'    => $this->l('Inject the capture script on all front-office pages'),
                        'name'    => static::INPUT_JS_LOGGING_ENABLED,
                        'is_bool' => true,
                        'values'  => [
                            ['id' => 'js_on',  'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'js_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('Sampling rate (%)'),
                        'desc'  => $this->l('Percentage of page sessions that will capture errors. 100 = all sessions, 10 = 10% of sessions.'),
                        'name'  => static::INPUT_JS_SAMPLING_RATE,
                        'class' => 'fixed-width-sm',
                        'suffix' => '%',
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('Max events per page'),
                        'desc'  => $this->l('Maximum number of JS error events captured per page view (1–100).'),
                        'name'  => static::INPUT_JS_MAX_EVENTS,
                        'class' => 'fixed-width-sm',
                    ],
                    [
                        'type'    => 'switch',
                        'label'   => $this->l('Include URL query strings'),
                        'desc'    => $this->l('When disabled (recommended), query strings are stripped from reported URLs to protect visitor privacy.'),
                        'name'    => static::INPUT_JS_INCLUDE_QS,
                        'is_bool' => true,
                        'values'  => [
                            ['id' => 'qs_on',  'value' => 1, 'label' => $this->l('Include')],
                            ['id' => 'qs_off', 'value' => 0, 'label' => $this->l('Strip')],
                        ],
                    ],
                    [
                        'type'    => 'switch',
                        'label'   => $this->l('Include stack traces'),
                        'desc'    => $this->l('Send parsed stack frames with each error event. Recommended for debugging.'),
                        'name'    => static::INPUT_JS_INCLUDE_STACK,
                        'is_bool' => true,
                        'values'  => [
                            ['id' => 'stack_on',  'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'stack_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('Retention (days)'),
                        'desc'  => $this->l('JS error records older than this many days are deleted when "Prune old records" is clicked in the JS Error Logs screen. Set to 0 to disable auto-pruning.'),
                        'name'  => static::INPUT_JS_RETENTION_DAYS,
                        'class' => 'fixed-width-sm',
                        'suffix' => $this->l('days'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'name'  => static::ACTION_SUBMIT_SETTINGS,
                ],
            ],
        ];

        $helper->fields_value = [
            static::INPUT_SEND_NEW_ERRORS_EMAIL => $settings->getSendNewErrorsEmail(),
            static::INPUT_EMAIL_ADDRESSES => implode("\n", $settings->getEmailAddresses()),
            static::INPUT_LOG_TO_FILE => $settings->getLogToFile(),
            static::INPUT_LOG_TO_FILE_NEW_ONLY => $settings->getLogToFileNewOnly(),
            static::INPUT_LOG_TO_FILE_SEVERITY => $settings->getLogToFileMinSeverity(),
            // JS logging
            static::INPUT_JS_LOGGING_ENABLED => $settings->getJsLoggingEnabled(),
            static::INPUT_JS_SAMPLING_RATE   => $settings->getJsSamplingRate(),
            static::INPUT_JS_MAX_EVENTS      => $settings->getJsMaxEvents(),
            static::INPUT_JS_INCLUDE_QS      => $settings->getJsIncludeQueryString(),
            static::INPUT_JS_INCLUDE_STACK   => $settings->getJsIncludeStackTrace(),
            static::INPUT_JS_RETENTION_DAYS  => $settings->getJsRetentionDays(),
        ];

        return $helper->generateForm([
            $infoForm,
            $fileLoggingForm,
            $cronForm,
            $jsLoggingForm,
        ]);
    }

    /**
     * @return void
     *
     * @throws PrestaShopException
     */
    public function processCron()
    {
        $settings = $this->getSettings();
        if (! headers_sent()) {
            header('Content-Type: text/plain');
        }

        $this->getTransformMessage()->synchronize();

        if (! $settings->getSendNewErrorsEmail()) {
            echo "Sending emails with new errors is disabled in module settings, exiting...\n";
            return;
        }
        $emailAddresses = $settings->getEmailAddresses();
        if (! $emailAddresses) {
            echo "No email address specified, exiting...\n";
            return;
        }
        $lastExec = $settings->getCronLastExec();
        $settings->updateCronLastExec();
        $from = date('Y-m-d H:i:s', $lastExec);
        echo "Retrieving new errors since " . $from . "\n";

        $conn = Db::getInstance();
        $rows = $conn->getArray((new DbQuery())
            ->select('*')
            ->from('collectlogs_logs')
            ->where('date_add >= \'' .$from . '\'')
            ->orderBy('id_collectlogs_logs')
        );
        if (! $rows) {
            echo "No new errors, exiting...\n";
            return;
        }

        echo "Found " . count($rows) ." new errors:\n";

        $errorsTxt = "";
        $errorsHtml = "";
        foreach ($rows as $row) {
            $id = (int)$row['id_collectlogs_logs'];
            $dateAdd = $row['date_add'];
            $type = $row['type'];
            $message = $row['sample_message'];
            $file = $row['file'];
            $realFile = $row['real_file'];
            $realLine = $row['real_line'];
            $line = $row['line'];
            $seen = (int)$conn->getValue((new DbQuery())
                ->select("SUM(`count`) as cnt")
                ->from('collectlogs_stats')
                ->where("id_collectlogs_logs = $id")
            );

            $errorsHtmlDescription = "<div>";
            $errorsHtmlDescription .= "<h3>[$type] $message</h3>";
            $errorTxtDescription = "  - [$type] ";
            $errorTxtDescription .= $message;
            $errorTxtDescription .= "\n    in " . $row['file'];
            $errorsHtmlDescription .= "<div>in file <code>$file</code>";
            if ($realFile) {
                $errorTxtDescription .= " (" . $realFile . ':' . $realLine . ")";
                $errorsHtmlDescription .= " <span>(<code>$realFile:$realLine</code>)</span>";
            } else {
                $errorTxtDescription .= ":" . $line;
                $errorsHtmlDescription .= "<code>:$line</code>";
            }
            $errorsHtmlDescription .= "</div>";
            $errorsHtmlDescription .= "<div>Seen <b>$seen</b> times since $dateAdd</div>";
            $errorTxtDescription .= "\n    Seen $seen times since $dateAdd";

            $extras = $conn->getArray((new DbQuery())
                ->select('*')
                ->from('collectlogs_extra')
                ->where('id_collectlogs_logs = ' . $id)
            );

            foreach ($extras as $section) {
                $errorTxtDescription .= "\n    " . $section['label'];
                $errorTxtDescription .= "\n    " . trim(str_replace("\n", "\n    ", $section['content']));
                $errorsHtmlDescription .= "<div><h5>".$section['label']."</h5><code><pre>".$section['content']."</pre></code></div>";
            }
            $errorTxtDescription .= "\n";
            $errorsHtmlDescription .= "</div>";
            $errorsTxt .= $errorTxtDescription;
            $errorsHtml .= $errorsHtmlDescription;
        }

        echo $errorsTxt . "\n";

        foreach ($emailAddresses as $emailAddress) {
            $unsubscribeUrl = $this->context->link->getModuleLink($this->name, 'api', [
                'action' => static::ACTION_UNSUBSCRIBE,
                'email' => $emailAddress,
                'secret' => $settings->getUnsubscribeSecret($emailAddress),
            ]);

            Mail::send(
                Configuration::get('PS_LANG_DEFAULT'),
                'collectlogs-errors',
                Mail::l('New errors detected'),
                [
                    '{errorsTxt}' => $errorsTxt,
                    '{errorsHtml}' => $errorsHtml,
                    '{unsubscribeUrl}' => $unsubscribeUrl,
                ],
                $emailAddress,
                null,
                null,
                null,
                null,
                null,
                dirname(__FILE__) . '/mails/'
            );
        }
    }

    /**
     * @param string $string
     * @return array
     */
    protected static function extractValidEmails($string)
    {
        if (!is_string($string) || !$string) {
            return [];
        }
        return array_filter(array_map('trim', explode("\n", $string)), function($addr) {
            if (! $addr) {
                return false;
            }
            return Validate::isEmail($addr);
        });
    }

    /**
     * @return Settings
     */
    public function getSettings()
    {
        static $settings = null;
        if ($settings === null) {
            $settings = new Settings();
        }
        return $settings;
    }

    /**
     * @return void
     * @throws PrestaShopException
     */
    protected function processPost()
    {
        /** @var AdminController $controller */
        $controller = $this->context->controller;

        if (Tools::isSubmit(static::ACTION_DELETE_ALL)) {
            $total = $this->deleteAll();
            $this->getTransformMessage()->synchronize(true);
            $this->setRedirectionAfterDeletion($controller, $total);
        } elseif (Tools::isSubmit(static::ACTION_DELETE_OLDER_THAN_DAYS)) {
            $olderThan = (int)Tools::getValue(static::INPUT_OLDER_THAN);
            $total = $this->deleteOlderThan($olderThan);
            $this->getTransformMessage()->synchronize(true);
            $this->setRedirectionAfterDeletion($controller, $total);
        } elseif (Tools::isSubmit(static::ACTION_SUBMIT_SETTINGS)) {
            $settings = $this->getSettings();
            $settings->setSendNewErrorsEmail((bool)Tools::getValue(static::INPUT_SEND_NEW_ERRORS_EMAIL));
            $settings->setEmailAddresses(static::extractValidEmails(Tools::getValue(static::INPUT_EMAIL_ADDRESSES)));
            $settings->setLogToFile((bool)Tools::getValue(static::INPUT_LOG_TO_FILE));
            $settings->setLogToFileNewOnly((bool)Tools::getValue(static::INPUT_LOG_TO_FILE_NEW_ONLY));
            $settings->setLogToFileMinSeverity((int)Tools::getValue(static::INPUT_LOG_TO_FILE_SEVERITY));
            // JS logging settings
            $settings->setJsLoggingEnabled((bool)Tools::getValue(static::INPUT_JS_LOGGING_ENABLED));
            $settings->setJsSamplingRate((int)Tools::getValue(static::INPUT_JS_SAMPLING_RATE));
            $settings->setJsMaxEvents((int)Tools::getValue(static::INPUT_JS_MAX_EVENTS));
            $settings->setJsIncludeQueryString((bool)Tools::getValue(static::INPUT_JS_INCLUDE_QS));
            $settings->setJsIncludeStackTrace((bool)Tools::getValue(static::INPUT_JS_INCLUDE_STACK));
            $settings->setJsRetentionDays((int)Tools::getValue(static::INPUT_JS_RETENTION_DAYS));
            $this->getTransformMessage()->synchronize(true);
            $controller->confirmations[] = $this->l('Settings saved');
        }
    }

    /**
     * @return array
     *
     * @throws PrestaShopException
     */
    protected function getErrorsTable()
    {
        $conn = Db::getInstance();
        $sql = (new DbQuery())
            ->select('e.type, e.severity, COUNT(1) as cnt')
            ->from('collectlogs_logs', 'e')
            ->orderBy('e.severity DESC, e.type ASC')
            ->groupBy('e.type, e.severity');
        return array_map(function($row) {
            $row['badge'] = Severity::getSeverityBadge($row['severity']);
            $row['link'] = Context::getContext()->link->getAdminLink('AdminCollectLogsBackend', true, [], [
                'type' => $row['type']
            ]);
            return $row;
        }, $conn->getArray($sql));
    }

    /**
     * @param int $olderThan
     *
     * @return int
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function deleteOlderThan($olderThan)
    {
        $olderThan = (int)$olderThan;
        $db = Db::getInstance();
        $sql = (new DbQuery())
            ->select('DISTINCT l.id_collectlogs_logs as id')
            ->from('collectlogs_logs', 'l')
            ->innerJoin('collectlogs_stats', 's', '(s.id_collectlogs_logs = l.id_collectlogs_logs)')
            ->groupBy('l.id_collectlogs_logs')
            ->having('COALESCE(DATEDIFF(NOW(), MAX(s.`dimension`)), 0) >= ' . $olderThan);
        $ids = array_filter(array_map('intval', array_column($db->getArray($sql), 'id')));
        if ($ids) {
            $imploded = implode(',', $ids);
            $where = "id_collectlogs_logs IN ($imploded)";
            $db->delete('collectlogs_logs', $where);
            $db->delete('collectlogs_extra', $where);
            $db->delete('collectlogs_stats', $where);
        }
        return count($ids);
    }

    /**
     * @return int
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function deleteAll()
    {
        $db = Db::getInstance();
        $cnt = (int)$db->getValue((new DbQuery())->select("COUNT(1)")->from('collectlogs_logs'));
        $db->delete('collectlogs_logs');
        $db->delete('collectlogs_extra');
        $db->delete('collectlogs_stats');
        return $cnt;
    }

    /**
     * @param AdminController $controller
     * @param int $total
     *
     * @return void
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function setRedirectionAfterDeletion($controller, $total)
    {
        $total = (int)$total;
        $link = $this->context->link;
        if ($total) {
            $controller->confirmations[] = sprintf($this->l('%s error logs deleted'), $total);
        } else {
            $controller->warnings[] = $this->l('No error log deleted');
        }
        $controller->setRedirectAfter($link->getAdminLink('AdminModules', true, [
            'configure' => 'collectlogs',
            'module_name' => 'collectlogs',
        ]));
    }

    /**
     * @return TransformMessage
     */
    public function getTransformMessage()
    {
        static $transform = null;
        if ($transform === null) {
            $transform = new TransformMessageImpl($this->getSettings());
        }
        return $transform;
    }
}
