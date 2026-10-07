<?php

declare(strict_types=1);

namespace Osmium\Services\Vonage\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\Vonage\Models\VonageConfig;
use Osmium\Services\Vonage\Models\VonageSms;

/**
 * Vonage SMS settings controller - a full-page form POST/redirect flow,
 * matching the Meta Pixel service.
 *
 * Routes:
 *   - index() → /admin/settings/vonage/  (GET shows the form, POST saves it
 *     or, with action=send_test, sends a test text with the saved settings)
 */
class VonageController extends AdminController
{
    private const CONFIG_FILE_PATH = 'app/config/services/vonage.json.php';
    private const DEFAULT_CONFIG = <<<'JSON'
        <?php exit(); ?>
        {
            "vonage": {
                "enabled": false,
                "apiKey": "",
                "apiSecret": "",
                "sender": ""
            }
        }
        JSON;

    public function index(): void
    {
        $isPost = $this->isPost();
        if ($isPost) $this->handleSubmit();

        $config = (array) VonageConfig::get();
        $config['apiSecretSet'] = ($config['apiSecret'] ?? '') !== '';
        unset($config['apiSecret']); // The saved secret never reaches the page
        $this->data['admin']['config']['vonage'] = $config;
        $this->data['admin']['settingsSaved'] = $_SESSION['vonage_settings_saved'] ?? false;
        $this->data['admin']['settingsError'] = $_SESSION['vonage_settings_error'] ?? false;
        $this->data['admin']['testResult'] = $_SESSION['vonage_test_result'] ?? false;
        unset($_SESSION['vonage_settings_saved'], $_SESSION['vonage_settings_error'], $_SESSION['vonage_test_result']);

        $this->setView('vonage/index.phtml');
    }

    private function handleSubmit(): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) {
            $_SESSION['vonage_settings_error'] = 'Invalid form submission. Please try again.';
            $this->redirect('settings/vonage/');
        }

        $isTest = ($_POST['action'] ?? '') === 'send_test';
        if ($isTest) $this->handleTest();

        $enabled = isset($_POST['enabled']);
        $apiKey = \trim($_POST['api_key'] ?? '');
        $sender = \trim($_POST['sender'] ?? '');

        $postedSecret = \trim($_POST['api_secret'] ?? '');
        $apiSecret = $postedSecret === '' ? (string) (VonageConfig::get()->apiSecret ?? '') : $postedSecret; // Blank keeps the stored secret

        $error = $this->validate(
            enabled: $enabled,
            apiKey: $apiKey,
            apiSecret: $apiSecret,
            sender: $sender,
        );
        if ($error !== null) {
            $_SESSION['vonage_settings_error'] = $error;
            $this->redirect('settings/vonage/');
        }

        $this->saveConfig(
            enabled: $enabled,
            apiKey: $apiKey,
            apiSecret: $apiSecret,
            sender: $sender,
        );

        $this->admin->model->changelog->log(
            description: 'Updated Vonage SMS settings',
            recordType: 'settings',
        );

        VonageConfig::clearCache();

        $_SESSION['vonage_settings_saved'] = true;
        $this->redirect('settings/vonage/');
    }

    private function validate(bool $enabled, string $apiKey, string $apiSecret, string $sender): ?string
    {
        $hasAnyCredential = $apiKey !== '' || $apiSecret !== '' || $sender !== '';
        $needsValidCredentials = $enabled || $hasAnyCredential;

        if ($needsValidCredentials && !VonageConfig::isValidApiKey($apiKey)) {
            return 'API key looks wrong: paste just the key from the Vonage dashboard, with no spaces or colons.';
        }
        if ($needsValidCredentials && !VonageConfig::isValidApiSecret($apiSecret)) {
            return 'API secret looks wrong: paste just the secret from the Vonage dashboard, at least 8 characters with no spaces.';
        }
        if ($needsValidCredentials && !VonageConfig::isValidSender($sender)) {
            return 'Sender must be a number like +447700900123, an alphanumeric sender ID (up to 11 characters, at least one letter), with at least one letter.';
        }

        return null;
    }

    /** Sends a test text using the saved settings, so what is tested is what is live. */
    private function handleTest(): void
    {
        $config = VonageConfig::get();
        $to = VonageSms::toE164(\trim($_POST['test_number'] ?? ''));

        if (!VonageSms::isConfigured($config)) {
            $_SESSION['vonage_settings_error'] = 'Save valid credentials and a sender first, then send a test.';
        } elseif ($to === '') {
            $_SESSION['vonage_settings_error'] = 'Enter a mobile number to text, such as 07700 900123 or +447700900123.';
        } else {
            $_SESSION['vonage_test_result'] = VonageSms::deliver(config: $config, to: $to, body: 'This is a test text from your Osmium site.');
        }

        $this->redirect('settings/vonage/');
    }

    private function saveConfig(
        bool $enabled,
        string $apiKey,
        string $apiSecret,
        string $sender,
    ): void
    {
        $configExists = \file_exists(self::CONFIG_FILE_PATH);
        if (!$configExists) $this->ensureConfigDirectoryExists();

        $content = $configExists ? \file_get_contents(self::CONFIG_FILE_PATH) : self::DEFAULT_CONFIG;

        $jsonStart = \strpos(haystack: $content, needle: '{');
        $phpHeader = \substr(string: $content, offset: 0, length: $jsonStart);
        $data = \json_decode(\substr(string: $content, offset: $jsonStart), associative: true) ?? [];

        $data['vonage'] = [
            'enabled' => $enabled,
            'apiKey' => $apiKey,
            'apiSecret' => $apiSecret,
            'sender' => $sender,
        ];

        $newJson = \json_encode(
            value: $data,
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        \file_put_contents(self::CONFIG_FILE_PATH, $phpHeader . $newJson . "\n");
    }

    private function ensureConfigDirectoryExists(): void
    {
        $dir = \dirname(self::CONFIG_FILE_PATH);
        $alreadyExists = \is_dir($dir);
        if (!$alreadyExists) \mkdir(directory: $dir, permissions: 0755, recursive: true);
    }

    private function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }
}
