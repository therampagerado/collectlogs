<?php

class AdminCollectLogsJsBackendController extends ModuleAdminController
{
    public $module;

    public function __construct()
    {
        $this->table = 'collectlogs_js_error';
        $this->identifier = 'id_collectlogs_js_error';
        $this->bootstrap = true;

        parent::__construct();

        $this->_orderBy = 'last_seen';
        $this->_orderWay = 'DESC';
        $this->actions = ['view', 'delete'];

        $this->fields_list = [
            'id_collectlogs_js_error' => ['title' => $this->l('ID'), 'class' => 'fixed-width-xs'],
            'severity' => ['title' => $this->l('Severity')],
            'error_type' => ['title' => $this->l('Type')],
            'message' => ['title' => $this->l('Message')],
            'url' => ['title' => $this->l('URL')],
            'user_agent' => ['title' => $this->l('User agent')],
            'occurrences' => ['title' => $this->l('Occurrences'), 'class' => 'fixed-width-xs'],
            'first_seen' => ['title' => $this->l('First seen'), 'type' => 'datetime'],
            'last_seen' => ['title' => $this->l('Last seen'), 'type' => 'datetime'],
        ];
    }

    public function renderView()
    {
        $id = (int)Tools::getValue($this->identifier);
        $log = Db::getInstance()->getRow((new DbQuery())
            ->select('*')
            ->from('collectlogs_js_error')
            ->where('id_collectlogs_js_error = ' . $id));
        if (!$log) {
            $this->errors[] = $this->l('Object not found');
            return '';
        }

        $this->context->smarty->assign([
            'log' => $log,
            'stackFrames' => json_decode($log['stack_trace_json'], true),
            'extra' => json_decode($log['extra_json'], true),
        ]);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'collectlogs/views/templates/admin/collect_logs_backend/js-log-view.tpl');
    }
}
