<?php

declare(strict_types=1);

use Appwrite\Utopia\Request;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Http\Http;

require \dirname(__DIR__, 3) . '/app/init.php';

// This fixture starts the production HTTP server with a deterministic deletion
// between the update handler's first read and its write. The hook exists only
// in this test server; normal requests follow the unchanged production path.
Http::init()
    ->groups(['presences'])
    ->inject('request')
    ->inject('dbForProject')
    ->action(function (Request $request, Database $db): void {
        $presenceId = $request->getHeaderLine('x-appwrite-test-presence-delete');
        if ($request->getMethod() !== 'PATCH' || $presenceId === '') {
            return;
        }

        $db->on(Database::EVENT_DOCUMENT_READ, 'delete-presence', function (string $event, Document $document) use ($db, $presenceId): void {
            if ($document->getCollection() !== 'presenceLogs' || $document->getId() !== $presenceId) {
                return;
            }
            $db->on(Database::EVENT_DOCUMENT_READ, 'delete-presence', null);
            $db->deleteDocument('presenceLogs', $presenceId);
        });
    });

require \dirname(__DIR__, 3) . '/app/http.php';
