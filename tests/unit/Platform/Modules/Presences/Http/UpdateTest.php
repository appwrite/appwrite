<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Presences\Http;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Presences\HTTP\Update;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

require_once __DIR__ . '/../../../../../../app/init.php';

final class UpdateTest extends TestCase
{
    private const string PRESENCE_ID = 'presence1';

    /**
     * A presence can expire or be deleted between the action's read and its write. The
     * database then returns an empty document from updateDocument, and serializing that as a
     * presence throws on `$permissions` (Sentry CLOUD-3QWW). The caller must get a 404.
     */
    public function testPresenceDeletedDuringUpdateIsNotFound(): void
    {
        $presence = new Document([
            '$id' => self::PRESENCE_ID,
            '$permissions' => [],
            'userId' => 'user1',
            'status' => 'online',
            'expiresAt' => null,
        ]);

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willReturn($presence);
        $dbForProject->method('updateDocument')->willReturn(new Document());

        $authorization = $this->createStub(Authorization::class);
        $authorization->method('getRoles')->willReturn(['any', 'guests']);

        $response = $this->createMock(Response::class);
        $response->expects($this->never())->method('dynamic');

        try {
            (new Update())->action(
                self::PRESENCE_ID,
                null,
                'away',
                null,
                null,
                null,
                false,
                $response,
                $dbForProject,
                new User(),
                $authorization,
                $this->createStub(Event::class),
            );
            $this->fail('Expected a presence not found exception');
        } catch (Exception $exception) {
            $this->assertSame(Exception::PRESENCE_NOT_FOUND, $exception->getType());
            $this->assertSame(404, $exception->getCode());
        }
    }
}
