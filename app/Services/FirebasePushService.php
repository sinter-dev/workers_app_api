<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FirebasePushService
{
    private ?array $credentials = null;
    private ?string $accessToken = null;
    private int $accessTokenExpiresAt = 0;

    /**
     * Send one push notification to every registered device for a user.
     *
     * Push delivery is deliberately best-effort. Callers should keep
     * Laravel/MySQL notifications as the source of truth.
     */
    public function sendToUser(
        int $userId,
        string $title,
        string $body,
        array $data = []
    ): array {
        $devices = DeviceToken::query()
            ->where('user_id', $userId)
            ->get();

        $result = [
            'devices' => $devices->count(),
            'sent' => 0,
            'failed' => 0,
            'removed' => 0,
        ];

        if ($devices->isEmpty()) {
            return $result;
        }

        foreach ($devices as $device) {
            try {
                $response = $this->sendToToken(
                    $device->token,
                    $title,
                    $body,
                    $data
                );

                if ($response['success']) {
                    $result['sent']++;
                    $device->forceFill([
                        'last_used_at' => now(),
                    ])->save();
                    continue;
                }

                $result['failed']++;

                if ($response['invalid_token']) {
                    $device->delete();
                    $result['removed']++;
                }
            } catch (Throwable $error) {
                $result['failed']++;

                Log::warning('Firebase push delivery failed.', [
                    'user_id' => $userId,
                    'device_token_id' => $device->id,
                    'message' => $error->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Send a single HTTP v1 FCM message.
     */
    public function sendToToken(
        string $token,
        string $title,
        string $body,
        array $data = []
    ): array {
        $credentials = $this->credentials();
        $projectId = $credentials['project_id'] ?? null;

        if (!$projectId) {
            throw new RuntimeException(
                'Firebase service account does not contain project_id.'
            );
        }

        $payloadData = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $payloadData[(string) $key] = is_scalar($value)
                ? (string) $value
                : json_encode($value);
        }

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(15)
            ->post(
                "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
                [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => $payloadData,
                        'android' => [
                            'priority' => 'high',
                            'notification' => [
                                'channel_id' => 'worklink_messages',
                                'sound' => 'default',
                                'default_vibrate_timings' => true,
                                'notification_priority' => 'PRIORITY_MAX',
                                'visibility' => 'PUBLIC',
                                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                            ],
                        ],
                        'apns' => [
                            'headers' => [
                                'apns-priority' => '10',
                            ],
                            'payload' => [
                                'aps' => [
                                    'sound' => 'default',
                                    'badge' => 1,
                                    'content-available' => 1,
                                ],
                            ],
                        ],
                    ],
                ]
            );

        if ($response->successful()) {
            return [
                'success' => true,
                'invalid_token' => false,
                'status' => $response->status(),
                'body' => $response->json(),
            ];
        }

        $responseBody = $response->json();
        $errorText = json_encode($responseBody);
        $invalidToken =
            $response->status() === 404 ||
            str_contains($errorText, 'UNREGISTERED') ||
            str_contains($errorText, 'registration-token-not-registered');

        Log::warning('Firebase rejected a push message.', [
            'status' => $response->status(),
            'response' => $responseBody,
        ]);

        return [
            'success' => false,
            'invalid_token' => $invalidToken,
            'status' => $response->status(),
            'body' => $responseBody,
        ];
    }

    private function credentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $path = config('services.firebase.credentials');

        if (!$path || !is_file($path)) {
            throw new RuntimeException(
                'Firebase service account file was not found at: ' .
                ($path ?: '[not configured]')
            );
        }

        $decoded = json_decode(
            file_get_contents($path),
            true
        );

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Firebase service account JSON is invalid.'
            );
        }

        foreach (['client_email', 'private_key', 'token_uri', 'project_id'] as $field) {
            if (empty($decoded[$field])) {
                throw new RuntimeException(
                    "Firebase service account is missing {$field}."
                );
            }
        }

        $this->credentials = $decoded;

        return $this->credentials;
    }

    /**
     * Exchange a signed service-account JWT for a Google OAuth access token.
     */
    private function accessToken(): string
    {
        if (
            $this->accessToken !== null &&
            time() < ($this->accessTokenExpiresAt - 60)
        ) {
            return $this->accessToken;
        }

        $credentials = $this->credentials();
        $now = time();

        $header = $this->base64UrlEncode(
            json_encode([
                'alg' => 'RS256',
                'typ' => 'JWT',
            ])
        );

        $claims = $this->base64UrlEncode(
            json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $credentials['token_uri'],
                'iat' => $now,
                'exp' => $now + 3600,
            ])
        );

        $unsignedToken = "{$header}.{$claims}";
        $signature = '';

        $signed = openssl_sign(
            $unsignedToken,
            $signature,
            $credentials['private_key'],
            OPENSSL_ALGO_SHA256
        );

        if (!$signed) {
            throw new RuntimeException(
                'Unable to sign Firebase service-account JWT.'
            );
        }

        $assertion = $unsignedToken . '.' .
            $this->base64UrlEncode($signature);

        $response = Http::asForm()
            ->timeout(15)
            ->post(
                $credentials['token_uri'],
                [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]
            );

        if (!$response->successful()) {
            throw new RuntimeException(
                'Unable to obtain Firebase OAuth token. HTTP ' .
                $response->status() . ': ' .
                $response->body()
            );
        }

        $token = $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 3600);

        if (!$token) {
            throw new RuntimeException(
                'Google OAuth response did not include access_token.'
            );
        }

        $this->accessToken = $token;
        $this->accessTokenExpiresAt = time() + $expiresIn;

        return $this->accessToken;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(
            strtr(
                base64_encode($value),
                '+/',
                '-_'
            ),
            '='
        );
    }
}
