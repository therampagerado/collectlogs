<?php

class CollectLogsJsLogModuleFrontController extends ModuleFrontController
{
    /** @var CollectLogs */
    public $module;

    public $ssl = true;

    public function initContent()
    {
        parent::initContent();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            die(json_encode(['ok' => false, 'error' => 'method_not_allowed']));
        }

        $settings = $this->module->getSettings();
        if (! $settings->getClientLoggingEnabled()) {
            http_response_code(204);
            die(json_encode(['ok' => true]));
        }

        $raw = file_get_contents('php://input');
        if (! is_string($raw) || strlen($raw) < 2 || strlen($raw) > 50000) {
            http_response_code(400);
            die(json_encode(['ok' => false, 'error' => 'invalid_payload_size']));
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            http_response_code(400);
            die(json_encode(['ok' => false, 'error' => 'invalid_json']));
        }

        $token = isset($data['token']) ? (string)$data['token'] : '';
        if (! $this->validateToken($token)) {
            http_response_code(403);
            die(json_encode(['ok' => false, 'error' => 'invalid_token']));
        }

        $visitorId = isset($data['visitor_id']) ? substr(preg_replace('/[^a-zA-Z0-9\-_.]/', '', (string)$data['visitor_id']), 0, 64) : '';
        if (! $this->checkRateLimit($visitorId)) {
            http_response_code(429);
            die(json_encode(['ok' => false, 'error' => 'rate_limited']));
        }

        $events = isset($data['events']) && is_array($data['events']) ? $data['events'] : [];
        if (! $events) {
            http_response_code(400);
            die(json_encode(['ok' => false, 'error' => 'missing_events']));
        }

        $now = date('Y-m-d H:i:s');
        $saved = 0;
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            if ($this->saveEvent($event, $visitorId, $now)) {
                $saved++;
            }
        }

        die(json_encode(['ok' => true, 'saved' => $saved]));
    }

    protected function validateToken($token)
    {
        if (! $token || strpos($token, '.') === false) {
            return false;
        }
        list($encoded, $sig) = explode('.', $token, 2);
        $expected = hash_hmac('sha256', $encoded, _COOKIE_KEY_);
        if (! hash_equals($expected, $sig)) {
            return false;
        }
        $payload = json_decode(base64_decode($encoded), true);
        if (! is_array($payload)) {
            return false;
        }
        if ((int)($payload['id_shop'] ?? 0) !== (int)$this->context->shop->id) {
            return false;
        }
        $ts = (int)($payload['ts'] ?? 0);
        return $ts > (time() - 7200) && $ts < (time() + 300);
    }

    protected function checkRateLimit($visitorId)
    {
        $ip = Tools::getRemoteAddr();
        $bucket = (int)floor(time() / 60);
        $db = Db::getInstance();
        foreach ([$ip, $visitorId] as $keyPart) {
            if (! $keyPart) {
                continue;
            }
            $rateKey = hash('sha256', $keyPart);
            $row = $db->getRow((new DbQuery())
                ->select('*')
                ->from('collectlogs_js_rate_limit')
                ->where("rate_key = '".pSQL($rateKey)."'")
                ->where('bucket = '.(int)$bucket)
            );
            if ($row) {
                $hits = (int)$row['hits'] + 1;
                $db->update('collectlogs_js_rate_limit', ['hits' => $hits, 'date_upd' => date('Y-m-d H:i:s')], "rate_key = '".pSQL($rateKey)."' AND bucket = ".(int)$bucket);
                if ($hits > 120) {
                    return false;
                }
            } else {
                $db->insert('collectlogs_js_rate_limit', [
                    'rate_key' => pSQL($rateKey),
                    'bucket' => (int)$bucket,
                    'hits' => 1,
                    'date_upd' => date('Y-m-d H:i:s'),
                ]);
            }
        }
        $db->delete('collectlogs_js_rate_limit', 'date_upd < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        return true;
    }

    protected function saveEvent(array $event, $visitorId, $now)
    {
        $severity = isset($event['severity']) ? (string)$event['severity'] : 'error';
        if (! in_array($severity, ['info', 'warn', 'error', 'fatal'], true)) {
            $severity = 'error';
        }

        $message = $this->sanitizeText(isset($event['message']) ? (string)$event['message'] : 'Unknown JS error', 2000);
        $errorType = $this->sanitizeText(isset($event['type']) ? (string)$event['type'] : 'runtime', 128);
        $url = $this->sanitizeUrl(isset($event['url']) ? (string)$event['url'] : '');
        $referrer = $this->sanitizeUrl(isset($event['referrer']) ? (string)$event['referrer'] : '');
        $ua = $this->sanitizeText(isset($event['user_agent']) ? (string)$event['user_agent'] : (string)Tools::getUserAgent(), 1500);

        $stack = isset($event['stack_trace']) && is_array($event['stack_trace']) ? $event['stack_trace'] : [];
        $stack = array_slice(array_map([$this, 'sanitizeStackFrame'], $stack), 0, 50);
        $top = $stack ? $stack[0] : [];

        $extra = [
            'meta' => isset($event['meta']) && is_array($event['meta']) ? $event['meta'] : [],
            'tags' => isset($event['tags']) && is_array($event['tags']) ? $event['tags'] : [],
            'raw_type' => isset($event['raw_type']) ? (string)$event['raw_type'] : null,
        ];

        $idCustomer = $this->context->customer ? (int)$this->context->customer->id : 0;
        $customerHash = $idCustomer ? hash('sha1', $idCustomer.'|'._COOKIE_KEY_) : null;

        $fingerprintBase = implode('|', [
            (int)$this->context->shop->id,
            $severity,
            $errorType,
            $message,
            isset($top['url']) ? $top['url'] : '',
            isset($top['line']) ? (int)$top['line'] : 0,
            isset($top['column']) ? (int)$top['column'] : 0,
        ]);
        $fingerprint = sha1($fingerprintBase);

        $db = Db::getInstance();
        $existing = $db->getRow((new DbQuery())
            ->select('id_collectlogs_js_error')
            ->from('collectlogs_js_error')
            ->where("fingerprint = '".pSQL($fingerprint)."'")
            ->where('id_shop = '.(int)$this->context->shop->id)
            ->where('last_seen >= DATE_SUB(NOW(), INTERVAL 24 HOUR)')
            ->orderBy('id_collectlogs_js_error DESC')
        );

        if ($existing) {
            return $db->execute('UPDATE `'._DB_PREFIX_."collectlogs_js_error` SET `occurrences`=`occurrences`+1, `last_seen`='".pSQL($now)."' WHERE id_collectlogs_js_error=".(int)$existing['id_collectlogs_js_error']);
        }

        return $db->insert('collectlogs_js_error', [
            'id_shop' => (int)$this->context->shop->id,
            'id_lang' => $this->context->language ? (int)$this->context->language->id : null,
            'id_customer' => $idCustomer ?: null,
            'customer_hash' => $customerHash,
            'visitor_id' => pSQL($visitorId) ?: null,
            'severity' => pSQL($severity),
            'message' => pSQL($message, true),
            'error_type' => pSQL($errorType),
            'url' => pSQL($url, true),
            'referrer' => pSQL($referrer, true),
            'user_agent' => pSQL($ua, true),
            'dt_iso' => pSQL($now),
            'stack_trace_json' => pSQL(json_encode($stack), true),
            'extra_json' => pSQL(json_encode($extra), true),
            'script_url' => pSQL(isset($top['url']) ? (string)$top['url'] : '', true),
            'line' => isset($top['line']) ? (int)$top['line'] : null,
            'column' => isset($top['column']) ? (int)$top['column'] : null,
            'fingerprint' => pSQL($fingerprint),
            'occurrences' => 1,
            'first_seen' => pSQL($now),
            'last_seen' => pSQL($now),
            'status' => 'open',
        ], false, true, Db::INSERT, false);
    }

    protected function sanitizeStackFrame($frame)
    {
        if (! is_array($frame)) {
            return [];
        }
        return [
            'url' => $this->sanitizeUrl(isset($frame['url']) ? (string)$frame['url'] : ''),
            'func' => $this->sanitizeText(isset($frame['func']) ? (string)$frame['func'] : '?', 200),
            'args' => [],
            'line' => isset($frame['line']) ? (int)$frame['line'] : null,
            'column' => isset($frame['column']) ? (int)$frame['column'] : null,
        ];
    }

    protected function sanitizeText($value, $maxLen)
    {
        $value = preg_replace('/[\r\n\t]+/', ' ', trim((string)$value));
        $value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $value);
        $value = preg_replace('/\+?[0-9][0-9\s\-]{7,}[0-9]/', '[phone]', $value);
        if (Tools::strlen($value) > $maxLen) {
            $value = Tools::substr($value, 0, $maxLen);
        }
        return $value;
    }

    protected function sanitizeUrl($url)
    {
        $url = $this->sanitizeText($url, 2000);
        if (! $url) {
            return '';
        }
        $parts = @parse_url($url);
        if (! is_array($parts)) {
            return '';
        }
        $safe = '';
        if (isset($parts['scheme'])) {
            $safe .= $parts['scheme'].'://';
        }
        if (isset($parts['host'])) {
            $safe .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $safe .= ':'.(int)$parts['port'];
        }
        if (isset($parts['path'])) {
            $safe .= $parts['path'];
        }
        return $safe;
    }
}
