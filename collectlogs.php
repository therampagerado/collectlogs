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
    const INPUT_CLIENT_LOGGING_ENABLED = 'CLIENT_LOGGING_ENABLED';
    const INPUT_CLIENT_LOGGING_SAMPLE_RATE = 'CLIENT_LOGGING_SAMPLE_RATE';
    const INPUT_CLIENT_LOGGING_MAX_EVENTS = 'CLIENT_LOGGING_MAX_EVENTS';
    const INPUT_CLIENT_LOGGING_INCLUDE_QUERY = 'CLIENT_LOGGING_INCLUDE_QUERY';
    const INPUT_CLIENT_LOGGING_INCLUDE_STACK = 'CLIENT_LOGGING_INCLUDE_STACK';
    const INPUT_CLIENT_LOGGING_EXCLUDE_BOTS = 'CLIENT_LOGGING_EXCLUDE_BOTS';
    const INPUT_CLIENT_LOGGING_RETENTION_DAYS = 'CLIENT_LOGGING_RETENTION_DAYS';
    const ACTION_DELETE_ALL = 'ACTION_DELETE_ALL';
    const ACTION_SUBMIT_SETTINGS = 'ACTION_SUBMIT_SETTINGS';
    const ACTION_DELETE_OLDER_THAN_DAYS = 'ACTION_DELETE_OLDER_THAN_DAYS';
    const ACTION_UNSUBSCRIBE = 'unsubscribe';
    const MIN_PHP_VERSION = '7.1';

    public function __construct()
    {
        $this->name = 'collectlogs';
        $this->tab = 'administaration';
        $this->version = '1.5.0';
        $this->author = 'thirty bees';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();
        $this->displayName = $this->l('Collect PHP Logs');
        $this->description = $this->l('Debugging module that collects PHP logs');
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
            $this->installDb($createTables) &&
            $this->registerHook('actionRegisterErrorHandlers') &&
            $this->registerHook('header')
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
    public function installTab() {
        foreach ([
            'AdminCollectLogsBackend' => $this->l('Error logs'),
            'AdminCollectLogsJsBackend' => $this->l('Client JS logs'),
        ] as $className => $name) {
            if (Tab::getIdFromClassName($className)) {
                continue;
            }
            $tab = new Tab();
            $tab->active = 1;
            $tab->class_name = $className;
            $tab->module = $this->name;
            $tab->id_parent = $this->getTabParent();
            $tab->name = array();
            foreach (Language::getLanguages(true) as $lang) {
                $tab->name[$lang['id_lang']] = $name;
            }
            if (! $tab->add()) {
                return false;
            }
        }
        return true;
    }

    public function hookHeader()
    {
        if (! $this->getSettings()->getClientLoggingEnabled()) {
            return;
        }
        if ($this->shouldSkipClientLoggingForCurrentRequest()) {
            return;
        }
        $config = $this->getClientLoggingConfig();
        Media::addJsDef([
            'collectlogsClientConfig' => $config,
        ]);
        $this->context->controller->addJS($this->_path . 'views/js/collectlogs-client.js');
    }

    public function createClientLogToken()
    {
        $payload = [
            'sid' => (int)$this->context->shop->id,
            'ts' => time(),
            'nonce' => Tools::passwdGen(12),
        ];
        $body = $this->encodeClientLogTokenPayload($payload);
        $sig = sha1($body . '|' . _COOKIE_KEY_);
        return $body . '.' . $sig;
    }

    public function validateClientLogToken($token)
    {
        if (!is_string($token) || strpos($token, '.') === false) {
            return false;
        }
        list($body, $sig) = explode('.', $token, 2);
        if (! hash_equals(sha1($body . '|' . _COOKIE_KEY_), (string)$sig)) {
            return false;
        }
        $payload = $this->decodeClientLogTokenPayload($body);
        if (!is_array($payload) || (int)$payload['sid'] !== (int)$this->context->shop->id) {
            return false;
        }
        $ts = isset($payload['ts']) ? (int)$payload['ts'] : 0;
        return $ts > (time() - 7200) && $ts < (time() + 300);
    }

    /**
     * @param array $payload
     * @return string
     */
    protected function encodeClientLogTokenPayload(array $payload)
    {
        return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    }

    /**
     * @param string $payload
     * @return array|null
     */
    protected function decodeClientLogTokenPayload($payload)
    {
        $payload = (string)$payload;
        if ($payload === '') {
            return null;
        }
        $pad = strlen($payload) % 4;
        if ($pad > 0) {
            $payload .= str_repeat('=', 4 - $pad);
        }
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if (! is_string($json) || $json === '') {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @return array
     */
    protected function getClientLoggingConfig()
    {
        $controller = isset($this->context->controller->php_self) ? $this->context->controller->php_self : '';
        $theme = isset($this->context->shop->theme_name) ? $this->context->shop->theme_name : '';
        $pageContext = $this->getClientPageContext();

        return [
            'endpoint' => $this->context->link->getModuleLink($this->name, 'jslog', [], true),
            'token' => $this->createClientLogToken(),
            'visitorStorageKey' => 'collectlogs-visitor-' . (int)$this->context->shop->id,
            'shopId' => (int)$this->context->shop->id,
            'sampleRate' => (int)$this->getSettings()->getClientLoggingSampleRate(),
            'maxEventsPerPage' => (int)$this->getSettings()->getClientLoggingMaxEvents(),
            'includeQueryString' => (bool)$this->getSettings()->getClientLoggingIncludeQueryString(),
            'includeStackTrace' => (bool)$this->getSettings()->getClientLoggingIncludeStackTrace(),
            'pageContext' => $pageContext,
            'tags' => [
                'build_id' => $this->getClientBuildId($theme),
                'tb_version' => _TB_VERSION_,
                'tb_revision' => $this->getClientTbRevision(),
                'module_version' => $this->version,
                'theme' => $theme,
                'controller' => $controller,
                'page_type' => $pageContext['page_type'],
                'product_id' => $pageContext['product_id'],
                'category_id' => $pageContext['category_id'],
                'combination_id' => $pageContext['combination_id'],
                'cart_count' => $pageContext['cart_count'],
                'lang' => isset($this->context->language->iso_code) ? $this->context->language->iso_code : null,
                'currency' => isset($this->context->currency->iso_code) ? $this->context->currency->iso_code : null,
                'login_state' => $pageContext['login_state'],
            ],
        ];
    }

    /**
     * @param string $theme
     * @return string
     */
    protected function getClientBuildId($theme)
    {
        $parts = [];

        $parts[] = 'tb-' . $this->normalizeBuildIdPart(_TB_VERSION_);
        $tbRevision = $this->getClientTbRevision();
        if ($tbRevision !== '') {
            $parts[] = 'tb-revision-' . $this->normalizeBuildIdPart($tbRevision);
        }
        $parts[] = 'collectlogs-' . $this->normalizeBuildIdPart($this->version);

        $theme = trim((string)$theme);
        if ($theme !== '') {
            $themeVersion = $this->detectThemeVersion($theme);
            $themePart = 'theme-' . $this->normalizeBuildIdPart($theme);
            if ($themeVersion !== '') {
                $themePart .= '-v' . $this->normalizeBuildIdPart($themeVersion);
            }
            $parts[] = $themePart;
        }

        $clientFile = __DIR__ . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'collectlogs-client.js';
        if (is_file($clientFile)) {
            $mtime = @filemtime($clientFile);
            if (is_int($mtime) && $mtime > 0) {
                $parts[] = 'client-' . strtolower(base_convert((string)$mtime, 10, 36));
            }
        }

        return implode('|', $parts);
    }

    /**
     * @return string
     */
    protected function getClientTbRevision()
    {
        if (!defined('_TB_REVISION_')) {
            return '';
        }

        $value = trim((string)_TB_REVISION_);
        return $value !== '' ? $value : '';
    }

    /**
     * @param string $value
     * @return string
     */
    protected function normalizeBuildIdPart($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return 'na';
        }

        $value = preg_replace('/[^a-z0-9._-]+/i', '-', $value);
        $value = preg_replace('/-+/', '-', (string)$value);
        $value = trim((string)$value, '-');

        return $value !== '' ? $value : 'na';
    }

    /**
     * @return array
     */
    protected function getClientPageContext()
    {
        return [
            'page_type' => isset($this->context->controller->php_self) ? (string)$this->context->controller->php_self : '',
            'product_id' => $this->normalizeClientContextId(Tools::getValue('id_product')),
            'category_id' => $this->normalizeClientContextId(Tools::getValue('id_category')),
            'combination_id' => $this->normalizeClientContextId(Tools::getValue('id_product_attribute')),
            'cart_count' => $this->getClientCartCount(),
            'login_state' => $this->getClientLoginState(),
            'lang' => isset($this->context->language->iso_code) ? (string)$this->context->language->iso_code : '',
            'currency' => isset($this->context->currency->iso_code) ? (string)$this->context->currency->iso_code : '',
        ];
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    protected function normalizeClientContextId($value)
    {
        $value = (int)$value;
        return $value > 0 ? $value : null;
    }

    /**
     * @return int
     */
    protected function getClientCartCount()
    {
        if (!isset($this->context->cart) || !is_object($this->context->cart)) {
            return 0;
        }

        if (method_exists($this->context->cart, 'nbProducts')) {
            return (int)$this->context->cart->nbProducts();
        }

        if (method_exists($this->context->cart, 'getProducts')) {
            $products = $this->context->cart->getProducts();
            return is_array($products) ? count($products) : 0;
        }

        return 0;
    }

    /**
     * @return string
     */
    protected function getClientLoginState()
    {
        if (isset($this->context->customer) && is_object($this->context->customer)) {
            if (method_exists($this->context->customer, 'isLogged') && $this->context->customer->isLogged()) {
                return 'logged_in';
            }

            if (!empty($this->context->customer->id)) {
                return 'logged_in';
            }
        }

        return 'guest';
    }

    /**
     * @param string $theme
     * @return string
     */
    protected function detectThemeVersion($theme)
    {
        $candidates = [
            _PS_ALL_THEMES_DIR_ . $theme . DIRECTORY_SEPARATOR . 'config.xml',
            _PS_ALL_THEMES_DIR_ . $theme . DIRECTORY_SEPARATOR . 'theme.yml',
        ];

        foreach ($candidates as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            $content = @file_get_contents($file);
            if (!is_string($content) || $content === '') {
                continue;
            }

            if (preg_match('/<version>\s*([^<]+)\s*<\/version>/i', $content, $matches)) {
                return trim((string)$matches[1]);
            }

            if (preg_match('/^\s*version\s*:\s*(.+)$/mi', $content, $matches)) {
                return trim((string)$matches[1], " \t\n\r\0\x0B'\"");
            }
        }

        return '';
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
        $jsErrorsUrl = $this->context->link->getAdminLink('AdminCollectLogsJsBackend');
        $crawlerDetectorAvailable = $this->isCrawlerDetectorAvailable();
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
                    ],
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

        $jsLoggingInputs = [
            [
                'type' => 'switch',
                'label' => $this->l('Enable client-side JS logging'),
                'name' => static::INPUT_CLIENT_LOGGING_ENABLED,
                'is_bool' => true,
                'values' => [
                    ['id' => 'cl_enabled_on', 'value' => 1, 'label' => $this->l('Enabled')],
                    ['id' => 'cl_enabled_off', 'value' => 0, 'label' => $this->l('Disabled')],
                ],
            ],
            [
                'type' => 'text',
                'label' => $this->l('Sampling rate'),
                'name' => static::INPUT_CLIENT_LOGGING_SAMPLE_RATE,
                'class' => 'fixed-width-sm',
                'suffix' => '%',
            ],
            [
                'type' => 'text',
                'label' => $this->l('Max events per page'),
                'name' => static::INPUT_CLIENT_LOGGING_MAX_EVENTS,
                'class' => 'fixed-width-sm',
            ],
            [
                'type' => 'switch',
                'label' => $this->l('Include query string'),
                'name' => static::INPUT_CLIENT_LOGGING_INCLUDE_QUERY,
                'is_bool' => true,
                'values' => [
                    ['id' => 'cl_q_on', 'value' => 1, 'label' => $this->l('Yes')],
                    ['id' => 'cl_q_off', 'value' => 0, 'label' => $this->l('No')],
                ],
            ],
            [
                'type' => 'switch',
                'label' => $this->l('Include stack trace'),
                'name' => static::INPUT_CLIENT_LOGGING_INCLUDE_STACK,
                'is_bool' => true,
                'values' => [
                    ['id' => 'cl_s_on', 'value' => 1, 'label' => $this->l('Yes')],
                    ['id' => 'cl_s_off', 'value' => 0, 'label' => $this->l('No')],
                ],
            ],
            [
                'type' => 'switch',
                'label' => $this->l('Exclude detected crawlers'),
                'name' => static::INPUT_CLIENT_LOGGING_EXCLUDE_BOTS,
                'is_bool' => true,
                'disabled' => !$crawlerDetectorAvailable,
                'desc' => $crawlerDetectorAvailable
                    ? $this->l('Uses the Detect crawlers module (`tbdetectcrawler`) to skip storing client-side JS logs for detected bots.')
                    : $this->l('This option is available after the Detect crawlers module (`tbdetectcrawler`) is installed and enabled.'),
                'values' => [
                    ['id' => 'cl_bots_on', 'value' => 1, 'label' => $this->l('Yes')],
                    ['id' => 'cl_bots_off', 'value' => 0, 'label' => $this->l('No')],
                ],
            ],
            [
                'type' => 'text',
                'label' => $this->l('Retention'),
                'name' => static::INPUT_CLIENT_LOGGING_RETENTION_DAYS,
                'class' => 'fixed-width-sm',
                'suffix' => $this->l('days'),
                'desc' => $this->l('Old client-side JS logs are pruned by the module cron. Set to 0 to disable pruning. Activate the module cron URL for this retention setting to take effect.'),
            ],
        ];

        if (!$crawlerDetectorAvailable) {
            array_splice($jsLoggingInputs, 1, 0, [[
                'type' => 'html',
                'label' => $this->l('Crawler detection'),
                'name' => 'COLLECTLOGS_CLIENT_LOGGING_EXCLUDE_BOTS_NOTICE',
                'html_content' => "<div class='alert alert-info' style='margin-bottom:0;'>"
                    . $this->l('Install and enable the Detect crawlers module (`tbdetectcrawler`) to enable bot exclusion for client-side JS logs.')
                    . '</div>',
            ]]);
        }

        $jsLoggingForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Client-side JS logging'),
                    'icon' => 'icon-code',
                ],
                'description' => Translate::ppTags(
                    $this->l('Captured browser-side JavaScript errors are available in [1]Client JS logs[/1].'),
                    ['<a href="'.$jsErrorsUrl.'">']
                ),
                'input' => $jsLoggingInputs,
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
            static::INPUT_CLIENT_LOGGING_ENABLED => $settings->getClientLoggingEnabled(),
            static::INPUT_CLIENT_LOGGING_SAMPLE_RATE => $settings->getClientLoggingSampleRate(),
            static::INPUT_CLIENT_LOGGING_MAX_EVENTS => $settings->getClientLoggingMaxEvents(),
            static::INPUT_CLIENT_LOGGING_INCLUDE_QUERY => $settings->getClientLoggingIncludeQueryString(),
            static::INPUT_CLIENT_LOGGING_INCLUDE_STACK => $settings->getClientLoggingIncludeStackTrace(),
            static::INPUT_CLIENT_LOGGING_EXCLUDE_BOTS => $settings->getClientLoggingExcludeBots(),
            static::INPUT_CLIENT_LOGGING_RETENTION_DAYS => $settings->getClientLoggingRetentionDays(),
        ];

        return $helper->generateForm([
            $infoForm,
            $fileLoggingForm,
            $jsLoggingForm,
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
        $retentionDays = (int)$settings->getClientLoggingRetentionDays();
        if (! headers_sent()) {
            header('Content-Type: text/plain');
        }

        $this->getTransformMessage()->synchronize();
        $this->pruneClientJsLogs();
        if ($retentionDays > 0) {
            echo "Old client-side JS errors pruned.\n";
        } else {
            echo "Client-side JS error pruning disabled.\n";
        }

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

    protected function pruneClientJsLogs()
    {
        $days = (int)$this->getSettings()->getClientLoggingRetentionDays();
        if ($days < 1) {
            return;
        }
        Db::getInstance()->delete('collectlogs_js_error', 'last_seen < DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY)');
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
            $settings->setClientLoggingEnabled((bool)Tools::getValue(static::INPUT_CLIENT_LOGGING_ENABLED));
            $settings->setClientLoggingSampleRate((int)Tools::getValue(static::INPUT_CLIENT_LOGGING_SAMPLE_RATE));
            $settings->setClientLoggingMaxEvents((int)Tools::getValue(static::INPUT_CLIENT_LOGGING_MAX_EVENTS));
            $settings->setClientLoggingIncludeQueryString((bool)Tools::getValue(static::INPUT_CLIENT_LOGGING_INCLUDE_QUERY));
            $settings->setClientLoggingIncludeStackTrace((bool)Tools::getValue(static::INPUT_CLIENT_LOGGING_INCLUDE_STACK));
            if ($this->isCrawlerDetectorAvailable()) {
                $settings->setClientLoggingExcludeBots((bool)Tools::getValue(static::INPUT_CLIENT_LOGGING_EXCLUDE_BOTS));
            }
            $settings->setClientLoggingRetentionDays((int)Tools::getValue(static::INPUT_CLIENT_LOGGING_RETENTION_DAYS));
            $this->getTransformMessage()->synchronize(true);
            $controller->confirmations[] = $this->l('Settings saved');
        }
    }

    public function shouldSkipClientLoggingForCurrentRequest()
    {
        return $this->getSettings()->getClientLoggingExcludeBots() && $this->isCurrentRequestCrawler();
    }

    public function isCurrentRequestCrawler()
    {
        $crawlerModule = $this->getCrawlerDetectorModule();
        if (! $crawlerModule) {
            return false;
        }

        try {
            return (bool)$crawlerModule->hookActionDetectBot();
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('collectlogs crawler detection failed: ' . $e->getMessage(), 2);
            return false;
        }
    }

    protected function isCrawlerDetectorAvailable()
    {
        return (bool)$this->getCrawlerDetectorModule();
    }

    protected function getCrawlerDetectorModule()
    {
        static $crawlerModule = null;
        static $resolved = false;

        if ($resolved) {
            return $crawlerModule;
        }
        $resolved = true;

        if (!Module::isInstalled('tbdetectcrawler') || !Module::isEnabled('tbdetectcrawler')) {
            return null;
        }

        $module = Module::getInstanceByName('tbdetectcrawler');
        if (!Validate::isLoadedObject($module) || !method_exists($module, 'hookActionDetectBot')) {
            return null;
        }

        $crawlerModule = $module;
        return $crawlerModule;
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
