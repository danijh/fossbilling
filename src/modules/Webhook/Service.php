<?php

declare(strict_types=1);

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Webhook;

use Box\Mod\Support\Event\AfterTicketClosedEvent;
use Box\Mod\Support\Event\AfterTicketRepliedEvent;
use Box\Mod\Support\Event\TicketActorRole;
use FOSSBilling\Config;
use FOSSBilling\InjectionAwareInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Configure in config.php:
 *   'webhook' => ['url' => 'https://realtime.example.com/hook', 'secret' => '...'],
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
    public function onAfterAdminReplyTicket(AfterTicketRepliedEvent $event): void
    {
        if ($event->actor !== TicketActorRole::ADMIN) {
            return;
        }

        $this->sendTicketWebhook('onAfterAdminReplyTicket', $event->ticketId);
    }

    #[AsEventListener]
    public function onAfterAdminCloseTicket(AfterTicketClosedEvent $event): void
    {
        if ($event->actor !== TicketActorRole::ADMIN) {
            return;
        }

        $this->sendTicketWebhook('onAfterAdminCloseTicket', $event->ticketId);
    }

    private function sendTicketWebhook(string $eventName, int $ticketId): void
    {
        $di = $this->di ?? throw new \LogicException('The Webhook service dependency injection container has not been set.');

        $config = Config::getProperty('webhook', []);
        $url = is_array($config) ? (string) ($config['url'] ?? '') : '';
        $secret = is_array($config) ? (string) ($config['secret'] ?? '') : '';
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return;
        }

        try {
            $ticket = $di['mod_service']('support')->getTicketById($ticketId);

            // Guest tickets have no dashboard to notify.
            if ($ticket->isGuestTicket()) {
                return;
            }

            $body = json_encode([
                'event' => $eventName,
                'timestamp' => time(),
                'data' => [
                    'ticket_id' => $ticket->getId(),
                    'client_id' => $ticket->getClientId(),
                    'subject' => $ticket->getSubject(),
                    'status' => $ticket->getStatus(),
                ],
            ], JSON_THROW_ON_ERROR);

            $headers = ['Content-Type' => 'application/json'];
            if ($secret !== '') {
                $headers['X-FOSSBilling-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
            }

            $di['http_client']->request('POST', $url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => 5,
            ])->getStatusCode();
        } catch (\Throwable $exc) {
            $di['logger']->error('Failed to send support ticket webhook', ['event' => $eventName, 'exception' => $exc]);
        }
    }
}
