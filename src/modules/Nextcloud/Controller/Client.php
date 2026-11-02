<?php

declare(strict_types=1);

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Nextcloud\Controller;

use Box\Mod\Nextcloud\Oidc;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class Client implements \FOSSBilling\InjectionAwareInterface
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

    public function register(\Box_App &$app): void
    {
        $app->get('/nextcloud/sso', 'get_sso', [], static::class);
        $app->get('/nextcloud/open', 'get_open', [], static::class);
        $app->get('/nextcloud/oidc/discovery', 'get_discovery', [], static::class);
        $app->get('/nextcloud/oidc/jwks', 'get_jwks', [], static::class);
        $app->get('/nextcloud/oidc/authorize', 'get_authorize', [], static::class);
        $app->post('/nextcloud/oidc/token', 'post_token', [], static::class);
        $app->get('/nextcloud/oidc/userinfo', 'get_userinfo', [], static::class);
        $app->post('/nextcloud/oidc/userinfo', 'get_userinfo', [], static::class);
    }

    private function oidc(): Oidc
    {
        return new Oidc($this->di);
    }

    private function json(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status, ['Cache-Control' => 'no-store']);
    }

    public function get_sso(\Box_App $app): Response
    {
        $ticket = (string) $app->getRequest()->query->get('ticket', '');
        $clientId = $ticket !== '' ? $this->oidc()->consumeSsoTicket($ticket) : null;
        if ($clientId === null) {
            return new Response('This link is invalid or has expired.', 403, ['Cache-Control' => 'no-store']);
        }

        $client = $this->di['em']->getRepository(\Box\Mod\Client\Entity\Client::class)->find($clientId);
        if (!$client instanceof \Box\Mod\Client\Entity\Client || $client->getStatus() !== \Box\Mod\Client\Entity\Client::ACTIVE) {
            return new Response('This link is invalid or has expired.', 403, ['Cache-Control' => 'no-store']);
        }

        $this->di['session']->regenerateId();
        $this->di['session']->set('client_id', $client->getId());

        // A page-initiated navigation (not a 302) keeps the SameSite=Strict session cookie
        // attached when the ticket link was opened from another origin, such as a headless frontend.
        $target = htmlspecialchars($this->di['url']->link('nextcloud/open'), ENT_QUOTES);

        return new Response(
            '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><meta http-equiv="refresh" content="0;url=' . $target . '"><a href="' . $target . '">Continue</a>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'],
        );
    }

    public function get_open(\Box_App $app): Response
    {
        $this->di['is_client_logged'];
        $oidc = $this->oidc();
        if (!$oidc->isConfigured()) {
            return new Response('Storage is not available.', 503);
        }

        return $app->redirectUrl($oidc->loginUrl());
    }

    public function get_discovery(\Box_App $app): JsonResponse
    {
        return $this->json($this->oidc()->discovery());
    }

    public function get_jwks(\Box_App $app): JsonResponse
    {
        return $this->json($this->oidc()->jwks());
    }

    public function get_authorize(\Box_App $app): Response
    {
        $oidc = $this->oidc();
        $query = $app->getRequest()->query->all();
        if (!$oidc->isConfigured() || !$oidc->validAuthorizeRequest($query)) {
            return $this->json(['error' => 'invalid_request'], 400);
        }

        if (!$this->di['auth']->isClientLoggedIn() && $oidc->portalLoginUrl() !== null) {
            return $app->redirectUrl($oidc->portalLoginUrl());
        }

        $this->di['is_client_logged'];
        $client = $this->di['loggedin_client'];

        return $app->redirectUrl($oidc->authorize((int) $client->getId(), $query));
    }

    public function post_token(\Box_App $app): JsonResponse
    {
        $request = $app->getRequest();
        $result = $this->oidc()->token(
            $request->request->all(),
            $request->headers->has('PHP_AUTH_USER') ? (string) $request->headers->get('PHP_AUTH_USER') : null,
            $request->headers->has('PHP_AUTH_PW') ? (string) $request->headers->get('PHP_AUTH_PW') : null,
        );

        return $this->json($result['body'], $result['status']);
    }

    public function get_userinfo(\Box_App $app): JsonResponse
    {
        $header = (string) $app->getRequest()->headers->get('Authorization', '');
        $claims = str_starts_with($header, 'Bearer ') ? $this->oidc()->userinfo(substr($header, 7)) : null;

        return $claims === null ? $this->json(['error' => 'invalid_token'], 401) : $this->json($claims);
    }
}
