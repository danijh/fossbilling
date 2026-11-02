<?php

declare(strict_types=1);

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 */

/**
 * Single sign-on to the client's Nextcloud storage.
 */

namespace Box\Mod\Nextcloud\Api;

use Box\Mod\Nextcloud\Oidc;

class Client extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Returns a short-lived, single-use URL that opens the client's Nextcloud
     * without another login. Intended for headless frontends.
     */
    public function sso_url(array $data): array
    {
        $oidc = new Oidc($this->getDi());
        if (!$oidc->isConfigured()) {
            throw new \FOSSBilling\InformationException('Storage is not available.');
        }

        $ticket = $oidc->createSsoTicket((int) $this->getIdentity()->getId());

        return ['url' => $this->getDi()['url']->link('nextcloud/sso', ['ticket' => $ticket])];
    }
}
