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
    const SETTINGS_CLIENT_LOGGING_ENABLED = 'COLLECTLOGS_CLIENT_LOGGING_ENABLED';
    const SETTINGS_CLIENT_LOGGING_SAMPLE_RATE = 'COLLECTLOGS_CLIENT_LOGGING_SAMPLE_RATE';
    const SETTINGS_CLIENT_LOGGING_MAX_EVENTS = 'COLLECTLOGS_CLIENT_LOGGING_MAX_EVENTS';
    const SETTINGS_CLIENT_LOGGING_INCLUDE_QUERY = 'COLLECTLOGS_CLIENT_LOGGING_INCLUDE_QUERY';
    const SETTINGS_CLIENT_LOGGING_INCLUDE_STACK = 'COLLECTLOGS_CLIENT_LOGGING_INCLUDE_STACK';
    const SETTINGS_CLIENT_LOGGING_RETENTION_DAYS = 'COLLECTLOGS_CLIENT_LOGGING_RETENTION_DAYS';

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

    public function getClientLoggingEnabled()
    {
        return $this->getBoolValue(static::SETTINGS_CLIENT_LOGGING_ENABLED, true);
    }

    public function setClientLoggingEnabled($value)
    {
        return $this->setBoolValue(static::SETTINGS_CLIENT_LOGGING_ENABLED, $value);
    }

    public function getClientLoggingSampleRate()
    {
        $value = (int)Configuration::getGlobalValue(static::SETTINGS_CLIENT_LOGGING_SAMPLE_RATE);
        if ($value < 1 || $value > 100) {
            return $this->setClientLoggingSampleRate(100);
        }
        return $value;
    }

    public function setClientLoggingSampleRate($value)
    {
        $value = max(1, min(100, (int)$value));
        Configuration::updateGlobalValue(static::SETTINGS_CLIENT_LOGGING_SAMPLE_RATE, $value);
        return $value;
    }

    public function getClientLoggingMaxEvents()
    {
        $value = (int)Configuration::getGlobalValue(static::SETTINGS_CLIENT_LOGGING_MAX_EVENTS);
        if ($value < 1 || $value > 100) {
            return $this->setClientLoggingMaxEvents(10);
        }
        return $value;
    }

    public function setClientLoggingMaxEvents($value)
    {
        $value = max(1, min(100, (int)$value));
        Configuration::updateGlobalValue(static::SETTINGS_CLIENT_LOGGING_MAX_EVENTS, $value);
        return $value;
    }

    public function getClientLoggingIncludeQueryString()
    {
        return $this->getBoolValue(static::SETTINGS_CLIENT_LOGGING_INCLUDE_QUERY, false);
    }

    public function setClientLoggingIncludeQueryString($value)
    {
        return $this->setBoolValue(static::SETTINGS_CLIENT_LOGGING_INCLUDE_QUERY, $value);
    }

    public function getClientLoggingIncludeStackTrace()
    {
        return $this->getBoolValue(static::SETTINGS_CLIENT_LOGGING_INCLUDE_STACK, true);
    }

    public function setClientLoggingIncludeStackTrace($value)
    {
        return $this->setBoolValue(static::SETTINGS_CLIENT_LOGGING_INCLUDE_STACK, $value);
    }

    public function getClientLoggingRetentionDays()
    {
        $value = (int)Configuration::getGlobalValue(static::SETTINGS_CLIENT_LOGGING_RETENTION_DAYS);
        if ($value < 1 || $value > 3650) {
            return $this->setClientLoggingRetentionDays(30);
        }
        return $value;
    }

    public function setClientLoggingRetentionDays($value)
    {
        $value = max(1, min(3650, (int)$value));
        Configuration::updateGlobalValue(static::SETTINGS_CLIENT_LOGGING_RETENTION_DAYS, $value);
        return $value;
    }


}
