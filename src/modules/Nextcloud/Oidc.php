<?php

declare(strict_types=1);

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Nextcloud;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Minimal OpenID Connect provider (authorization code flow, RS256) used to sign
 * clients in to Nextcloud through the user_oidc app.
 *
 * Environment: NEXTCLOUD_URL, NEXTCLOUD_OIDC_CLIENT_ID (default "nextcloud"),
 * NEXTCLOUD_OIDC_CLIENT_SECRET, NEXTCLOUD_OIDC_PROVIDER_ID (default 1).
 */
class Oidc
{
    private const CODE_TTL = 60;
    private const TOKEN_TTL = 600;
    private const SSO_TICKET_TTL = 30;

    public function __construct(private readonly \Pimple\Container $di)
    {
    }

    public function isConfigured(): bool
    {
        return $this->nextcloudUrl() !== '' && $this->clientSecret() !== '';
    }

    public function nextcloudUrl(): string
    {
        return rtrim((string) getenv('NEXTCLOUD_URL'), '/');
    }

    public function loginUrl(): string
    {
        $providerId = (int) (getenv('NEXTCLOUD_OIDC_PROVIDER_ID') ?: 1);

        return $this->nextcloudUrl() . '/apps/user_oidc/login/' . $providerId;
    }

    /** Login page of the customer portal; defaults to FOSSBilling's own login when unset. */
    public function portalLoginUrl(): ?string
    {
        $url = trim((string) getenv('NEXTCLOUD_PORTAL_LOGIN_URL'));

        return preg_match('#^https?://#', $url) === 1 ? $url : null;
    }

    public function issuer(): string
    {
        return rtrim(SYSTEM_URL, '/');
    }

    public function discovery(): array
    {
        $base = $this->issuer() . '/nextcloud/oidc';

        return [
            'issuer' => $this->issuer(),
            'authorization_endpoint' => $base . '/authorize',
            'token_endpoint' => $base . '/token',
            'userinfo_endpoint' => $base . '/userinfo',
            'jwks_uri' => $base . '/jwks',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['openid', 'profile', 'email'],
            'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'nonce', 'email', 'email_verified', 'name', 'preferred_username'],
        ];
    }

    public function jwks(): array
    {
        $details = openssl_pkey_get_details($this->privateKey());
        $rsa = $details['rsa'];

        return ['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $this->kid(),
            'n' => self::b64($rsa['n']),
            'e' => self::b64($rsa['e']),
        ]]];
    }

    public function validAuthorizeRequest(array $query): bool
    {
        return ($query['response_type'] ?? '') === 'code'
            && hash_equals($this->clientId(), (string) ($query['client_id'] ?? ''))
            && $this->isAllowedRedirectUri((string) ($query['redirect_uri'] ?? ''));
    }

    /** Issues a one-time authorization code for the given client and returns the redirect URL. */
    public function authorize(int $clientId, array $query): string
    {
        $code = bin2hex(random_bytes(32));
        $this->store('code_' . hash('sha256', $code), [
            'client_id' => $clientId,
            'nonce' => $query['nonce'] ?? null,
            'challenge' => $query['code_challenge'] ?? null,
            'redirect_uri' => (string) ($query['redirect_uri'] ?? ''),
        ], self::CODE_TTL);

        $params = ['code' => $code];
        $redirectUri = (string) $query['redirect_uri'];
        if (isset($query['state'])) {
            $params['state'] = (string) $query['state'];
        }

        return $redirectUri . '?' . http_build_query($params);
    }

    /** Creates a one-time ticket that lets a client's browser start a FOSSBilling session. */
    public function createSsoTicket(int $clientId): string
    {
        $ticket = bin2hex(random_bytes(32));
        $this->store('sso_' . hash('sha256', $ticket), ['client_id' => $clientId], self::SSO_TICKET_TTL);

        return $ticket;
    }

    public function consumeSsoTicket(string $ticket): ?int
    {
        $data = $this->take('sso_' . hash('sha256', $ticket));

        return $data === null ? null : (int) $data['client_id'];
    }

    /**
     * @return array{status:int,body:array}
     */
    public function token(array $post, ?string $authUser, ?string $authPass): array
    {
        $id = $authUser ?? (string) ($post['client_id'] ?? '');
        $secret = $authPass ?? (string) ($post['client_secret'] ?? '');
        if (!hash_equals($this->clientId(), $id) || !hash_equals($this->clientSecret(), $secret)) {
            return ['status' => 401, 'body' => ['error' => 'invalid_client']];
        }

        $key = 'code_' . hash('sha256', (string) ($post['code'] ?? ''));
        $data = $this->take($key);
        $valid = ($post['grant_type'] ?? '') === 'authorization_code'
            && $data !== null
            && hash_equals((string) $data['redirect_uri'], (string) ($post['redirect_uri'] ?? ''));
        if ($valid && $data['challenge'] !== null) {
            $verifier = (string) ($post['code_verifier'] ?? '');
            $valid = hash_equals((string) $data['challenge'], self::b64(hash('sha256', $verifier, true)));
        }
        if (!$valid) {
            return ['status' => 400, 'body' => ['error' => 'invalid_grant']];
        }

        $claims = $this->claims((int) $data['client_id']);
        if ($claims === null) {
            return ['status' => 400, 'body' => ['error' => 'invalid_grant']];
        }

        $accessToken = bin2hex(random_bytes(32));
        $this->store('access_' . hash('sha256', $accessToken), ['client_id' => (int) $data['client_id']], self::TOKEN_TTL);

        $now = time();
        $idClaims = $claims + [
            'iss' => $this->issuer(),
            'aud' => $this->clientId(),
            'iat' => $now,
            'exp' => $now + self::TOKEN_TTL,
        ];
        if ($data['nonce'] !== null) {
            $idClaims['nonce'] = $data['nonce'];
        }

        return ['status' => 200, 'body' => [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_TTL,
            'id_token' => $this->sign($idClaims),
        ]];
    }

    public function userinfo(string $accessToken): ?array
    {
        $data = $this->load('access_' . hash('sha256', $accessToken));

        return $data === null ? null : $this->claims((int) $data['client_id']);
    }

    private function claims(int $clientId): ?array
    {
        $client = $this->di['em']->getRepository(\Box\Mod\Client\Entity\Client::class)->find($clientId);
        if (!$client instanceof \Box\Mod\Client\Entity\Client || $client->getStatus() !== \Box\Mod\Client\Entity\Client::ACTIVE) {
            return null;
        }

        $email = (string) $client->getEmail();
        $name = trim($client->getFirstName() . ' ' . $client->getLastName());

        return [
            'sub' => 'fb_' . $clientId,
            'preferred_username' => 'fb_' . $clientId,
            'email' => $email,
            'email_verified' => (bool) $client->getEmailApproved(),
            'name' => $name !== '' ? $name : $email,
        ];
    }

    private function clientId(): string
    {
        return (string) (getenv('NEXTCLOUD_OIDC_CLIENT_ID') ?: 'nextcloud');
    }

    private function clientSecret(): string
    {
        return (string) getenv('NEXTCLOUD_OIDC_CLIENT_SECRET');
    }

    private function isAllowedRedirectUri(string $uri): bool
    {
        $base = $this->nextcloudUrl();

        return hash_equals($base . '/apps/user_oidc/code', $uri)
            || hash_equals($base . '/index.php/apps/user_oidc/code', $uri);
    }

    private function sign(array $claims): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->kid()];
        $input = self::b64(json_encode($header, JSON_THROW_ON_ERROR)) . '.' . self::b64(json_encode($claims, JSON_THROW_ON_ERROR));
        openssl_sign($input, $signature, $this->privateKey(), OPENSSL_ALGO_SHA256);

        return $input . '.' . self::b64($signature);
    }

    private function kid(): string
    {
        $details = openssl_pkey_get_details($this->privateKey());

        return substr(hash('sha256', $details['rsa']['n']), 0, 16);
    }

    private function privateKey(): \OpenSSLAsymmetricKey
    {
        $path = Path::join(PATH_DATA, 'nextcloud_oidc_key.pem');
        $filesystem = new Filesystem();
        if (!$filesystem->exists($path)) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
            $filesystem->dumpFile($path, $pem);
            $filesystem->chmod($path, 0o600);
        }

        return openssl_pkey_get_private($filesystem->readFile($path));
    }

    private function store(string $key, array $value, int $ttl): void
    {
        $cache = $this->di['cache'];
        $item = $cache->getItem('nextcloud_oidc_' . $key);
        $item->set($value)->expiresAfter($ttl);
        $cache->save($item);
    }

    private function load(string $key): ?array
    {
        $item = $this->di['cache']->getItem('nextcloud_oidc_' . $key);

        return $item->isHit() ? $item->get() : null;
    }

    /** Loads and deletes the entry so it can only be used once. */
    private function take(string $key): ?array
    {
        $data = $this->load($key);
        $this->di['cache']->deleteItem('nextcloud_oidc_' . $key);

        return $data;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
