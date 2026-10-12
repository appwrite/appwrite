<?php

use Utopia\Http\Http;

class GuestWriteFixture
{
    public function bad(): void
    {
        // ruleid: php.appwrite.guest-write-without-abuse-limit
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/things')
            ->groups(['api'])
            ->label('scope', 'documents.write')
            ->callback($this->action(...));
    }

    public function good(): void
    {
        // ok: php.appwrite.guest-write-without-abuse-limit
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/things')
            ->groups(['api'])
            ->label('scope', 'documents.write')
            ->label('abuse-limit', 60)
            ->callback($this->action(...));
    }

    public function privileged(): void
    {
        // ok: php.appwrite.guest-write-without-abuse-limit
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/things')
            ->groups(['api'])
            ->label('scope', 'things.write')
            ->callback($this->action(...));
    }

    public function read(): void
    {
        // ok: php.appwrite.guest-write-without-abuse-limit
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/things')
            ->groups(['api'])
            ->label('scope', 'public')
            ->callback($this->action(...));
    }

    public function providerWebhook(): void
    {
        // ok: php.appwrite.guest-write-without-abuse-limit
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vcs/github/events')
            ->groups(['api', 'vcs'])
            ->label('scope', 'public')
            ->callback($this->action(...));
    }
}

// ruleid: php.appwrite.guest-write-without-abuse-limit
Http::patch('/v1/things/:thingId')
    ->groups(['api'])
    ->label('scope', 'public')
    ->action(function () {
    });

// ok: php.appwrite.guest-write-without-abuse-limit
Http::post('/v1/mock/things')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->action(function () {
    });
