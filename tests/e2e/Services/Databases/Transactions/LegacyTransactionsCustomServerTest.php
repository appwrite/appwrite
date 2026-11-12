<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Transactions;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ApiLegacy;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Traits\DatabasesUrlHelpers;

final class LegacyTransactionsCustomServerTest extends Scope
{
    use TransactionsBase;
    use DatabasesUrlHelpers;
    use ProjectCustom;
    use SideServer;
    use ApiLegacy;

    /**
     * Regression: the legacy `/v1/databases/transactions` read routes (Get, XList) are part
     * of the legacy Databases surface, so a key scoped with `documents.read` must be able to
     * read transactions it can create and commit via `documents.write`. They were mislabeled
     * `rows.read` (the TablesDB scope), so a documents-only key was refused on its own
     * transactions while a rows-only key could read them. Fix: accept both scopes, matching
     * the TablesDB variants.
     */
    public function testLegacyTransactionReadsAcceptDocumentsReadScope(): void
    {
        $fullKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        // Create a transaction with a write-capable key.
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), $fullKeyHeaders);
        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        // A key scoped to documents.read only (legacy Databases read scope).
        $readKey = $this->getNewKey(['documents.read']);
        $readHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $readKey,
        ];

        // Test for SUCCESS: GET and LIST of legacy transactions must accept documents.read.
        // Before the fix the routes required rows.read, so these returned 401.
        $get = $this->client->call(Client::METHOD_GET, $this->getTransactionUrl($transactionId), $readHeaders);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals($transactionId, $get['body']['$id']);

        $list = $this->client->call(Client::METHOD_GET, $this->getTransactionUrl(), $readHeaders);
        $this->assertEquals(200, $list['headers']['status-code']);
    }
}
