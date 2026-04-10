<?php

class CollectLogsJsLogModuleFrontController extends ModuleFrontController
{
    /** @var CollectLogs */
    public $module;

    public $ssl = true;
    protected $visitorId = '';
    protected $debugEnabled = false;
    protected $debugMessages = [];

    public function postProcess()
    {
        header('Content-Type: application/json');

        try {
            $this->debugStep('postProcess start');

            if (!$this->module->getSettings()->getClientLoggingEnabled()) {
                $this->respond(202, ['status' => 'disabled']);
            }

            $method = isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : '';
            $this->debugStep('request method: ' . $method);
            if ($method !== 'POST') {
                $this->respond(405, ['error' => 'method_not_allowed']);
            }

            $raw = Tools::file_get_contents('php://input');
            $this->debugStep('payload size: ' . (is_string($raw) ? strlen($raw) : -1));
            if (!is_string($raw) || strlen($raw) < 2 || strlen($raw) > 32768) {
                $this->respond(413, ['error' => 'invalid_payload_size']);
            }

            $payload = json_decode($raw, true);
            if (!is_array($payload) || empty($payload['token'])) {
                $this->respond(400, ['error' => 'invalid_payload']);
            }
            $this->debugEnabled = $this->isDebugRequested($payload);
            $this->debugStep('debug enabled: ' . ($this->debugEnabled ? 'yes' : 'no'));
            $this->visitorId = isset($payload['visitor_id']) ? (string)$payload['visitor_id'] : '';
            $this->debugStep('visitor id present: ' . ($this->visitorId !== '' ? 'yes' : 'no'));

            if (!$this->module->validateClientLogToken($payload['token'])) {
                $this->debugStep('token validation failed');
                $this->respond(403, ['error' => 'invalid_token']);
            }
            $this->debugStep('token validation ok');

            if ($this->shouldDropBotRequest()) {
                $this->debugStep('request dropped: bot user agent');
                $this->respondNoContent(204);
            }

            if (!$this->allowRequestRate()) {
                $this->debugStep('request rate denied');
                $this->respond(429, ['error' => 'rate_limited']);
            }
            $this->debugStep('request rate ok');

            $events = [];
            if (isset($payload['events']) && is_array($payload['events'])) {
                $events = array_slice($payload['events'], 0, 20);
            } else {
                $events[] = $payload;
            }
            $this->debugStep('event count received: ' . count($events));

            $stored = 0;
            foreach ($events as $index => $event) {
                if (is_array($event) && $this->safeStoreEvent($event, $index)) {
                    $stored++;
                }
            }
            $this->debugStep('stored count: ' . $stored);

            $this->respond(200, ['status' => 'ok', 'stored' => $stored]);
        } catch (Throwable $e) {
            $this->logIntakeFailure($e);
            $this->respond(202, ['status' => 'dropped']);
        }
    }

    protected function allowRequestRate()
    {
        $db = Db::getInstance();
        $ip = (string)Tools::getRemoteAddr();
        $ipHash = pSQL(sha1($ip));
        $visitor = pSQL($this->sanitizeText($this->visitorId, 128));
        $where = "date_add >= DATE_SUB(NOW(), INTERVAL 1 MINUTE) AND ip_hash = '" . $ipHash . "'";
        if ($visitor) {
            $where .= " AND visitor_id = '" . $visitor . "'";
        }
        $count = (int)$db->getValue((new DbQuery())->select('COUNT(1)')->from('collectlogs_js_error')->where($where));
        $this->debugStep('rate window count: ' . $count);
        return $count < 50;
    }

    protected function storeEvent(array $event, $index = null)
    {
        $db = Db::getInstance();
        $event = $this->normalizeEvent($event);
        if (! $event) {
            $this->logMessage('collectlogs jslog skipped empty normalized event');
            $this->debugStep('event ' . (int)$index . ' skipped: normalized empty');
            return false;
        }

        $message = $event['message'];
        $type = $event['type'];
        $severity = $event['severity'];
        $url = $event['url'];
        $referrer = $event['referrer'];
        $ua = $this->sanitizeText($this->getUserAgent(), 1024);
        $stackJson = $this->encodeJson($event['stack_trace'], '[]');
        $extraJson = $this->encodeJson($event['extra'], '{}');
        $scriptUrl = $event['script_url'];
        $line = $event['line'];
        $column = $event['column'];
        $idShop = (int)$this->context->shop->id;
        $idLang = isset($this->context->language->id) ? (int)$this->context->language->id : 0;
        $idCustomer = isset($this->context->customer->id) ? (int)$this->context->customer->id : 0;
        $guestId = isset($this->context->cookie->id_guest) ? (string)$this->context->cookie->id_guest : '';
        $customerHash = $idCustomer || $guestId === '' ? null : sha1($guestId . '|' . _COOKIE_KEY_);
        $visitor = $this->sanitizeText($this->visitorId, 128);
        $ipHash = sha1((string)Tools::getRemoteAddr());
        $fingerprint = sha1(strtolower(trim($type . '|' . $message . '|' . $url . '|' . $scriptUrl . '|' . (int)$line)));
        $this->debugStep('event ' . (int)$index . ' fingerprint: ' . $fingerprint);

        $existing = $db->getRow((new DbQuery())
            ->select('id_collectlogs_js_error')
            ->from('collectlogs_js_error')
            ->where("id_shop = $idShop")
            ->where("fingerprint = '" . pSQL($fingerprint) . "'")
            ->where("last_seen >= DATE_SUB(NOW(), INTERVAL 12 HOUR)")
            ->orderBy('id_collectlogs_js_error DESC'));

        if ($existing) {
            $this->debugStep('event ' . (int)$index . ' matched existing id ' . (int)$existing['id_collectlogs_js_error']);
            return $this->updateExistingEvent(
                $db,
                (int)$existing['id_collectlogs_js_error'],
                [
                    'url' => $url,
                    'referrer' => $referrer ?: null,
                    'user_agent' => $ua,
                    'stack_trace_json' => $stackJson,
                    'extra_json' => $extraJson,
                    'script_url' => $scriptUrl ?: null,
                    'line' => $line,
                    'column' => $column,
                ]
            );
        }

        $now = date('Y-m-d H:i:s');
        $data = [
            'id_shop' => (int)$idShop,
            'id_lang' => $idLang ?: null,
            'id_customer' => $idCustomer ?: null,
            'customer_hash' => $customerHash,
            'visitor_id' => $visitor ?: null,
            'ip_hash' => $ipHash,
            'severity' => $severity,
            'message' => $message,
            'error_type' => $type,
            'url' => $url,
            'referrer' => $referrer ?: null,
            'user_agent' => $ua,
            'dt_iso' => $now,
            'stack_trace_json' => $stackJson,
            'extra_json' => $extraJson,
            'script_url' => $scriptUrl ?: null,
            'line' => $line,
            'column' => $column,
            'fingerprint' => $fingerprint,
            'occurrences' => 1,
            'first_seen' => $now,
            'last_seen' => $now,
            'date_add' => $now,
        ];

        $inserted = $this->insertEventWithSql($db, $data);
        $this->debugStep('event ' . (int)$index . ' insert sql result: ' . ($inserted ? 'ok' : 'fail'));

        if (! $inserted) {
            $this->logMessage('collectlogs jslog insert failed: ' . $this->getDbErrorMessage($db));
            $this->debugStep('event ' . (int)$index . ' db error: ' . $this->getDbErrorMessage($db));
        }

        return (bool)$inserted;
    }

    protected function safeStoreEvent(array $event, $index = null)
    {
        try {
            return $this->storeEvent($event, $index);
        } catch (Throwable $e) {
            $this->logIntakeFailure($e);
            $this->debugStep('event ' . (int)$index . ' exception: ' . $e->getMessage());
            return false;
        }
    }

    protected function normalizeEvent(array $event)
    {
        $message = $this->sanitizeText(isset($event['message']) ? $event['message'] : '');
        if ($message === '') {
            return null;
        }

        $type = $this->sanitizeText(isset($event['type']) ? $event['type'] : 'runtime', 120);
        if ($type === '') {
            $type = 'runtime';
        }

        return [
            'message' => $message,
            'type' => $type,
            'severity' => $this->sanitizeSeverity(isset($event['severity']) ? $event['severity'] : 'error'),
            'url' => $this->sanitizeUrl(isset($event['url']) ? $event['url'] : ''),
            'referrer' => $this->sanitizeUrl(isset($event['referrer']) ? $event['referrer'] : ''),
            'script_url' => $this->sanitizeUrl(isset($event['script_url']) ? $event['script_url'] : ''),
            'line' => $this->sanitizeLineNumber(isset($event['line']) ? $event['line'] : null),
            'column' => $this->sanitizeLineNumber(isset($event['column']) ? $event['column'] : null),
            'stack_trace' => $this->sanitizeStackTrace(isset($event['stack_trace']) && is_array($event['stack_trace']) ? $event['stack_trace'] : []),
            'extra' => [
                'meta' => $this->sanitizeExtraData(isset($event['meta']) ? $event['meta'] : []),
                'tags' => $this->sanitizeExtraData(isset($event['tags']) ? $event['tags'] : []),
                'source_excerpt' => $this->sanitizeSourceExcerpt(isset($event['source_excerpt']) ? $event['source_excerpt'] : []),
            ],
        ];
    }

    protected function sanitizeSeverity($severity)
    {
        $severity = strtolower((string)$severity);
        return in_array($severity, ['info', 'warn', 'error', 'fatal']) ? $severity : 'error';
    }

    protected function sanitizeText($value, $limit = 4000)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[\r\n\t]+/', ' ', $value);
        if (! is_string($value)) {
            return '';
        }

        $value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $value);
        if (! is_string($value)) {
            return '';
        }

        $value = preg_replace_callback('/\+?[0-9][0-9\s\-()]{7,}[0-9]/', function ($matches) {
            return $this->shouldRedactPhoneMatch($matches[0]) ? '[phone]' : $matches[0];
        }, $value);
        if (! is_string($value)) {
            return '';
        }

        return Tools::substr($value, 0, $limit);
    }

    protected function sanitizeUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }

        $url = preg_replace('/[\r\n\t]+/', '', $url);
        if (!is_string($url)) {
            return '';
        }

        $url = Tools::substr($url, 0, 2000);
        $parts = @parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $path = isset($parts['path']) ? $parts['path'] : '';
        return $parts['scheme'] . '://' . $parts['host'] . $path;
    }

    protected function sanitizeLineNumber($value)
    {
        $value = (int)$value;
        return $value > 0 ? $value : null;
    }

    protected function sanitizeStackTrace(array $frames)
    {
        $output = [];
        foreach (array_slice($frames, 0, 20) as $frame) {
            if (! is_array($frame)) {
                continue;
            }
            $output[] = [
                'url' => $this->sanitizeUrl(isset($frame['url']) ? $frame['url'] : ''),
                'func' => $this->sanitizeText(isset($frame['func']) ? $frame['func'] : '?', 255),
                'args' => [],
                'line' => $this->sanitizeLineNumber(isset($frame['line']) ? $frame['line'] : null),
                'column' => $this->sanitizeLineNumber(isset($frame['column']) ? $frame['column'] : null),
            ];
        }
        return $output;
    }

    protected function sanitizeExtraData($value, $depth = 0)
    {
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (is_string($value)) {
            return $this->sanitizeText($value, 500);
        }

        if ($depth >= 3) {
            return '[truncated]';
        }

        if (is_array($value)) {
            $output = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count >= 50) {
                    $output['__truncated__'] = true;
                    break;
                }
                $safeKey = is_int($key) ? $key : $this->sanitizeText($key, 100);
                $output[$safeKey] = $this->sanitizeExtraData($item, $depth + 1);
                $count++;
            }
            return $output;
        }

        return $this->sanitizeText($value, 500);
    }

    protected function sanitizeSourceExcerpt($value)
    {
        if (!is_array($value)) {
            return [];
        }

        $focus = $this->sanitizeCodeText(isset($value['focus']) ? $value['focus'] : '', 120);
        if ($focus === '') {
            return [];
        }

        return [
            'source_url' => $this->sanitizeUrl(isset($value['source_url']) ? $value['source_url'] : ''),
            'line' => $this->sanitizeLineNumber(isset($value['line']) ? $value['line'] : null),
            'column' => $this->sanitizeLineNumber(isset($value['column']) ? $value['column'] : null),
            'has_prefix' => !empty($value['has_prefix']),
            'has_suffix' => !empty($value['has_suffix']),
            'before' => $this->sanitizeCodeText(isset($value['before']) ? $value['before'] : '', 140),
            'focus' => $focus,
            'after' => $this->sanitizeCodeText(isset($value['after']) ? $value['after'] : '', 140),
        ];
    }

    protected function sanitizeCodeText($value, $limit = 200)
    {
        $value = (string)$value;
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[\r\n\t]+/', ' ', $value);
        if (!is_string($value)) {
            return '';
        }

        $value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $value);
        if (!is_string($value)) {
            return '';
        }

        $value = preg_replace_callback('/\+?[0-9][0-9\s\-()]{7,}[0-9]/', function ($matches) {
            return $this->shouldRedactPhoneMatch($matches[0]) ? '[phone]' : $matches[0];
        }, $value);
        if (!is_string($value)) {
            return '';
        }

        $value = preg_replace('/([?&])(token|secure_key|password|passwd|pwd|email)=([^&\s]+)/i', '$1$2=[removed]', $value);
        if (!is_string($value)) {
            return '';
        }

        return Tools::substr($value, 0, (int)$limit);
    }

    protected function shouldRedactPhoneMatch($value)
    {
        return !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value);
    }

    protected function encodeJson($value, $fallback)
    {
        $options = defined('JSON_PARTIAL_OUTPUT_ON_ERROR') ? JSON_PARTIAL_OUTPUT_ON_ERROR : 0;
        $json = json_encode($value, $options);
        return is_string($json) ? $json : $fallback;
    }

    protected function logIntakeFailure(Throwable $e)
    {
        PrestaShopLogger::addLog('collectlogs jslog intake failed: ' . $e->getMessage(), 3);
    }

    protected function logMessage($message)
    {
        PrestaShopLogger::addLog((string)$message, 2);
    }

    protected function getUserAgent()
    {
        if (method_exists('Tools', 'getUserAgent')) {
            return (string)Tools::getUserAgent();
        }

        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            return (string)$_SERVER['HTTP_USER_AGENT'];
        }

        return '';
    }

    protected function shouldDropBotRequest()
    {
        return $this->module && method_exists($this->module, 'shouldSkipClientLoggingForCurrentRequest')
            ? (bool)$this->module->shouldSkipClientLoggingForCurrentRequest()
            : false;
    }

    protected function getDbErrorMessage(Db $db)
    {
        if (method_exists($db, 'getMsgError')) {
            return (string)$db->getMsgError();
        }
        return 'unknown database error';
    }

    protected function insertEventWithSql(Db $db, array $data)
    {
        $columns = [];
        $values = [];
        foreach ($data as $column => $value) {
            $columns[] = '`' . bqSQL($column) . '`';
            $values[] = $this->toSqlValue($value);
        }

        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'collectlogs_js_error` (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')';
        try {
            return (bool)$db->execute($sql);
        } catch (Throwable $e) {
            $this->debugStep('insert sql exception: ' . $e->getMessage());
            return false;
        }
    }

    protected function updateExistingEvent(Db $db, $id, array $data)
    {
        $sets = [
            '`occurrences` = `occurrences` + 1',
            '`last_seen` = NOW()',
            '`dt_iso` = NOW()',
        ];

        foreach ($data as $column => $value) {
            $sets[] = '`' . bqSQL($column) . '` = ' . $this->toSqlValue($value);
        }

        $sql = 'UPDATE `' . _DB_PREFIX_ . 'collectlogs_js_error` SET ' . implode(', ', $sets) . ' WHERE `id_collectlogs_js_error` = ' . (int)$id;
        try {
            return (bool)$db->execute($sql);
        } catch (Throwable $e) {
            $this->debugStep('update sql exception: ' . $e->getMessage());
            return false;
        }
    }

    protected function toSqlValue($value)
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        $value = (string)$value;
        if ($value === '') {
            return '\'\'';
        }

        // Hex literals avoid SQL quoting issues with captured JS selectors/snippets.
        return '0x' . bin2hex($value);
    }

    protected function isDebugRequested(array $payload)
    {
        return ! empty($payload['debug']) || ! empty($_GET['collectlogs_debug']) || (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_);
    }

    protected function debugStep($message)
    {
        $this->debugMessages[] = (string)$message;
    }

    protected function respond($status, array $body)
    {
        if ($this->shouldExposeDebug($body)) {
            $body['debug'] = $this->debugMessages;
        }
        http_response_code((int)$status);
        die(json_encode($body));
    }

    protected function respondNoContent($status)
    {
        http_response_code((int)$status);
        die();
    }

    protected function shouldExposeDebug(array $body)
    {
        if ($this->debugEnabled) {
            return true;
        }

        if (isset($body['stored']) && (int)$body['stored'] === 0) {
            return true;
        }

        if (isset($body['status']) && (string)$body['status'] !== 'ok') {
            return true;
        }

        return false;
    }
}
