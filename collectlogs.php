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

    public function __construct()
    {
        $this->name = 'collectlogs';
        $this->tab = 'administaration';
        $this->version = '1.3.1';
        $this->author = 'thirty bees';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();
        $this->displayName = $this->l('Collect PHP Logs');
        $this->description = $this->l('Debugging module that collects PHP logs');
        $this->ps_versions_compliancy = ['min' => '1.6', 'max' => '1.6.999'];
        $this->tb_min_version = '1.4.0';
        $this->controllers = ['cron', 'api'];
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
            $this->installDb($createTables) &&
            $this->registerHook('actionRegisterErrorHandlers')
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
        $helper->fields_value = [
            static::INPUT_SEND_NEW_ERRORS_EMAIL => $settings->getSendNewErrorsEmail(),
            static::INPUT_EMAIL_ADDRESSES => implode("\n", $settings->getEmailAddresses()),
            static::INPUT_LOG_TO_FILE => $settings->getLogToFile(),
            static::INPUT_LOG_TO_FILE_NEW_ONLY => $settings->getLogToFileNewOnly(),
            static::INPUT_LOG_TO_FILE_SEVERITY => $settings->getLogToFileMinSeverity(),
        ];

        return $helper->generateForm([
            $infoForm,
            $fileLoggingForm,
            $cronForm,
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
