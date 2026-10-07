<?php

use Utopia\Http\Http;

class RouteWithoutScopeFixture
{
    public function bad(): void
    {
        // ruleid: php.appwrite.route-without-scope
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/things')
            ->groups(['api', 'things'])
            ->param('name', '', new Text(128), 'Name.')
            ->callback($this->action(...));
    }

    public function good(): void
    {
        // ok: php.appwrite.route-without-scope
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/things')
            ->groups(['api', 'things'])
            ->label('scope', 'things.write')
            ->callback($this->action(...));
    }

    public function nonApiPath(): void
    {
        // ok: php.appwrite.route-without-scope
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/_appwrite/authorize')
            ->callback($this->action(...));
    }
}

// ruleid: php.appwrite.route-without-scope
Http::get('/v1/things/:thingId')
    ->groups(['api'])
    ->action(function () {
    });

// ok: php.appwrite.route-without-scope
Http::get('/v1/things/:thingId')
    ->groups(['api'])
    ->label('scope', 'public')
    ->action(function () {
    });
