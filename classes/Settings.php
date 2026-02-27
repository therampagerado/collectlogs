<?php

namespace CollectLogsModule;

use Configuration;
use PrestaShopException;
use ReflectionClass;
use Throwable;
use Tools;

class Settings
{
    const SETTINGS_SECRET = 'COLLECTLOGS_CRON_SECRET';
    const SETTINGS_LAST_CRON_EXECUTION = 'COLLECTLOGS_CRON_TS';
    const SETTINGS_SEND_NEW_ERRORS_EMAIL = 'COLLECTLOGS_SEND_NEW_ERRORS_EMAIL';
    const SETTINGS_NEW_ERRORS_EMAIL_ADDRESSES = 'COLLECTLOGS_NEW_ERRORS_EMAIL';
    const SETTINGS_LOG_TO_FILE = 'COLLECTLOGS_LOG_TO_FILE';
    const SETTINGS_LOG_TO_FILE_NEW_ONLY = 'COLLECTLOGS_LOG_TO_FILE_NEW_ONLY';
    const SETTINGS_LOG_TO_FILE_SEVERITY = 'COLLECTLOGS_LOG_TO_FILE_SEVERITY';
    const SETTINGS_LAST_SYNC = 'COLLECTLOGS_SYNC_TS';

    // JS error logging settings
    const SETTINGS_JS_LOGGING_ENABLED  = 'COLLECTLOGS_JS_ENABLED';
    const SETTINGS_JS_SAMPLING_RATE    = 'COLLECTLOGS_JS_SAMPLING';
    const SETTINGS_JS_MAX_EVENTS       = 'COLLECTLOGS_JS_MAX_EVENTS';
    const SETTINGS_JS_INCLUDE_QS       = 'COLLECTLOGS_JS_INCLUDE_QS';
    const SETTINGS_JS_INCLUDE_STACK    = 'COLLECTLOGS_JS_INCLUDE_STACK';
    const SETTINGS_JS_RETENTION_DAYS   = 'COLLECTLOGS_JS_RETENTION';

    /**
     * @return bool
     */
    public function cleanup()
    {
        try {
            // delete everything that starts with SETTINGS_*
            $reflection = new ReflectionClass(static::class);
            foreach ($reflection->getConstants() as $key => $configKey) {
                if (strpos($key, "SETTINGS_") === 0) {
                    Configuration::deleteByName($configKey);
                }
            }
        } catch (Throwable $ignored) {
        }

        return true;
    }

    /**
     * @return string
     * @throws PrestaShopException
     */
    public function getSecret()
    {
        $value = Configuration::getGlobalValue(static::SETTINGS_SECRET);
        if (!$value) {
            $value = Tools::passwdGen(32);
            Configuration::updateGlobalValue(static::SETTINGS_SECRET, $value);
        }
        return $value;
    }

    /**
     * @return int
     * @throws PrestaShopException
     */
    public function getCronLastExec()
    {
        return (int)Configuration::getGlobalValue(static::SETTINGS_LAST_CRON_EXECUTION);
    }

    /**
     * @return void
     * @throws PrestaShopException
     */
    public function updateCronLastExec()
    {
        Configuration::updateGlobalValue(static::SETTINGS_LAST_CRON_EXECUTION, time() - 1);
    }

    /**
     * @return int
     * @throws PrestaShopException
     */
    public function getLastSync()
    {
        return (int)Configuration::getGlobalValue(static::SETTINGS_LAST_SYNC);
    }

    /**
     * @return void
     * @throws PrestaShopException
     */
    public function updateLastSync(int $ts)
    {
        Configuration::updateGlobalValue(static::SETTINGS_LAST_SYNC, $ts);
    }

    /**
     * @return int
     *
     * @throws PrestaShopException
     */
    public function getLogToFileMinSeverity()
    {
        $value = (int)Configuration::getGlobalValue(static::SETTINGS_LOG_TO_FILE_SEVERITY);
        if (! Severity::isSeverityLevel($value)) {
            return $this->setLogToFileMinSeverity(Severity::SEVERITY_DEPRECATION);
        }
        return $value;
    }

    /**
     * @param int $value
     *
     * @return int
     * @throws PrestaShopException
     */
    public function setLogToFileMinSeverity($value)
    {
        $value = (int)$value;
        if (! Severity::isSeverityLevel($value)) {
            $value = Severity::SEVERITY_DEPRECATION;
        }
        Configuration::updateGlobalValue(static::SETTINGS_LOG_TO_FILE_SEVERITY, $value);
        return $value;
    }

    /**
     * @return bool
     * @throws PrestaShopException
     */
    public function getLogToFile()
    {
        return $this->getBoolValue(static::SETTINGS_LOG_TO_FILE, false);
    }

    /**
     * @param bool $value
     *
     * @return bool
     * @throws PrestaShopException
     */
    public function setLogToFile($value)
    {
        return $this->setBoolValue(static::SETTINGS_LOG_TO_FILE, $value);
    }

    /**
     * @return bool
     * @throws PrestaShopException
     */
    public function getLogToFileNewOnly()
    {
        return $this->getBoolValue(static::SETTINGS_LOG_TO_FILE_NEW_ONLY, true);
    }

    /**
     * @param bool $value
     *
     * @return bool
     * @throws PrestaShopException
     */
    public function setLogToFileNewOnly($value)
    {
        return $this->setBoolValue(static::SETTINGS_LOG_TO_FILE_NEW_ONLY, $value);
    }

    /**
     * @return bool
     * @throws PrestaShopException
     */
    public function getSendNewErrorsEmail()
    {
        return $this->getBoolValue(static::SETTINGS_SEND_NEW_ERRORS_EMAIL, false);
    }

    /**
     * @param bool $value
     *
     * @return bool
     * @throws PrestaShopException
     */
    public function setSendNewErrorsEmail($value)
    {
        return $this->setBoolValue(static::SETTINGS_SEND_NEW_ERRORS_EMAIL, $value);
    }

    /**
     * @return string[]
     * @throws PrestaShopException
     */
    public function getEmailAddresses()
    {
        $strValue = Configuration::getGlobalValue(static::SETTINGS_NEW_ERRORS_EMAIL_ADDRESSES);
        if (is_null($strValue) || $strValue === false) {
            return [];
        }
        return explode("\n", $strValue);
    }

    /**
     * @param string[] $emails
     *
     * @return string[]
     * @throws PrestaShopException
     */
    public function setEmailAddresses(array $emails)
    {
        if ($emails) {
            $strValue = implode("\n", $emails);
            Configuration::updateGlobalValue(static::SETTINGS_NEW_ERRORS_EMAIL_ADDRESSES, $strValue);
        } else {
            Configuration::deleteByName(static::SETTINGS_NEW_ERRORS_EMAIL_ADDRESSES);
        }
        return $emails;
    }

    /**
     * @param string $key
     * @param bool $default
     *
     * @return bool
     * @throws PrestaShopException
     */
    protected function getBoolValue($key, $default)
    {
        $value = Configuration::getGlobalValue($key);
        if (is_null($value) || $value === false) {
            return $this->setBoolValue($key, $default);
        }
        return (bool)$value;
    }

    /**
     * @param string $key
     * @param bool $value
     *
     * @return bool
     * @throws PrestaShopException
     */
    protected function setBoolValue($key, $value)
    {
        $value = (bool)$value;
        Configuration::updateGlobalValue($key, (int)$value);
        return $value;
    }

    /**
     * @param string $email
     *
     * @return string
     * @throws PrestaShopException
     */
    public function getUnsubscribeSecret($email)
    {
        return md5($email . $this->getSecret());
    }

    // -------------------------------------------------------------------------
    // JS error logging settings
    // -------------------------------------------------------------------------

    /**
     * @return bool
     * @throws PrestaShopException
     */
    public function getJsLoggingEnabled()
    {
        return $this->getBoolValue(static::SETTINGS_JS_LOGGING_ENABLED, false);
    }

    /**
     * @param bool $value
     * @return bool
     * @throws PrestaShopException
     */
    public function setJsLoggingEnabled($value)
    {
        return $this->setBoolValue(static::SETTINGS_JS_LOGGING_ENABLED, $value);
    }

    /**
     * Sampling rate as integer percentage 1-100.
     *
     * @return int
     * @throws PrestaShopException
     */
    public function getJsSamplingRate()
    {
        $v = (int)Configuration::getGlobalValue(static::SETTINGS_JS_SAMPLING_RATE);
        if ($v < 1 || $v > 100) {
            return 100;
        }
        return $v;
    }

    /**
     * @param int $value  1-100
     * @return int
     * @throws PrestaShopException
     */
    public function setJsSamplingRate($value)
    {
        $value = max(1, min(100, (int)$value));
        Configuration::updateGlobalValue(static::SETTINGS_JS_SAMPLING_RATE, $value);
        return $value;
    }

    /**
     * Maximum JS error events captured per page view.
     *
     * @return int
     * @throws PrestaShopException
     */
    public function getJsMaxEvents()
    {
        $v = (int)Configuration::getGlobalValue(static::SETTINGS_JS_MAX_EVENTS);
        if ($v < 1 || $v > 100) {
            return 10;
        }
        return $v;
    }

    /**
     * @param int $value
     * @return int
     * @throws PrestaShopException
     */
    public function setJsMaxEvents($value)
    {
        $value = max(1, min(100, (int)$value));
        Configuration::updateGlobalValue(static::SETTINGS_JS_MAX_EVENTS, $value);
        return $value;
    }

    /**
     * Whether to include URL query strings in the reported URL.
     *
     * @return bool
     * @throws PrestaShopException
     */
    public function getJsIncludeQueryString()
    {
        return $this->getBoolValue(static::SETTINGS_JS_INCLUDE_QS, false);
    }

    /**
     * @param bool $value
     * @return bool
     * @throws PrestaShopException
     */
    public function setJsIncludeQueryString($value)
    {
        return $this->setBoolValue(static::SETTINGS_JS_INCLUDE_QS, $value);
    }

    /**
     * Whether to capture and send stack traces.
     *
     * @return bool
     * @throws PrestaShopException
     */
    public function getJsIncludeStackTrace()
    {
        return $this->getBoolValue(static::SETTINGS_JS_INCLUDE_STACK, true);
    }

    /**
     * @param bool $value
     * @return bool
     * @throws PrestaShopException
     */
    public function setJsIncludeStackTrace($value)
    {
        return $this->setBoolValue(static::SETTINGS_JS_INCLUDE_STACK, $value);
    }

    /**
     * Auto-prune JS errors older than this many days (0 = no auto-prune).
     *
     * @return int
     * @throws PrestaShopException
     */
    public function getJsRetentionDays()
    {
        $v = Configuration::getGlobalValue(static::SETTINGS_JS_RETENTION_DAYS);
        if ($v === false || $v === null) {
            return 90;
        }
        return (int)$v;
    }

    /**
     * @param int $value  0 = disabled
     * @return int
     * @throws PrestaShopException
     */
    public function setJsRetentionDays($value)
    {
        $value = max(0, (int)$value);
        Configuration::updateGlobalValue(static::SETTINGS_JS_RETENTION_DAYS, $value);
        return $value;
    }
}