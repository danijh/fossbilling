<?php

declare(strict_types=1);

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Nextcloud;

use Box\Mod\Client\Event\AfterAdminClientCreateEvent;
use Box\Mod\Client\Event\AfterClientSignUpEvent;
use FOSSBilling\InjectionAwareInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Provisions a Nextcloud account for every new client.
 *
 * Configured through environment variables:
 * NEXTCLOUD_URL, NEXTCLOUD_USER, NEXTCLOUD_APP_PASSWORD,
 * and optionally NEXTCLOUD_GROUP, NEXTCLOUD_QUOTA, NEXTCLOUD_VERIFY_TLS.
 */
class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    #[AsEventListener]
    public function onAfterClientSignUp(AfterClientSignUpEvent $event): void
    {
        $this->provision($event->clientId);
    }

    #[AsEventListener]
    public function onAfterAdminClientCreate(AfterAdminClientCreateEvent $event): void
    {
        $this->provision($event->clientId);
    }

    public function provision(int $clientId): bool
    {
        $url = rtrim((string) getenv('NEXTCLOUD_URL'), '/');
        $user = (string) getenv('NEXTCLOUD_USER');
        $pass = (string) getenv('NEXTCLOUD_APP_PASSWORD');
        if ($url === '' || $user === '' || $pass === '') {
            return false;
        }

        $logger = $this->di['logger']->withChannel('nextcloud');

        try {
            $client = $this->di['mod_service']('client')->get(['id' => $clientId]);
            $email = (string) $client->getEmail();
            $name = trim($client->getFirstName() . ' ' . $client->getLastName());

            $form = [
                'userid' => 'fb_' . $clientId,
                'email' => $email,
                'displayName' => $name !== '' ? $name : $email,
            ];
            if (($group = (string) getenv('NEXTCLOUD_GROUP')) !== '') {
                $form['groups'] = [$group];
            }
            if (($quota = (string) getenv('NEXTCLOUD_QUOTA')) !== '') {
                $form['quota'] = $quota;
            }

            // Reuse the password from the current request when it matches the stored hash.
            // Otherwise none is sent, so Nextcloud emails the user a link to set one.
            $password = $this->requestPassword($client->getPass());
            if ($password !== null) {
                $form['password'] = $password;
            }

            $response = $this->di['http_client']->request('POST', $url . '/ocs/v1.php/cloud/users?format=json', [
                'auth_basic' => [$user, $pass],
                'headers' => ['OCS-APIRequest' => 'true', 'Accept' => 'application/json'],
                'body' => $form,
                'timeout' => 15,
                'verify_peer' => getenv('NEXTCLOUD_VERIFY_TLS') !== '0',
                'verify_host' => getenv('NEXTCLOUD_VERIFY_TLS') !== '0',
            ]);
            $data = $response->toArray(false);
            $code = (int) ($data['ocs']['meta']['statuscode'] ?? 0);

            // 100 = created, 102 = user already exists (idempotent)
            if ($code === 100 || $code === 102) {
                return true;
            }

            $logger->error('Nextcloud user creation failed', ['client_id' => $clientId, 'response' => $data['ocs']['meta'] ?? $data]);
        } catch (\Throwable $e) {
            $logger->error('Nextcloud user creation failed', ['client_id' => $clientId, 'exception' => $e]);
        }

        return false;
    }

    private function requestPassword(?string $hash): ?string
    {
        $request = $this->di['request'];
        $json = json_decode((string) $request->getContent(), true);
        $candidate = $request->request->get('password')
            ?? (is_array($json) ? ($json['password'] ?? null) : null);

        if (!is_string($candidate) || $candidate === '' || $hash === null || $hash === '') {
            return null;
        }

        return $this->di['password']->verify($candidate, $hash) ? $candidate : null;
    }
}
