<?php

declare(strict_types=1);

namespace Osmium\Services\Vonage\Models;

/**
 * Sends SMS through Vonage's SMS API. A capability only: it never decides
 * when to text. Core or another service asks through the sms hooks.
 *
 * sms.providers - collect(); no payload. Returns this provider's descriptor:
 *            id, label, ready (enabled and credentials valid) and settingsRoute,
 *            so Shop settings can offer SMS only when it can actually send.
 *
 * sms.send - handle(); payload: to (string, any common phone format), body
 *            (string). Returns null when this service is off or not
 *            configured (so the caller can tell nothing handled it), else
 *            ['sent' => bool, 'detail' => string]. A number that cannot be
 *            made valid is a failed send, not an exception.
 *
 * Whether and when to text (for example only in production) is the caller's
 * decision, since sending costs money.
 */
class VonageSms
{
    private const SEND_URL = 'https://rest.nexmo.com/sms/json';

    /** Per-message status codes from Vonage's SMS troubleshooting guide. 0 is success. */
    private const STATUS_MEANINGS = [
        '1' => 'throttled: sending faster than the account allows',
        '2' => 'a required parameter is missing',
        '3' => 'a parameter is invalid',
        '4' => 'invalid credentials: check the API key and secret',
        '5' => 'Vonage internal error',
        '6' => 'Vonage could not process the message',
        '7' => 'the destination number is barred',
        '8' => 'the Vonage account is barred',
        '9' => 'not enough Vonage credit to send',
        '11' => 'this Vonage account is not enabled for the SMS API',
        '12' => 'the message is too long',
        '15' => 'invalid sender: this sender ID is not allowed for that destination',
        '29' => 'destination not whitelisted (demo account)',
        '33' => 'the destination number is deactivated',
    ];

    /**
     * @return array{id: string, label: string, ready: bool, settingsRoute: string}
     */
    public static function provider(): array
    {
        $config = VonageConfig::get();

        return [
            'id' => 'vonage',
            'label' => 'Vonage SMS',
            'ready' => ($config->enabled ?? false) && self::isConfigured($config),
            'settingsRoute' => 'admin/settings/vonage',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{sent: bool, detail: string}|null
     */
    public static function send(array $payload): ?array
    {
        $config = VonageConfig::get();
        $active = ($config->enabled ?? false) && self::isConfigured($config);
        if (!$active) return null;

        $to = self::toE164((string) ($payload['to'] ?? ''));
        if ($to === '') return ['sent' => false, 'detail' => 'error: not a valid phone number'];

        $result = self::deliver(config: $config, to: $to, body: (string) ($payload['body'] ?? ''));

        return ['sent' => \str_starts_with($result, 'ok'), 'detail' => $result];
    }

    /**
     * UK-focused: a leading 0 becomes +44 and a leading 00 becomes +. A number
     * already starting with + keeps its country code. Anything that does not
     * end up as valid E.164 returns '' so the caller skips it.
     */
    public static function toE164(string $phone): string
    {
        $trimmed = \trim($phone);
        $hasPlus = \str_starts_with($trimmed, '+');
        $digits = \preg_replace('/\D/', '', $trimmed) ?? '';
        if ($digits === '') return '';

        if ($hasPlus) $candidate = '+' . $digits;
        elseif (\str_starts_with($digits, '00')) $candidate = '+' . \substr($digits, 2);
        elseif (\str_starts_with($digits, '0')) $candidate = '+44' . \substr($digits, 1);
        elseif (\str_starts_with($digits, '44')) $candidate = '+' . $digits; // Typed with the code but no +
        else return '';

        return VonageConfig::isValidE164($candidate) ? $candidate : '';
    }

    /**
     * Posts one message to Vonage. Used by the hook and the settings page's
     * test button, which sends even while the service is switched off.
     *
     * Vonage wants numbers without the leading +. It answers HTTP 200 even
     * for a refused message, so success is the per-message status "0".
     *
     * @return string "ok: <message id>" or "error: <reason>"
     */
    public static function deliver(object $config, string $to, string $body): string
    {
        $sender = (string) $config->sender;
        $fields = [
            'from' => VonageConfig::isValidE164($sender) ? \ltrim($sender, '+') : $sender,
            'to' => \ltrim($to, '+'),
            'text' => $body,
        ];

        $curl = \curl_init(self::SEND_URL);
        \curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => \http_build_query($fields),
            CURLOPT_USERPWD => $config->apiKey . ':' . $config->apiSecret,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
        ]);
        $response = \curl_exec($curl);
        $status = (int) \curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = \curl_error($curl);

        if ($response === false) return "error: {$curlError}";

        $decoded = \json_decode((string) $response, associative: true);
        $message = \is_array($decoded) ? ($decoded['messages'][0] ?? null) : null;
        if ($message === null) return "error: HTTP {$status} unexpected response";

        $messageStatus = (string) ($message['status'] ?? '');
        if ($messageStatus === '0') return 'ok: ' . (string) ($message['message-id'] ?? '');

        $reason = self::STATUS_MEANINGS[$messageStatus] ?? (string) ($message['error-text'] ?? 'unknown'); // error-text is not in Vonage's documented response, so only a fallback

        return "error: Vonage status {$messageStatus} ({$reason})";
    }

    public static function isConfigured(object $config): bool
    {
        return VonageConfig::isValidApiKey((string) ($config->apiKey ?? ''))
            && VonageConfig::isValidApiSecret((string) ($config->apiSecret ?? ''))
            && VonageConfig::isValidSender((string) ($config->sender ?? ''));
    }
}
