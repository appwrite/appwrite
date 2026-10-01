<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\TransactionState;
use Appwrite\Utopia\Database\Documents\User;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;

final class TransactionStateTest extends TestCase
{
    public function testNumericReplayPreservesTheRowAndCommittedValues(): void
    {
        $authorization = new Authorization();
        $authorization->addRole('any');
        $adapter = new Memory();
        $adapter->setSupportForAttributes(false);
        $db = new Database($adapter, new Cache(new None()));
        $db->setNamespace('transaction-test')->setAuthorization($authorization);
        $db->create('transaction-test');
        foreach (['transactions', 'transactionLogs', 'database_1_collection_1'] as $collection) {
            $db->createCollection($collection, permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ]);
        }
        $transaction = $db->createDocument('transactions', new Document([
            '$id' => 'tx',
            '$permissions' => [Permission::read(Role::any())],
            'status' => 'pending',
        ]));
        $row = $db->createDocument('database_1_collection_1', new Document([
            '$id' => 'row',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'balance' => 50,
            'name' => 'Account',
        ]));
        foreach ([
            ['increment', ['column' => 'balance', 'value' => 50]],
            ['increment', ['attribute' => 'balance', 'value' => 25]],
            ['decrement', ['column' => 'balance', 'value' => 10]],
        ] as $index => [$action, $data]) {
            $db->createDocument('transactionLogs', new Document([
                '$id' => 'operation' . $index,
                'databaseInternalId' => '1',
                'collectionInternalId' => '1',
                'transactionInternalId' => $transaction->getSequence(),
                'documentId' => 'row',
                'action' => $action,
                'data' => $data,
            ]));
        }
        $state = new TransactionState($db, $authorization, fn (Document $database): Database => $db, new User());
        $result = $state->getDocument(new Document(['$id' => 'database']), 'database_1_collection_1', 'row', 'tx');

        $this->assertSame(115, $result->getAttribute('balance'));
        $this->assertSame('Account', $result->getAttribute('name'));
        $this->assertSame($row->getPermissions(), $result->getPermissions());
        $this->assertSame($row->getCreatedAt(), $result->getCreatedAt());
        $this->assertSame($row->getSequence(), $result->getSequence());
        $this->assertSame(50, $db->getDocument('database_1_collection_1', 'row')->getAttribute('balance'));
    }
}
