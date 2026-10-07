<?php

declare(strict_types=1);

namespace Osmium\Services\Vonage\Models;

/**
 * Vonage SMS configuration helper.
 *
 * Loads this service's own settings file, matching the file-based config
 * convention used by the other services (app/config/services/{id}.json.php).
 */
class VonageConfig
{
    private static ?object $config = null;
    private static string $configPath = 'app/config/services/vonage.json.php';

    /**
     * Falls back to defaults (disabled, no credentials) if the config file is
     * missing, so installing this service stays inert until someone visits
     * its settings page and saves credentials.
     */
    public static function get(): object
    {
        $configLoaded = self::$config !== null;
        if ($configLoaded) return self::$config;

        $configExists = \file_exists(self::$configPath);
        if (!$configExists) {
            self::$config = self::defaults();
            return self::$config;
        }

        $content = (string) \file_get_contents(self::$configPath);
        $jsonStart = \strpos(haystack: $content, needle: '{');

        $noJsonFound = $jsonStart === false;
        if ($noJsonFound) {
            self::$config = self::defaults();
            return self::$config;
        }

        $decoded = \json_decode(\substr(string: $content, offset: $jsonStart));

        self::$config = (object) ((array) ($decoded->vonage ?? []) + (array) self::defaults());

        return self::$config;
    }

    public static function clearCache(): void
    {
        self::$config = null;
    }

    /**
     * Loose shape checks only: Vonage's exact key format is not documented,
     * and its documented secret rules (8-25 characters for newly created
     * secrets) may not hold for older accounts. These just stop whitespace or
     * a pasted sentence reaching the Authorization header ("key:secret", so a
     * key cannot contain a colon).
     */
    public static function isValidApiKey(string $key): bool
    {
        return \preg_match(pattern: '/^[^\s:]{4,64}$/', subject: $key) === 1;
    }

    public static function isValidApiSecret(string $secret): bool
    {
        return \preg_match(pattern: '/^\S{8,64}$/', subject: $secret) === 1;
    }

    /**
     * Sender is an E.164 number or an alphanumeric sender ID (1-11 letters,
     * digits or spaces with at least one letter). Alphanumeric IDs are not
     * accepted in every country.
     */
    public static function isValidSender(string $sender): bool
    {
        return self::isValidE164($sender)
            || \preg_match(pattern: '/^(?=.*[A-Za-z])[A-Za-z0-9 ]{1,11}$/', subject: $sender) === 1;
    }

    public static function isValidE164(string $number): bool
    {
        return \preg_match(pattern: '/^\+[1-9]\d{6,14}$/', subject: $number) === 1;
    }

    private static function defaults(): object
    {
        return (object) [
            'enabled' => false,
            'apiKey' => '',
            'apiSecret' => '',
            'sender' => '',
        ];
    }
}
