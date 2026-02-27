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
 * Front controller that accepts client-side JS error reports.
 *
 * Endpoint: POST /module/collectlogs/jslog
 * Content-Type: application/json
 */
class CollectLogsJslogModuleFrontController extends ModuleFrontController
{
    /** @var CollectLogs */
    public $module;

    /** Maximum raw body size accepted (bytes) */
    const MAX_BODY_BYTES = 65536; // 64 KB

    /** Maximum events accepted per request */
    const MAX_EVENTS_PER_REQUEST = 20;

    /** Token validity window in seconds (2 hours) */
    const TOKEN_TTL = 7200;

    /** Deduplication window in seconds (24 hours) */
    const DEDUP_WINDOW = 86400;

    /** Rate limit: max requests per IP per minute */
    const RATE_LIMIT_PER_MINUTE = 30;

    /**
     * Disable all page rendering — this is a pure JSON API endpoint.
     *
     * @var bool
     */
    public $ajax = true;

    /**
     * @return void
     * @throws PrestaShopException
     */
    public function init()
    {
        // Skip parent init to avoid unnecessary overhead and cookie writes.
        // We only need the DB and basic context.
        $this->sendCorsHeaders();

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            // Pre-flight
            http_response_code(204);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonError('Method not allowed', 405);
        }

        $settings = $this->module->getSettings();

        if (!$settings->getJsLoggingEnabled()) {
            $this->jsonError('JS logging disabled', 403);
        }

        $payload = $this->readAndValidateBody();
        $idShop  = $this->validateToken($payload['token'] ?? '');

        if (!$this->checkRateLimit($idShop)) {
            $this->jsonError('Rate limit exceeded', 429);
        }

        $events = $payload['events'] ?? [];
        if (!is_array($events) || empty($events)) {
            $this->jsonError('No events', 400);
        }

        $events = array_slice($events, 0, static::MAX_EVENTS_PER_REQUEST);

        $stored = 0;
        foreach ($events as $event) {
            if (is_array($event)) {
                $stored += (int)$this->processEvent($event, $idShop, $settings);
            }
        }

        $this->jsonSuccess(['stored' => $stored]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Read, size-check, and JSON-decode the request body.
     *
     * @return array
     */
    private function readAndValidateBody()
    {
        $raw = file_get_contents('php://input', false, null, 0, static::MAX_BODY_BYTES + 1);
        if ($raw === false || $raw === '') {
            $this->jsonError('Empty body', 400);
        }
        if (strlen($raw) > static::MAX_BODY_BYTES) {
            $this->jsonError('Payload too large', 413);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->jsonError('Invalid JSON', 400);
        }
        return $data;
    }

    /**
     * Validate the HMAC token injected by the module on page load.
     *
     * Token format: base64url( id_shop:timestamp:nonce ) + '.' + hex_hmac
     *
     * @param string $token
     * @return int  id_shop (validated)
     */
    private function validateToken($token)
    {
        if (!$token || !is_string($token)) {
            $this->jsonError('Missing token', 403);
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            $this->jsonError('Invalid token format', 403);
        }

        $payload = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $hmac    = $parts[1];

        if ($payload === false) {
            $this->jsonError('Invalid token encoding', 403);
        }

        $fields = explode(':', $payload, 3);
        if (count($fields) !== 3) {
            $this->jsonError('Invalid token payload', 403);
        }

        list($idShop, $ts, $nonce) = $fields;
        $idShop = (int)$idShop;
        $ts     = (int)$ts;

        if ($idShop < 1 || $ts < 1 || !$nonce) {
            $this->jsonError('Invalid token fields', 403);
        }

        $age = time() - $ts;
        if ($age < -60 || $age > static::TOKEN_TTL) {
            $this->jsonError('Token expired', 403);
        }

        $secret   = _COOKIE_KEY_;
        $expected = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $hmac)) {
            $this->jsonError('Invalid token signature', 403);
        }

        return $idShop;
    }

    /**
     * Simple rate limiting using the DB.
     * Returns false if the IP has exceeded the threshold.
     *
     * @param int $idShop
     * @return bool
     */
    private function checkRateLimit($idShop)
    {
        $ip  = $this->getClientIp();
        $key = 'jslog_rl_' . md5($ip . ':' . $idShop);

        // Use PS cache if available; fall back to a DB-based counter.
        if (class_exists('Cache') && method_exists('Cache', 'getInstance')) {
            try {
                $cache  = Cache::getInstance();
                $count  = (int)$cache->get($key);
                if ($count >= static::RATE_LIMIT_PER_MINUTE) {
                    return false;
                }
                $cache->set($key, $count + 1, 60);
                return true;
            } catch (Throwable $e) {
                // Cache unavailable — fall through to allow request
            }
        }

        // Without cache we can't easily rate-limit; allow the request.
        return true;
    }

    /**
     * Validate, sanitize and persist a single error event.
     *
     * @param array $event
     * @param int   $idShop
     * @param \CollectLogsModule\Settings $settings
     * @return bool  true if a row was inserted/updated
     */
    private function processEvent(array $event, $idShop, $settings)
    {
        $severity  = $this->sanitizeSeverity($event['severity'] ?? 'error');
        $message   = $this->sanitizeText($event['message'] ?? '', 1000);
        $errorType = $this->sanitizeText($event['error_type'] ?? '', 128);
        $url       = $this->sanitizeUrl($event['url'] ?? '', $settings->getJsIncludeQueryString());
        $referrer  = $this->sanitizeUrl($event['referrer'] ?? '', false);
        $userAgent = $this->sanitizeText($event['user_agent'] ?? '', 512);
        $scriptUrl = $this->sanitizeUrl($event['script_url'] ?? '', true);
        $line      = isset($event['line']) ? max(0, (int)$event['line']) : null;
        $col       = isset($event['col'])  ? max(0, (int)$event['col'])  : null;
        $dtIso     = $this->sanitizeDateTime($event['dt_iso'] ?? '');
        $stackJson = null;
        $extraJson = null;

        if ($settings->getJsIncludeStackTrace()) {
            $stack = $event['stack_trace'] ?? null;
            if (is_array($stack)) {
                $stackJson = json_encode($stack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        $tags = $event['tags'] ?? null;
        if (is_array($tags)) {
            $extraJson = json_encode(['tags' => $tags], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Compute fingerprint for deduplication
        $fingerprint = sha1(implode('|', [
            $idShop,
            $message,
            $errorType,
            $scriptUrl,
            (string)$line,
            (string)$col,
        ]));

        $db  = Db::getInstance();
        $now = date('Y-m-d H:i:s');

        // Deduplication: look for same fingerprint in last DEDUP_WINDOW seconds
        $existing = $db->getValue((new DbQuery())
            ->select('id_collectlogs_js_error')
            ->from('collectlogs_js_error')
            ->where('fingerprint = \'' . pSQL($fingerprint) . '\'')
            ->where('id_shop = ' . (int)$idShop)
            ->where('last_seen >= \'' . pSQL(date('Y-m-d H:i:s', time() - static::DEDUP_WINDOW)) . '\'')
        );

        if ($existing) {
            // Update existing row
            return (bool)$db->update(
                'collectlogs_js_error',
                [
                    'occurrences' => ['type' => 'sql', 'value' => '`occurrences` + 1'],
                    'last_seen'   => $now,
                ],
                'id_collectlogs_js_error = ' . (int)$existing
            );
        }

        // Insert new row
        $idCustomer = (int)($this->context->customer->id ?? 0);
        $idLang     = (int)($this->context->language->id ?? 0);

        return (bool)$db->insert('collectlogs_js_error', [
            'id_shop'          => (int)$idShop,
            'id_lang'          => $idLang ?: null,
            'id_customer'      => $idCustomer ?: null,
            'guest_token'      => pSQL($event['guest_token'] ?? ''),
            'severity'         => pSQL($severity),
            'message'          => pSQL($message),
            'error_type'       => pSQL($errorType),
            'url'              => pSQL($url),
            'referrer'         => $referrer ? pSQL($referrer) : null,
            'user_agent'       => pSQL($userAgent),
            'dt_iso'           => pSQL($dtIso),
            'stack_trace_json' => $stackJson,
            'extra_json'       => $extraJson,
            'script_url'       => $scriptUrl ? pSQL($scriptUrl) : null,
            'line'             => $line,
            'col'              => $col,
            'fingerprint'      => pSQL($fingerprint),
            'occurrences'      => 1,
            'first_seen'       => $now,
            'last_seen'        => $now,
        ]);
    }

    // -------------------------------------------------------------------------
    // Sanitization helpers
    // -------------------------------------------------------------------------

    /**
     * @param string $value
     * @param int    $maxLen
     * @return string
     */
    private function sanitizeText($value, $maxLen)
    {
        if (!is_string($value)) {
            return '';
        }
        $value = $this->maskPii($value);
        return mb_substr(strip_tags($value), 0, $maxLen);
    }

    /**
     * @param string $value
     * @param bool   $includeQueryString
     * @return string
     */
    private function sanitizeUrl($value, $includeQueryString)
    {
        if (!is_string($value)) {
            return '';
        }
        $value = $this->maskPii($value);
        // Trim extremely long URLs
        $value = mb_substr($value, 0, 2048);

        if (!$includeQueryString) {
            // Strip query string
            $qPos = strpos($value, '?');
            if ($qPos !== false) {
                $value = substr($value, 0, $qPos);
            }
        }
        return $value;
    }

    /**
     * @param string $value
     * @return string  validated datetime or NOW
     */
    private function sanitizeDateTime($value)
    {
        if (!is_string($value)) {
            return date('Y-m-d H:i:s');
        }
        // Accept ISO 8601 and convert to MySQL datetime
        $ts = strtotime($value);
        if ($ts === false) {
            return date('Y-m-d H:i:s');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * @param string $value
     * @return string
     */
    private function sanitizeSeverity($value)
    {
        $allowed = ['info', 'warn', 'error', 'fatal'];
        return in_array($value, $allowed, true) ? $value : 'error';
    }

    /**
     * Mask common PII patterns (email addresses, phone-like strings).
     *
     * @param string $value
     * @return string
     */
    private function maskPii($value)
    {
        // Mask email addresses
        $value = preg_replace('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', '[email]', $value);
        // Mask phone-like patterns (7+ digit sequences, possibly with spaces/dashes/parens)
        $value = preg_replace('/(?:\+?\d[\s\-.]?){7,}/', '[phone]', $value);
        return $value;
    }

    /**
     * @return string
     */
    private function getClientIp()
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    /**
     * @return void
     */
    private function sendCorsHeaders()
    {
        // Only allow same-origin requests — restrict to shop domain if possible.
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 600');
    }

    /**
     * @param array $data
     * @return void
     */
    private function jsonSuccess(array $data)
    {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(200);
        echo json_encode(array_merge(['ok' => true], $data));
        exit;
    }

    /**
     * @param string $message
     * @param int    $code
     * @return void
     */
    private function jsonError($message, $code = 400)
    {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $message]);
        exit;
    }
}
