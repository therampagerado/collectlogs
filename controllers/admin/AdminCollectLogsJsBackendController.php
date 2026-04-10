<?php

use CollectLogsModule\GithubIssueFormatter;

require_once _PS_MODULE_DIR_ . 'collectlogs/classes/GithubIssueFormatter.php';
require_once _PS_MODULE_DIR_ . 'collectlogs/classes/TransformMessage.php';

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
        $this->bulk_actions = [
            'delete' => [
                'text' => $this->l('Delete selected'),
                'confirm' => $this->l('Delete selected items?'),
                'icon' => 'icon-trash',
            ],
        ];

        $this->fields_list = [
            'id_collectlogs_js_error' => ['title' => $this->l('ID'), 'class' => 'fixed-width-xs'],
            'severity' => ['title' => $this->l('Severity')],
            'error_type' => ['title' => $this->l('Type')],
            'message' => ['title' => $this->l('Message')],
            'url' => ['title' => $this->l('URL'), 'callback' => 'renderDecodedUrlColumn'],
            'occurrences' => ['title' => $this->l('Occurrences'), 'class' => 'fixed-width-xs'],
            'first_seen' => ['title' => $this->l('First seen'), 'type' => 'datetime'],
            'last_seen' => ['title' => $this->l('Last seen'), 'type' => 'datetime'],
        ];
    }

    /**
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function processDelete()
    {
        $id = (int)Tools::getValue($this->identifier);
        $result = $this->deleteLog($id);
        if ($result) {
            $this->redirect_after = static::$currentIndex . '&conf=1&token=' . $this->token;
        } else {
            $this->errors[] = Tools::displayError('Failed to delete log');
        }
        return $result;
    }

    /**
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function processBulkDelete()
    {
        $result = true;
        if (is_array($this->boxes) && !empty($this->boxes)) {
            foreach ($this->boxes as $id) {
                $result = $this->deleteLog($id) && $result;
            }
        }
        return $result;
    }

    /**
     * @return void
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function initToolbar()
    {
        $this->page_header_toolbar_btn['settings'] = [
            'icon' => 'process-icon-cogs',
            'href' => $this->context->link->getAdminLink('AdminModules', true, [
                'configure' => $this->module->name,
                'module_name' => $this->module->name,
            ]),
            'desc' => $this->l('Settings'),
        ];

        if (Tools::isSubmit('view' . $this->table)) {
            $href = $this->context->link->getAdminLink(
                'AdminCollectLogsJsBackend',
                true,
                [
                    $this->identifier => (int) Tools::getValue($this->identifier),
                    'create_github_issue' => 1,
                ]
            );

            $this->page_header_toolbar_btn['copy_markdown'] = [
                'icon' => 'process-icon-new',
                'href' => '#',
                'desc' => $this->l('Copy as markdown'),
                'js' => 'if (window.collectlogsCopyMarkdown) { return window.collectlogsCopyMarkdown(\'collectlogs-markdown-copy-source\', \''
                    . addslashes($this->l('Error markdown copied to clipboard.'))
                    . '\', \''
                    . addslashes($this->l('Failed to copy markdown. Please copy it manually from the hidden field.'))
                    . '\'); } return false;',
            ];

            $this->page_header_toolbar_btn['github_issue'] = [
                'icon' => 'process-icon-new',
                'href' => $href,
                'desc' => $this->l('Create GitHub issue'),
                'target' => '_blank',
            ];
        }
    }

    /**
     * @return void
     *
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function postProcess()
    {
        parent::postProcess();

        if (Tools::isSubmit('create_github_issue')) {
            $this->processCreateGithubIssue();
        }
    }

    public function renderView()
    {
        $id = (int)Tools::getValue($this->identifier);
        $log = $this->getLog($id);
        if (!$log) {
            $this->errors[] = $this->l('Object not found');
            return '';
        }

        $extra = $this->decodeJsonField($log['extra_json'], []);
        $meta = isset($extra['meta']) && is_array($extra['meta']) ? $extra['meta'] : [];
        $tags = isset($extra['tags']) && is_array($extra['tags']) ? $extra['tags'] : [];
        $sourceExcerpt = isset($extra['source_excerpt']) && is_array($extra['source_excerpt']) ? $extra['source_excerpt'] : [];
        $runtimeState = isset($meta['runtime_state']) && is_array($meta['runtime_state']) ? $meta['runtime_state'] : [];
        $stackFrames = $this->decodeJsonField($log['stack_trace_json'], []);

        $displayLog = $log;
        $displayLog['url_display'] = $this->decodeUrlForDisplay(isset($log['url']) ? $log['url'] : '');
        $displayLog['referrer_display'] = $this->decodeUrlForDisplay(isset($log['referrer']) ? $log['referrer'] : '');
        $displayLog['script_url_display'] = $this->decodeUrlForDisplay(isset($log['script_url']) ? $log['script_url'] : '');

        if ($sourceExcerpt) {
            $sourceExcerpt['source_url_display'] = $this->decodeUrlForDisplay(
                isset($sourceExcerpt['source_url']) ? $sourceExcerpt['source_url'] : ''
            );
        }

        if ($stackFrames) {
            foreach ($stackFrames as $index => $frame) {
                if (!is_array($frame)) {
                    continue;
                }
                $stackFrames[$index]['url_display'] = $this->decodeUrlForDisplay(
                    isset($frame['url']) ? $frame['url'] : ''
                );
            }
        }

        $template = $this->context->smarty->createTemplate(
            _PS_MODULE_DIR_ . 'collectlogs/views/templates/admin/collect_logs_backend/js-log-view.tpl'
        );
        $markdownBody = $this->buildMarkdownBody($log, $stackFrames, $extra);
        $template->assign([
            'log' => $displayLog,
            'stackFrames' => $stackFrames,
            'extra' => $extra,
            'meta' => $meta,
            'tags' => $tags,
            'sourceExcerpt' => $sourceExcerpt,
            'runtimeState' => $runtimeState,
            'markdownBody' => $markdownBody,
        ]);

        return $template->fetch();
    }

    /**
     * @param int $id
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function deleteLog($id)
    {
        return Db::getInstance()->delete($this->table, $this->identifier . ' = ' . (int)$id);
    }

    /**
     * @param int $id
     * @return array|false|null
     * @throws PrestaShopDatabaseException
     */
    protected function getLog($id)
    {
        return Db::getInstance()->getRow((new DbQuery())
            ->select('*')
            ->from('collectlogs_js_error')
            ->where('id_collectlogs_js_error = ' . (int)$id));
    }

    protected function decodeJsonField($value, array $fallback)
    {
        if (! is_string($value) || $value === '') {
            return $fallback;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $fallback;
    }

    protected function decodeUrlForDisplay($url)
    {
        $url = (string)$url;
        if ($url === '') {
            return '';
        }

        $decoded = rawurldecode($url);
        return is_string($decoded) ? $decoded : $url;
    }

    public function renderDecodedUrlColumn($value, $row)
    {
        $url = isset($row['url']) ? (string)$row['url'] : (string)$value;
        if ($url === '') {
            return '';
        }

        $display = $this->decodeUrlForDisplay($url);

        return sprintf(
            '<span title="%s" style="word-break: break-all;">%s</span>',
            htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($display, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * @return void
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function processCreateGithubIssue()
    {
        $id = (int) Tools::getValue($this->identifier);
        if (!$id) {
            $this->errors[] = $this->l('Missing log ID');
            return;
        }

        $log = $this->getLog($id);
        if (!$log) {
            $this->errors[] = $this->l('Object not found');
            return;
        }

        $stackFrames = $this->decodeJsonField($log['stack_trace_json'], []);
        $extra = $this->decodeJsonField($log['extra_json'], []);
        $body = $this->buildMarkdownBody($log, $stackFrames, $extra);

        $max = 7000;
        if (strlen($body) > $max) {
            $body = substr($body, 0, $max) . "\n\n---\n" .
                '_[Truncated. See the JS error log screen for full details.]_';
        }

        $titleParts = [
            $log['error_type'] ?: 'JS Error',
            $log['message'] ?: '',
        ];
        $title = 'JS Error: ' . trim(implode(' - ', array_filter($titleParts)));
        if (strlen($title) > 120) {
            $title = substr($title, 0, 117) . '...';
        }

        $url = 'https://github.com/thirtybees/thirtybees/issues/new'
            . '?title=' . rawurlencode($title)
            . '&body=' . rawurlencode($body);

        Tools::redirectAdmin($url);
    }

    /**
     * @return array
     */
    private function detectAdminFolder()
    {
        $fsPath = PS_ADMIN_DIR;
        $seg = basename($fsPath);

        return [$seg, $fsPath];
    }

    protected function buildMarkdownBody(array $log, array $stackFrames, array $extra)
    {
        list($adminSeg, $adminFsPath) = $this->detectAdminFolder();

        $formatter = new GithubIssueFormatter(
            $this->module->getTransformMessage(),
            $adminSeg,
            $adminFsPath
        );

        return $formatter->formatJsError($log, $stackFrames, $extra);
    }
}
