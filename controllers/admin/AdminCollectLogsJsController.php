<?php

class AdminCollectLogsJsController extends ModuleAdminController
{
    /** @var CollectLogs */
    public $module;

    public function __construct()
    {
        $this->table = 'collectlogs_js_error';
        $this->identifier = 'id_collectlogs_js_error';
        $this->bootstrap = true;

        parent::__construct();

        $this->actions = ['view', 'delete'];
        $this->bulk_actions = [
            'delete' => [
                'text' => $this->l('Delete selected'),
                'confirm' => $this->l('Delete selected items?'),
                'icon' => 'icon-trash',
            ],
        ];

        $this->fields_list = [
            'id_collectlogs_js_error' => ['title' => $this->l('ID'), 'class' => 'fixed-width-xs'],
            'severity' => ['title' => $this->l('Severity'), 'type' => 'text'],
            'status' => ['title' => $this->l('Status'), 'type' => 'text'],
            'message' => ['title' => $this->l('Message'), 'type' => 'text'],
            'url' => ['title' => $this->l('URL'), 'type' => 'text'],
            'user_agent' => ['title' => $this->l('User agent'), 'type' => 'text'],
            'occurrences' => ['title' => $this->l('Occurrences'), 'class' => 'fixed-width-xs', 'align' => 'center'],
            'last_seen' => ['title' => $this->l('Last seen'), 'type' => 'datetime'],
        ];

        $this->_orderBy = 'last_seen';
        $this->_orderWay = 'DESC';

        if ($severity = Tools::getValue('severity')) {
            $this->_where .= " AND a.severity = '".pSQL($severity)."'";
        }
        if ($status = Tools::getValue('status')) {
            $this->_where .= " AND a.status = '".pSQL($status)."'";
        }
        if ($shop = (int)Tools::getValue('id_shop')) {
            $this->_where .= ' AND a.id_shop = '.(int)$shop;
        }

        $dateFrom = Tools::getValue('date_from');
        if ($dateFrom && Validate::isDate($dateFrom.' 00:00:00')) {
            $this->_where .= " AND a.last_seen >= '".pSQL($dateFrom." 00:00:00")."'";
        }
        $dateTo = Tools::getValue('date_to');
        if ($dateTo && Validate::isDate($dateTo.' 23:59:59')) {
            $this->_where .= " AND a.last_seen <= '".pSQL($dateTo." 23:59:59")."'";
        }
    }

    public function postProcess()
    {
        if (Tools::isSubmit('markIgnored')) {
            $this->updateStatus('ignored');
        }
        if (Tools::isSubmit('markResolved')) {
            $this->updateStatus('resolved');
        }
        parent::postProcess();
    }

    protected function updateStatus($status)
    {
        $id = (int)Tools::getValue($this->identifier);
        if ($id) {
            Db::getInstance()->update('collectlogs_js_error', ['status' => pSQL($status)], $this->identifier.' = '.(int)$id);
        }
    }

    public function renderView()
    {
        $id = (int)Tools::getValue($this->identifier);
        $row = Db::getInstance()->getRow((new DbQuery())
            ->select('*')
            ->from('collectlogs_js_error')
            ->where($this->identifier.' = '.$id)
        );
        if (! $row) {
            $this->errors[] = $this->l('Item not found');
            return '';
        }

        $stack = json_decode($row['stack_trace_json'], true);
        if (! is_array($stack)) {
            $stack = [];
        }

        $this->context->smarty->assign([
            'row' => $row,
            'stack' => $stack,
            'markIgnoredUrl' => self::$currentIndex.'&'.$this->identifier.'='.$id.'&view'.$this->table.'&markIgnored=1&token='.$this->token,
            'markResolvedUrl' => self::$currentIndex.'&'.$this->identifier.'='.$id.'&view'.$this->table.'&markResolved=1&token='.$this->token,
        ]);

        return $this->createTemplate('js-log-view.tpl')->fetch();
    }
}
