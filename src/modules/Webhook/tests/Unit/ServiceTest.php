<?php

declare(strict_types=1);

use Box\Mod\Support\Event\AfterTicketRepliedEvent;
use Box\Mod\Support\Event\TicketActorRole;

test('ignores replies that were not written by an admin', function (): void {
    $di = new Pimple\Container();
    $di['mod_service'] = fn () => throw new RuntimeException('must not be called');

    $service = new Box\Mod\Webhook\Service();
    $service->setDi($di);

    $service->onAfterAdminReplyTicket(new AfterTicketRepliedEvent(1, TicketActorRole::CLIENT));

    expect(true)->toBeTrue();
});
