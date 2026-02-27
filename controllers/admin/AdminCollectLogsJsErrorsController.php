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

/**
 * Back-office list/detail view for client-side JS errors collected by the
 * collectlogs module.
 */
class AdminCollectLogsJsErrorsController extends ModuleAdminController
{
    /** @var CollectLogs */
    public $module;

    /**
     * @throws PrestaShopException
     */
    public function __construct()
    {
        $this->table      = 'collectlogs_js_error';
        $this->className  = 'ObjectModel'; // We use raw DB — no ObjectModel needed
        $this->identifier = 'id_collectlogs_js_error';
        $this->bootstrap  = true;
        $this->lang       = false;

        parent::__construct();

        $this->_select  = 'a.severity, a.message, a.error_type, a.url, a.occurrences, a.first_seen, a.last_seen, a.id_shop, a.fingerprint';
        $this->_orderBy = 'last_seen';
        $this->_orderWay = 'DESC';

        $this->actions     = ['view', 'delete'];
        $this->bulk_actions = [
            'delete' => [
                'text'    => $this->l('Delete selected'),
                'confirm' => $this->l('Delete selected items?'),
                'icon'    => 'icon-trash',
            ],
        ];

        $this->fields_list = [
            'severity' => [
                'title'           => $this->l('Severity'),
                'type'            => 'text',
                'class'           => 'fixed-width-sm',
                'callback_object' => $this,
                'callback'        => 'displaySeverity',
                'filter_key'      => 'a!severity',
                'order_key'       => 'a!severity',
            ],
            'error_type' => [
                'title'      => $this->l('Type'),
                'type'       => 'text',
                'class'      => 'fixed-width-sm',
                'filter_key' => 'a!error_type',
            ],
            'message' => [
                'title'      => $this->l('Message'),
                'type'       => 'text',
                'filter_key' => 'a!message',
            ],
            'url' => [
                'title'      => $this->l('URL'),
                'type'       => 'text',
                'filter_key' => 'a!url',
                'callback_object' => $this,
                'callback'        => 'displayUrl',
            ],
            'occurrences' => [
                'title' => $this->l('Hits'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ],
            'first_seen' => [
                'title' => $this->l('First seen'),
                'type'  => 'datetime',
                'align' => 'right',
            ],
            'last_seen' => [
                'title' => $this->l('Last seen'),
                'type'  => 'datetime',
                'align' => 'right',
            ],
        ];

        // Filters: date range, shop, url, message, severity, user_agent
        $this->fields_options = [];
    }

    /**
     * @return void
     * @throws PrestaShopException
     */
    public function initToolbar()
    {
        parent::initToolbar();

        $this->page_header_toolbar_btn['settings'] = [
            'icon' => 'process-icon-cogs',
            'href' => $this->context->link->getAdminLink('AdminModules', true, [
                'configure'   => $this->module->name,
                'module_name' => $this->module->name,
            ]),
            'desc' => $this->l('Settings'),
        ];

        // Add a "Prune old records" button
        $this->page_header_toolbar_btn['prune'] = [
            'icon' => 'process-icon-delete',
            'href' => $this->context->link->getAdminLink(
                'AdminCollectLogsJsErrors',
                true,
                ['action' => 'prune']
            ),
            'desc' => $this->l('Prune old records'),
        ];
    }

    /**
     * @return void
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function postProcess()
    {
        parent::postProcess();

        if (Tools::getValue('action') === 'prune') {
            $this->processPrune();
        }
    }

    /**
     * Delete JS error rows older than the configured retention period.
     *
     * @return void
     * @throws PrestaShopException
     */
    protected function processPrune()
    {
        $days = (int)$this->module->getSettings()->getJsRetentionDays();
        if ($days < 1) {
            $this->confirmations[] = $this->l('Auto-prune is disabled (retention = 0 days).');
            return;
        }
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $count  = (int)Db::getInstance()->getValue(
            (new DbQuery())->select('COUNT(1)')->from('collectlogs_js_error')->where("last_seen < '$cutoff'")
        );
        Db::getInstance()->delete('collectlogs_js_error', "last_seen < '" . pSQL($cutoff) . "'");
        $this->confirmations[] = sprintf($this->l('%d old JS error record(s) deleted.'), $count);
    }

    /**
     * Detail view – shows raw payload + formatted stack frames.
     *
     * @return string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function renderView()
    {
        $id = (int)Tools::getValue($this->identifier);
        if (!$id) {
            $this->errors[] = $this->l('Missing record ID');
            return '';
        }

        $row = Db::getInstance()->getRow(
            (new DbQuery())->select('*')->from('collectlogs_js_error')->where('id_collectlogs_js_error = ' . $id)
        );
        if (!$row) {
            $this->errors[] = $this->l('Record not found');
            return '';
        }

        $stackFrames = null;
        if ($row['stack_trace_json']) {
            $stackFrames = json_decode($row['stack_trace_json'], true);
        }
        $tags = null;
        if ($row['extra_json']) {
            $decoded = json_decode($row['extra_json'], true);
            $tags = is_array($decoded) ? ($decoded['tags'] ?? null) : null;
        }

        $template = $this->createTemplate('js-error-detail.tpl');
        $template->assign([
            'row'         => $row,
            'stackFrames' => $stackFrames,
            'tags'        => $tags,
        ]);
        return $template->fetch();
    }

    /**
     * Process deletion of a single record.
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function processDelete()
    {
        $id = (int)Tools::getValue($this->identifier);
        $ok = Db::getInstance()->delete('collectlogs_js_error', 'id_collectlogs_js_error = ' . $id);
        if ($ok) {
            $this->redirect_after = static::$currentIndex . '&conf=1&token=' . $this->token;
        } else {
            $this->errors[] = Tools::displayError('Failed to delete record');
        }
        return $ok;
    }

    /**
     * Process bulk deletion.
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function processBulkDelete()
    {
        $result = true;
        if (is_array($this->boxes) && !empty($this->boxes)) {
            $ids = implode(',', array_map('intval', $this->boxes));
            $result = Db::getInstance()->delete('collectlogs_js_error', 'id_collectlogs_js_error IN (' . $ids . ')');
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Display callbacks
    // -------------------------------------------------------------------------

    /**
     * @param string $value
     * @return string
     */
    public function displaySeverity($value)
    {
        $map = [
            'fatal' => 'badge-critical',
            'error' => 'badge-danger',
            'warn'  => 'badge-warning',
            'info'  => 'badge-info',
        ];
        $class = $map[$value] ?? 'badge-default';
        return '<span class="badge ' . $class . '">' . htmlspecialchars($value) . '</span>';
    }

    /**
     * @param string $value
     * @return string
     */
    public function displayUrl($value)
    {
        $short = mb_strlen($value) > 80 ? mb_substr($value, 0, 77) . '...' : $value;
        return '<span title="' . htmlspecialchars($value) . '">' . htmlspecialchars($short) . '</span>';
    }
}
