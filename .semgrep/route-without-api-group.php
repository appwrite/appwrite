<?php

use Utopia\Http\Http;

class RouteWithoutApiGroupFixture
{
    public function bad(): void
    {
        // ruleid: php.appwrite.route-without-api-group
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/things/:thingId')
            ->groups(['things'])
            ->label('scope', 'things.write')
            ->callback($this->action(...));
    }

    public function badNoGroups(): void
    {
        // ruleid: php.appwrite.route-without-api-group
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/things')
            ->label('scope', ['things.read', 'documents.read'])
            ->callback($this->action(...));
    }

    public function good(): void
    {
        // ok: php.appwrite.route-without-api-group
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/things/:thingId')
            ->groups(['api', 'things'])
            ->label('scope', 'things.write')
            ->callback($this->action(...));
    }

    public function publicForwarder(): void
    {
        // ok: php.appwrite.route-without-api-group
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/things/callback')
            ->groups(['things'])
            ->label('scope', 'public')
            ->callback($this->action(...));
    }
}

// ruleid: php.appwrite.route-without-api-group
Http::post('/v1/things')
    ->groups(['things'])
    ->label('scope', 'things.write')
    ->action(function () {
    });

// ok: php.appwrite.route-without-api-group
Http::post('/v1/graphql')
    ->groups(['graphql'])
    ->label('scope', 'graphql')
    ->action(function () {
    });
