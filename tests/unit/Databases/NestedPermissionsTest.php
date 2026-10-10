<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Hook\Permissions;
use Utopia\Database\Hook\Relationships;
use Utopia\Database\Permission;
use Utopia\Database\Relationship;
use Utopia\Database\RelationshipDeleteAction;
use Utopia\Database\RelationshipType;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;

/**
 * Document create has no API-side permission gate: the database hooks appwrite installs decide nested related
 * documents, written the way the create action writes them. API keys pass; a session needs create on the related collection for a new nested document and update
 * (collection-level, or document-level under document security) to link or change a stored one.
 */
final class NestedPermissionsTest extends TestCase
{
    private const string USER = 'u1';
    private const string STORED = 'stored';
    private const string NEW = 'fresh';
    private const string NO_CREATE = "No permissions provided for action 'create'";
    private const string NO_UPDATE = "No permissions provided for action 'update'";

    private const string KEY = 'key';
    private const string SESSION = 'session';
    private const string GUEST = 'guest';

    private Authorization $authorization;

    private Database $database;

    /**
     * @return iterable<string, array{RelationshipType, bool, list<string>, list<string>, string, string, ?string}>
     */
    public static function cases(): iterable
    {
        $read = [Permission::read(Role::users())];
        $create = [...$read, Permission::create(Role::users())];
        $update = [...$read, Permission::update(Role::users())];
        $own = [Permission::read(Role::user(self::USER)), Permission::update(Role::user(self::USER))];

        foreach ([RelationshipType::OneToMany, RelationshipType::OneToOne, RelationshipType::ManyToMany] as $type) {
            $name = $type->value;

            yield "{$name}: a session creates a nested document with create" => [$type, false, $create, [], self::NEW, self::SESSION, null];
            yield "{$name}: a session without create cannot create a nested document" => [$type, false, $read, [], self::NEW, self::SESSION, self::NO_CREATE];
            yield "{$name}: a session links with collection update" => [$type, false, $update, [], self::STORED, self::SESSION, null];
            yield "{$name}: a session without update cannot link" => [$type, false, $read, [], self::STORED, self::SESSION, self::NO_UPDATE];
            yield "{$name}: a session links with document update under document security" => [$type, true, $read, $own, self::STORED, self::SESSION, null];
            yield "{$name}: document security without any update refuses the link" => [$type, true, $read, [Permission::read(Role::user(self::USER))], self::STORED, self::SESSION, self::NO_UPDATE];
            yield "{$name}: an API key creates without any permission" => [$type, false, [], [], self::NEW, self::KEY, null];
            yield "{$name}: an API key links without any permission" => [$type, false, [], [], self::STORED, self::KEY, null];
            yield "{$name}: a guest cannot create a nested document" => [$type, false, $create, [], self::NEW, self::GUEST, 'Missing "create" permission for role "users". Only "["any","guests"]" scopes are allowed and "["users"]" was given.'];
        }

        yield 'oneToMany: a guest cannot link a document it cannot read' => [RelationshipType::OneToMany, false, $update, [], self::STORED, self::GUEST, 'Missing "read" permission for role "users". Only "["any","guests"]" scopes are allowed and "["users"]" was given.'];
    }

    /**
     * @param list<string> $collectionPermissions
     * @param list<string> $documentPermissions
     */
    #[DataProvider('cases')]
    public function testNestedRelatedDocuments(RelationshipType $type, bool $documentSecurity, array $collectionPermissions, array $documentPermissions, string $relatedId, string $caller, ?string $refusal): void
    {
        $this->seed($type, $documentSecurity, $collectionPermissions, $documentPermissions);

        $related = $relatedId === self::NEW ? new Document(['$id' => self::NEW, 'name' => 'New']) : self::STORED;
        $create = fn (): int => $this->database->withTransaction(fn (): int => $this->database->createDocuments('parents', [new Document([
            '$id' => 'parent',
            '$permissions' => [Permission::read(Role::any())],
            'related' => $type === RelationshipType::OneToOne ? $related : [$related],
        ])]));

        try {
            $this->as($caller, $create);
        } catch (AuthorizationException $error) {
            $this->assertSame($refusal, $error->getMessage(), 'The create must not be refused');

            $this->assertTrue($this->authorization->skip(fn (): bool => $this->database->getDocument('parents', 'parent')->isEmpty()), 'A refused create writes nothing');

            return;
        }

        $this->assertNull($refusal, 'The create must be refused with: ' . $refusal);
        $this->assertSame([$relatedId], $this->relatedIds(), 'The nested document is related to the new parent');
    }

    /**
     * @param list<string> $collectionPermissions
     * @param list<string> $documentPermissions
     */
    private function seed(RelationshipType $type, bool $documentSecurity, array $collectionPermissions, array $documentPermissions): void
    {
        $this->authorization = new Authorization();
        $this->database = (new Database(new Memory(), new Cache(new None())))
            ->setAuthorization($this->authorization)
            ->setDatabase('nested')
            ->setNamespace('nested_' . \uniqid());
        $this->database->addHook(new Permissions());
        $this->database->addHook(new Relationships());

        $this->authorization->skip(function () use ($type, $documentSecurity, $collectionPermissions, $documentPermissions): void {
            $this->database->create();
            $this->database->createCollection(Collection::create(
                'parents',
                attributes: [Attribute::string('name', size: 64)],
                permissions: [Permission::create(Role::any()), Permission::read(Role::any())],
            ));
            $this->database->createCollection(Collection::create(
                'related',
                attributes: [Attribute::string('name', size: 64)],
                permissions: $collectionPermissions,
                documentSecurity: $documentSecurity,
            ));
            $this->database->createRelationship('parents', match ($type) {
                RelationshipType::OneToMany => Relationship::oneToMany('related', 'related', twoWay: true, twoWayKey: 'parent', onDelete: RelationshipDeleteAction::SetNull),
                RelationshipType::OneToOne => Relationship::oneToOne('related', 'related', twoWay: true, twoWayKey: 'parent', onDelete: RelationshipDeleteAction::SetNull),
                default => Relationship::manyToMany('related', 'related', twoWay: true, twoWayKey: 'parents', onDelete: RelationshipDeleteAction::SetNull),
            });
            $this->database->createDocument('related', new Document(['$id' => self::STORED, 'name' => 'Stored', '$permissions' => $documentPermissions]));
        });
    }

    private function as(string $caller, callable $callback): mixed
    {
        if ($caller === self::KEY) {
            return $this->authorization->skip($callback);
        }

        $roles = $caller === self::GUEST
            ? [Role::any()->toString(), Role::guests()->toString()]
            : [Role::any()->toString(), Role::users()->toString(), Role::user(self::USER)->toString()];

        return $this->authorization->withRoles($roles, $callback);
    }

    /**
     * @return list<string>
     */
    private function relatedIds(): array
    {
        $related = $this->authorization->skip(fn (): Document => $this->database->getDocument('parents', 'parent'))->getAttribute('related');
        $related = $related instanceof Document ? [$related] : $related;

        return \array_values(\array_map(fn (Document $document): string => $document->getId(), $related ?? []));
    }
}
