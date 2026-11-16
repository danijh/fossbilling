<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Notification;

use Box\Mod\Cart\Event\AfterStaffOrderCreateEvent;
use Box\Mod\Client\Entity\Client;
use Box\Mod\Client\Event\AfterAdminClientCreateEvent;
use Box\Mod\Client\Event\AfterClientSignUpEvent;
use Box\Mod\Extension\Entity\ExtensionMeta;
use Box\Mod\Extension\Repository\ExtensionMetaRepository;
use Box\Mod\Invoice\Entity\Invoice;
use Box\Mod\Invoice\Event\AfterAdminInvoicePaymentReceivedEvent;
use Box\Mod\Notification\Event\AfterAdminNotificationAddEvent;
use Box\Mod\Order\Entity\Order;
use Box\Mod\Order\Event\AfterClientOrderCreateEvent;
use Box\Mod\Support\Entity\SupportTicket;
use Box\Mod\Support\Event\AfterTicketOpenedEvent;
use Box\Mod\Support\Event\TicketActorRole;
use FOSSBilling\InjectionAwareInterface;
use FOSSBilling\SortOptions;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    private ?ExtensionMetaRepository $extensionMetaRepository = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
        $this->extensionMetaRepository = isset($this->di['em'])
            ? $this->di['em']->getRepository(ExtensionMeta::class)
            : null;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        return [
            'view' => [
                'type' => 'bool',
                'display_name' => __trans('View notifications'),
                'description' => __trans('Allows the staff member to view notifications.'),
            ],
            'manage' => [
                'type' => 'bool',
                'display_name' => __trans('Manage notifications'),
                'description' => __trans('Allows the staff member to add and delete notifications.'),
            ],
        ];
    }

    public function getExtensionMetaRepository(): ExtensionMetaRepository
    {
        if ($this->extensionMetaRepository === null) {
            if ($this->di === null) {
                throw new \FOSSBilling\Exception('The dependency injection container has not been set.');
            }

            $this->extensionMetaRepository = $this->di['em']->getRepository(ExtensionMeta::class);
        }

        return $this->extensionMetaRepository;
    }

    public function getSearchQueryBuilder(array $filter = []): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->getExtensionMetaRepository()
            ->createQueryBuilderForExtension('mod_notification', 'n')
            ->andWhere('n.metaKey = :metaKey')
            ->setParameter('metaKey', 'message');

        if (!empty($filter['id'])) {
            $qb->andWhere('n.id = :id')
                ->setParameter('id', (int) $filter['id']);
        }

        if (!empty($filter['search'])) {
            $qb->andWhere('n.metaValue LIKE :search')
                ->setParameter('search', '%' . $filter['search'] . '%');
        }

        if (!empty($filter['date_from'])) {
            $qb->andWhere('n.createdAt >= :date_from')
                ->setParameter('date_from', new \DateTime(date('Y-m-d 00:00:00', strtotime((string) $filter['date_from']))));
        }

        if (!empty($filter['date_to'])) {
            $qb->andWhere('n.createdAt <= :date_to')
                ->setParameter('date_to', new \DateTime(date('Y-m-d 23:59:59', strtotime((string) $filter['date_to']))));
        }

        $sort = SortOptions::fromArray($filter, [
            'id' => 'n.id',
            'created_at' => 'n.createdAt',
            'updated_at' => 'n.updatedAt',
        ]);
        if ($sort->isSorted()) {
            $qb->orderBy($sort->expression, $sort->direction);
            if ($sort->expression !== 'n.id') {
                $qb->addOrderBy('n.id', $sort->direction);
            }
        } else {
            $qb->orderBy('n.id', \SortDirection::Descending);
        }

        return $qb;
    }

    public function toApiArray(ExtensionMeta $row): array
    {
        return $row->toApiArray();
    }

    public function get(int $id): ExtensionMeta
    {
        $meta = $this->getExtensionMetaRepository()->findOneByExtensionAndId('mod_notification', $id);
        if (!$meta instanceof ExtensionMeta || $meta->getMetaKey() !== 'message') {
            throw new \FOSSBilling\InformationException('Notification message was not found');
        }

        return $meta;
    }

    public function create(string $message): int
    {
        $meta = (new ExtensionMeta())
            ->setExtension('mod_notification')
            ->setRelType('staff')
            ->setRelId('1')
            ->setMetaKey('message')
            ->setMetaValue($message);

        $this->di['em']->persist($meta);
        $this->di['em']->flush();

        $id = $meta->getId();
        if ($id === null) {
            throw new \FOSSBilling\Exception('Failed to create notification message: missing ID after persistence.');
        }
        $this->di['event_dispatcher']->dispatch(new AfterAdminNotificationAddEvent($id));

        return $id;
    }

    public function delete(int $id): bool
    {
        $meta = $this->get($id);
        $this->di['em']->remove($meta);
        $this->di['em']->flush();

        return true;
    }

    public function deleteAll(): bool
    {
        $this->getExtensionMetaRepository()->deleteByExtensionAndScope('mod_notification', 'message');

        return true;
    }

    #[AsEventListener]
    public function onClientSignUp(AfterClientSignUpEvent $event): void
    {
        $this->notifySafely('New client signed up: ' . $this->clientLabel($event->clientId));
    }

    #[AsEventListener]
    public function onAdminClientCreate(AfterAdminClientCreateEvent $event): void
    {
        $this->notifySafely('New client created by staff: ' . $this->clientLabel($event->clientId));
    }

    #[AsEventListener]
    public function onClientOrderCreate(AfterClientOrderCreateEvent $event): void
    {
        $this->notifySafely('New order from client: ' . $this->orderLabel($event->orderId, $event->clientId));
    }

    #[AsEventListener]
    public function onStaffOrderCreate(AfterStaffOrderCreateEvent $event): void
    {
        $this->notifySafely('Staff created order: ' . $this->orderLabel($event->orderId, $event->clientId));
    }

    #[AsEventListener]
    public function onTicketOpened(AfterTicketOpenedEvent $event): void
    {
        $actor = match ($event->actor) {
            TicketActorRole::ADMIN => 'staff',
            TicketActorRole::CLIENT => 'client',
            TicketActorRole::GUEST => 'guest',
        };

        $this->notifySafely('New ' . $actor . ' ticket: ' . $this->ticketLabel($event->ticketId));
    }

    #[AsEventListener]
    public function onInvoicePaymentReceived(AfterAdminInvoicePaymentReceivedEvent $event): void
    {
        $this->notifySafely('Invoice paid: ' . $this->invoiceLabel($event->invoiceId));
    }

    private function notifySafely(string $message): void
    {
        try {
            $this->create($message);
        } catch (\Throwable $exception) {
            $this->di['logger']->error('Failed to store admin notification: {message}', [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function clientLabel(?int $clientId): string
    {
        if ($clientId === null) {
            return 'unknown client';
        }

        try {
            $client = $this->di['mod_service']('client')->getClientRepository()->find($clientId);
        } catch (\Throwable) {
            $client = null;
        }

        if (!$client instanceof Client) {
            return '#' . $clientId;
        }

        $name = trim($client->getFullName() . ' <' . (string) $client->getEmail() . '>');

        return $name !== '<>' ? $name . ' (#' . $clientId . ')' : '#' . $clientId;
    }

    private function orderLabel(int $orderId, ?int $clientId = null): string
    {
        $client = $clientId !== null ? ' for ' . $this->clientLabel($clientId) : '';

        try {
            $order = $this->di['mod_service']('order')->getOrderRepository()->find($orderId);
        } catch (\Throwable) {
            $order = null;
        }

        if (!$order instanceof Order) {
            return '#' . $orderId . $client;
        }

        $details = [];
        if ($order->getTitle() !== null && $order->getTitle() !== '') {
            $details[] = '"' . $order->getTitle() . '"';
        }
        $price = trim((string) $order->getPrice() . ' ' . (string) $order->getCurrency());
        if ($price !== '') {
            $details[] = $price;
        }
        if ($order->getPeriod() !== null && $order->getPeriod() !== '') {
            $details[] = '/' . $order->getPeriod();
        }

        return '#' . $orderId . $client . ($details === [] ? '' : ' — ' . implode(' ', $details));
    }

    private function ticketLabel(int $ticketId): string
    {
        try {
            $ticket = $this->di['mod_service']('support')->getTicketById($ticketId);
        } catch (\Throwable) {
            $ticket = null;
        }

        if (!$ticket instanceof SupportTicket) {
            return '#' . $ticketId;
        }

        $subject = $ticket->getSubject();

        return '#' . $ticketId . ($subject !== null && $subject !== '' ? ' — "' . $subject . '"' : '');
    }

    private function invoiceLabel(int $invoiceId): string
    {
        try {
            $invoice = $this->di['mod_service']('invoice')->getInvoiceRepository()->find($invoiceId);
        } catch (\Throwable) {
            $invoice = null;
        }

        if (!$invoice instanceof Invoice) {
            return '#' . $invoiceId;
        }

        $number = trim((string) $invoice->getSerie() . ' ' . (string) $invoice->getNr());
        $label = $number !== '' ? $number : '#' . $invoiceId;

        try {
            $total = $this->di['mod_service']('invoice')->getTotalWithTax($invoice);
            $currency = $invoice->getCurrency();
            if ($total > 0) {
                $label .= ' — ' . number_format($total, 2) . ($currency !== null ? ' ' . $currency : '');
            }
        } catch (\Throwable) {
            // Total is optional detail; the invoice number is enough.
        }

        return $label;
    }
}
