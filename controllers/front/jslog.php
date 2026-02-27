<?php

class CollectLogsJsLogModuleFrontController extends ModuleFrontController
{
    /** @var CollectLogs */
    public $module;

    public $ssl = true;
    protected $visitorId = '';

    public function postProcess()
    {
        header('Content-Type: application/json');

        if (!$this->module->getSettings()->getClientLoggingEnabled()) {
            $this->respond(202, ['status' => 'disabled']);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(405, ['error' => 'method_not_allowed']);
        }

        $raw = Tools::file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw) < 2 || strlen($raw) > 32768) {
            $this->respond(413, ['error' => 'invalid_payload_size']);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['token'])) {
            $this->respond(400, ['error' => 'invalid_payload']);
        }
        $this->visitorId = isset($payload['visitor_id']) ? (string)$payload['visitor_id'] : '';

        if (!$this->module->validateClientLogToken($payload['token'])) {
            $this->respond(403, ['error' => 'invalid_token']);
        }

        if (!$this->allowRequestRate()) {
            $this->respond(429, ['error' => 'rate_limited']);
        }

        $events = [];
        if (isset($payload['events']) && is_array($payload['events'])) {
            $events = array_slice($payload['events'], 0, 20);
        } else {
            $events[] = $payload;
        }

        $stored = 0;
        foreach ($events as $event) {
            if (is_array($event) && $this->storeEvent($event)) {
                $stored++;
            }
        }

        $this->respond(200, ['status' => 'ok', 'stored' => $stored]);
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
        return $count < 50;
    }

    protected function storeEvent(array $event)
    {
        $db = Db::getInstance();
        $message = $this->sanitizeText(isset($event['message']) ? $event['message'] : '');
        $type = $this->sanitizeText(isset($event['type']) ? $event['type'] : 'runtime', 120);
        $severity = $this->sanitizeSeverity(isset($event['severity']) ? $event['severity'] : 'error');
        $url = $this->sanitizeUrl(isset($event['url']) ? $event['url'] : '');
        $referrer = $this->sanitizeUrl(isset($event['referrer']) ? $event['referrer'] : '');
        $ua = $this->sanitizeText((string)Tools::getUserAgent(), 1024);
        $stack = isset($event['stack_trace']) && is_array($event['stack_trace']) ? $event['stack_trace'] : [];
        $stackJson = pSQL(json_encode($stack));
        $extra = [
            'meta' => isset($event['meta']) ? $event['meta'] : [],
            'tags' => isset($event['tags']) ? $event['tags'] : [],
        ];
        $extraJson = pSQL(json_encode($extra));
        $scriptUrl = $this->sanitizeUrl(isset($event['script_url']) ? $event['script_url'] : '');
        $line = isset($event['line']) ? (int)$event['line'] : null;
        $column = isset($event['column']) ? (int)$event['column'] : null;
        $idShop = (int)$this->context->shop->id;
        $idLang = isset($this->context->language->id) ? (int)$this->context->language->id : 0;
        $idCustomer = isset($this->context->customer->id) ? (int)$this->context->customer->id : 0;
        $customerHash = $idCustomer ? null : sha1((string)$this->context->cookie->id_guest . '|' . _COOKIE_KEY_);
        $visitor = $this->sanitizeText($this->visitorId, 128);
        $ipHash = sha1((string)Tools::getRemoteAddr());
        $fingerprint = sha1(strtolower(trim($type . '|' . $message . '|' . $url . '|' . $scriptUrl . '|' . (int)$line)));

        $existing = $db->getRow((new DbQuery())
            ->select('id_collectlogs_js_error')
            ->from('collectlogs_js_error')
            ->where("id_shop = $idShop")
            ->where("fingerprint = '" . pSQL($fingerprint) . "'")
            ->where("last_seen >= DATE_SUB(NOW(), INTERVAL 12 HOUR)")
            ->orderBy('id_collectlogs_js_error DESC'));

        if ($existing) {
            return $db->execute('UPDATE `' . _DB_PREFIX_ . "collectlogs_js_error` SET occurrences = occurrences + 1, last_seen = NOW(), dt_iso = NOW() WHERE id_collectlogs_js_error = " . (int)$existing['id_collectlogs_js_error']);
        }

        $sql = 'INSERT INTO `' . _DB_PREFIX_ . "collectlogs_js_error`
            (`id_shop`,`id_lang`,`id_customer`,`customer_hash`,`visitor_id`,`ip_hash`,`severity`,`message`,`error_type`,`url`,`referrer`,`user_agent`,`dt_iso`,`stack_trace_json`,`extra_json`,`script_url`,`line`,`column`,`fingerprint`,`occurrences`,`first_seen`,`last_seen`,`date_add`)
            VALUES (
            " . (int)$idShop . ",
            " . ($idLang ?: 'NULL') . ",
            " . ($idCustomer ?: 'NULL') . ",
            " . ($customerHash ? "'" . pSQL($customerHash) . "'" : 'NULL') . ",
            " . ($visitor ? "'" . pSQL($visitor) . "'" : 'NULL') . ",
            '" . pSQL($ipHash) . "',
            '" . pSQL($severity) . "',
            '" . pSQL($message, true) . "',
            '" . pSQL($type) . "',
            '" . pSQL($url, true) . "',
            " . ($referrer ? "'" . pSQL($referrer, true) . "'" : 'NULL') . ",
            '" . pSQL($ua, true) . "',
            NOW(),
            '" . pSQL($stackJson, true) . "',
            '" . pSQL($extraJson, true) . "',
            " . ($scriptUrl ? "'" . pSQL($scriptUrl, true) . "'" : 'NULL') . ",
            " . ($line ?: 'NULL') . ",
            " . ($column ?: 'NULL') . ",
            '" . pSQL($fingerprint) . "',
            1,
            NOW(), NOW(), NOW())";
        return $db->execute($sql);
    }

    protected function sanitizeSeverity($severity)
    {
        $severity = strtolower((string)$severity);
        return in_array($severity, ['info', 'warn', 'error', 'fatal']) ? $severity : 'error';
    }

    protected function sanitizeText($value, $limit = 4000)
    {
        $value = trim((string)$value);
        $value = preg_replace('/[\r\n\t]+/', ' ', $value);
        $value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $value);
        $value = preg_replace('/\+?[0-9][0-9\s\-()]{7,}[0-9]/', '[phone]', $value);
        return Tools::substr($value, 0, $limit);
    }

    protected function sanitizeUrl($url)
    {
        $url = $this->sanitizeText($url, 2000);
        $parts = @parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $path = isset($parts['path']) ? $parts['path'] : '';
        return $parts['scheme'] . '://' . $parts['host'] . $path;
    }

    protected function respond($status, array $body)
    {
        http_response_code((int)$status);
        die(json_encode($body));
    }
}
