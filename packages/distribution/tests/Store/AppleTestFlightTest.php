<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests\Store;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Exception\Conflict;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Release;
use Utopia\Distribution\State;
use Utopia\Distribution\Tests\Store\Apple\Frozen;
use Utopia\Distribution\Tests\Transport;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class AppleTestFlightTest extends TestCase
{
    private const string KEY_ID = 'ABC123DEFG';

    private const string ISSUER_ID = '57246542-96fe-1a63-e053-0824d011072a';

    private const string BUNDLE_ID = 'io.example.app';

    private const string BUILD_ID = 'b7e1c2d3-0000-4000-8000-000000000101';

    private const string SUBMISSION_ID = 'e8f7a6b5-0000-4000-8000-000000000301';

    private const string GROUP_INTERNAL = '1a2b3c4d-0000-4000-8000-000000000601';

    private const string GROUP_EXTERNAL = '1a2b3c4d-0000-4000-8000-000000000602';

    private const string FIELDS_ENCRYPTION = '?fields%5Bbuilds%5D=usesNonExemptEncryption';

    private const int NOW = 1_795_000_000;

    private const string ENDPOINT = 'https://store.test/apple';

    private const array EXTERNAL = [
        'appId' => '6450000001',
        'bundleId' => self::BUNDLE_ID,
        'version' => '1.2.0',
        'build' => '42',
        'buildId' => self::BUILD_ID,
    ];

    private static string $privateKey = '';

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        self::assertTrue(openssl_pkey_export($key, $private));
        self::assertIsString($private);

        self::$privateKey = $private;
    }

    public function testInternalReleaseDeclaresEncryptionWritesNotesAndAddsGroups(): void
    {
        $transport = new Transport(
            $this->reply('build-undeclared'),
            $this->reply('beta-build'),
            $this->reply('beta-localizations'),
            $this->reply('beta-localization'),
            $this->reply('beta-localization', 201),
            $this->page(204, ''),
        );

        $external = $this->store($transport)->release($this->release(
            Release::TRACK_TESTFLIGHT,
            notes: ['en-US' => 'Bug fixes', 'fr-FR' => 'Corrections de bugs', 'de-DE' => 'Fehlerbehebungen'],
            groups: [self::GROUP_INTERNAL, self::GROUP_EXTERNAL, self::GROUP_INTERNAL],
            encryption: false,
        ), self::EXTERNAL);

        $this->assertSame(self::EXTERNAL + ['track' => Release::TRACK_TESTFLIGHT], $external);

        [, $encryption, $list, $update, $create, $groups] = $this->sequence($transport, [
            ['GET', '/v1/builds/' . self::BUILD_ID . self::FIELDS_ENCRYPTION],
            ['PATCH', '/v1/builds/' . self::BUILD_ID],
            ['GET', '/v1/builds/' . self::BUILD_ID . '/betaBuildLocalizations?limit=200'],
            ['PATCH', '/v1/betaBuildLocalizations/5d4c3b2a-0000-4000-8000-000000000201'],
            ['POST', '/v1/betaBuildLocalizations'],
            ['POST', '/v1/builds/' . self::BUILD_ID . '/relationships/betaGroups'],
        ]);

        $this->assertSame([
            'data' => [
                'type' => 'builds',
                'id' => self::BUILD_ID,
                'attributes' => ['usesNonExemptEncryption' => false],
            ],
        ], $this->body($encryption));
        $this->assertSame('', (string) $list->getBody());
        $this->assertSame([
            'data' => [
                'type' => 'betaBuildLocalizations',
                'id' => '5d4c3b2a-0000-4000-8000-000000000201',
                'attributes' => ['whatsNew' => 'Bug fixes'],
            ],
        ], $this->body($update));
        $this->assertSame([
            'data' => [
                'type' => 'betaBuildLocalizations',
                'attributes' => ['locale' => 'de-DE', 'whatsNew' => 'Fehlerbehebungen'],
                'relationships' => [
                    'build' => ['data' => ['type' => 'builds', 'id' => self::BUILD_ID]],
                ],
            ],
        ], $this->body($create));
        $this->assertSame([
            'data' => [
                ['type' => 'betaGroups', 'id' => self::GROUP_INTERNAL],
                ['type' => 'betaGroups', 'id' => self::GROUP_EXTERNAL],
            ],
        ], $this->body($groups));
    }

    public function testInternalReleaseWithoutOptionsSendsNothing(): void
    {
        $transport = new Transport();

        $external = $this->store($transport)->release($this->release(Release::TRACK_TESTFLIGHT), self::EXTERNAL);

        $this->assertSame(self::EXTERNAL + ['track' => Release::TRACK_TESTFLIGHT], $external);
        $this->assertSame([], $transport->requests());
    }

    public function testEncryptionTrueIsDeclaredAsGiven(): void
    {
        $transport = new Transport($this->reply('build-undeclared'), $this->reply('beta-build'));

        $this->store($transport)->release($this->release(Release::TRACK_TESTFLIGHT, encryption: true), self::EXTERNAL);

        $this->assertSame([
            'data' => [
                'type' => 'builds',
                'id' => self::BUILD_ID,
                'attributes' => ['usesNonExemptEncryption' => true],
            ],
        ], $this->body($this->sequence($transport, [
            ['GET', '/v1/builds/' . self::BUILD_ID . self::FIELDS_ENCRYPTION],
            ['PATCH', '/v1/builds/' . self::BUILD_ID],
        ])[1]));
    }

    public function testBuildThatAlreadyAnsweredIsNotDeclaredAgain(): void
    {
        $transport = new Transport($this->reply('beta-build'));

        $external = $this->store($transport)->release($this->release(Release::TRACK_TESTFLIGHT, encryption: false), self::EXTERNAL);

        $this->assertSame(self::EXTERNAL + ['track' => Release::TRACK_TESTFLIGHT], $external);
        $this->sequence($transport, [['GET', '/v1/builds/' . self::BUILD_ID . self::FIELDS_ENCRYPTION]]);
    }

    public function testNotesForNewBuildAreAllCreated(): void
    {
        $transport = new Transport(
            $this->reply('beta-localizations-empty'),
            $this->reply('beta-localization', 201),
            $this->reply('beta-localization', 201),
        );

        $this->store($transport)->release($this->release(Release::TRACK_TESTFLIGHT, notes: ['en-US' => 'Bug fixes', 'de-DE' => 'Fehlerbehebungen']), self::EXTERNAL);

        [, $english, $german] = $this->sequence($transport, [
            ['GET', '/v1/builds/' . self::BUILD_ID . '/betaBuildLocalizations?limit=200'],
            ['POST', '/v1/betaBuildLocalizations'],
            ['POST', '/v1/betaBuildLocalizations'],
        ]);

        $this->assertSame(['locale' => 'en-US', 'whatsNew' => 'Bug fixes'], $this->attributes($english));
        $this->assertSame(['locale' => 'de-DE', 'whatsNew' => 'Fehlerbehebungen'], $this->attributes($german));
    }

    public function testUnchangedNotesAreNotRewritten(): void
    {
        $transport = new Transport($this->reply('beta-localizations'));

        $this->store($transport)->release($this->release(Release::TRACK_TESTFLIGHT, notes: ['fr-FR' => 'Corrections de bugs']), self::EXTERNAL);

        $this->sequence($transport, [['GET', '/v1/builds/' . self::BUILD_ID . '/betaBuildLocalizations?limit=200']]);
    }

    public function testExternalReleaseAddsGroupsThenSubmitsBetaReview(): void
    {
        $transport = new Transport(
            $this->page(204, ''),
            $this->reply('error-not-found', 404),
            $this->reply('beta-review-submission', 201),
        );

        $external = $this->store($transport)->release($this->release(Release::TRACK_TESTFLIGHT_EXTERNAL, groups: [self::GROUP_EXTERNAL]), self::EXTERNAL);

        $this->assertSame(self::EXTERNAL + [
            'track' => Release::TRACK_TESTFLIGHT_EXTERNAL,
            'betaReviewSubmissionId' => self::SUBMISSION_ID,
        ], $external);

        [, $read, $submit] = $this->sequence($transport, [
            ['POST', '/v1/builds/' . self::BUILD_ID . '/relationships/betaGroups'],
            ['GET', '/v1/builds/' . self::BUILD_ID . '/betaAppReviewSubmission'],
            ['POST', '/v1/betaAppReviewSubmissions'],
        ]);

        $this->assertSame('', (string) $read->getBody());
        $this->assertSame([
            'data' => [
                'type' => 'betaAppReviewSubmissions',
                'relationships' => [
                    'build' => ['data' => ['type' => 'builds', 'id' => self::BUILD_ID]],
                ],
            ],
        ], $this->body($submit));
    }

    public function testExternalReleaseSubmitsWhenSubmissionIsEmpty(): void
    {
        $transport = new Transport($this->reply('beta-review-submission-none'), $this->reply('beta-review-submission', 201));

        $external = $this->store($transport)->release($this->release(Release::TRACK_TESTFLIGHT_EXTERNAL), self::EXTERNAL);

        $this->assertSame(self::SUBMISSION_ID, $external['betaReviewSubmissionId'] ?? null);
        $this->sequence($transport, [
            ['GET', '/v1/builds/' . self::BUILD_ID . '/betaAppReviewSubmission'],
            ['POST', '/v1/betaAppReviewSubmissions'],
        ]);
    }

    public function testExternalReleaseReusesExistingSubmission(): void
    {
        $transport = new Transport($this->reply('beta-review-submission'));

        $external = $this->store($transport)->release($this->release(Release::TRACK_TESTFLIGHT_EXTERNAL), self::EXTERNAL);

        $this->assertSame(self::SUBMISSION_ID, $external['betaReviewSubmissionId'] ?? null);
        $this->sequence($transport, [['GET', '/v1/builds/' . self::BUILD_ID . '/betaAppReviewSubmission']]);
    }

    public function testConflictingSubmissionCountsAsAlreadySubmitted(): void
    {
        $transport = new Transport(
            $this->reply('error-not-found', 404),
            $this->reply('beta-error-submitted', 409),
            $this->reply('beta-review-submission'),
        );

        $external = $this->store($transport)->release($this->release(Release::TRACK_TESTFLIGHT_EXTERNAL), self::EXTERNAL);

        $this->assertSame(self::SUBMISSION_ID, $external['betaReviewSubmissionId'] ?? null);
        $this->sequence($transport, [
            ['GET', '/v1/builds/' . self::BUILD_ID . '/betaAppReviewSubmission'],
            ['POST', '/v1/betaAppReviewSubmissions'],
            ['GET', '/v1/builds/' . self::BUILD_ID . '/betaAppReviewSubmission'],
        ]);
    }

    public function testConflictingSubmissionWithoutSubmissionIsRethrown(): void
    {
        $transport = new Transport(
            $this->reply('error-not-found', 404),
            $this->reply('beta-error-submitted', 409),
            $this->reply('error-not-found', 404),
        );

        try {
            $this->store($transport)->release($this->release(Release::TRACK_TESTFLIGHT_EXTERNAL), self::EXTERNAL);
            $this->fail('release should throw');
        } catch (Conflict $conflict) {
            $this->assertSame('STATE_ERROR', $conflict->getIssues()[0]->code);
        }

        $this->assertCount(3, $transport->requests());
    }

    public function testRejectedSubmissionKeepsTheIssue(): void
    {
        $transport = new Transport(
            $this->reply('beta-review-submission-none'),
            $this->reply('beta-error-compliance', 409),
            $this->reply('beta-review-submission-none'),
        );

        try {
            $this->store($transport)->release($this->release(Release::TRACK_TESTFLIGHT_EXTERNAL), self::EXTERNAL);
            $this->fail('release should throw');
        } catch (Invalid $invalid) {
            $this->assertSame('ENTITY_ERROR.RELATIONSHIP.INVALID', $invalid->getIssues()[0]->code);
            $this->assertSame('/data/relationships/build', $invalid->getIssues()[0]->field);
        }
    }

    public function testInternalOnlyBuildInExternalGroupThrowsInvalid(): void
    {
        $transport = new Transport($this->reply('beta-error-group', 409));

        try {
            $this->store($transport)->release($this->release(Release::TRACK_TESTFLIGHT_EXTERNAL, groups: [self::GROUP_EXTERNAL]), self::EXTERNAL);
            $this->fail('release should throw');
        } catch (Invalid $invalid) {
            $this->assertSame('ENTITY_ERROR.RELATIONSHIP.INVALID', $invalid->getIssues()[0]->code);
        }

        $this->assertCount(1, $transport->requests());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unprocessed(): iterable
    {
        yield 'no build id' => [['appId' => '6450000001']];
        yield 'empty build id' => [['buildId' => ' ']];
        yield 'numeric build id' => [['buildId' => 42]];
    }

    /**
     * @param array<string, mixed> $external
     */
    #[DataProvider('unprocessed')]
    public function testReleaseWithoutBuildIdThrowsInvalidBeforeSending(array $external): void
    {
        $transport = new Transport();

        try {
            $this->store($transport)->release($this->release(Release::TRACK_TESTFLIGHT, encryption: false), $external);
            $this->fail('release should throw');
        } catch (Invalid) {
            $this->assertSame([], $transport->requests());
        }
    }

    /**
     * @param array<string, mixed> $external
     */
    #[DataProvider('unprocessed')]
    public function testStateWithoutBuildIdThrowsInvalidBeforeSending(array $external): void
    {
        $transport = new Transport();

        try {
            $this->store($transport)->state($external, Release::TRACK_TESTFLIGHT);
            $this->fail('state should throw');
        } catch (Invalid) {
            $this->assertSame([], $transport->requests());
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string, ?string, bool}>
     */
    public static function states(): iterable
    {
        $rows = [
            'PROCESSING' => [State::STATUS_PROCESSING, null, false],
            'PROCESSING_EXCEPTION' => [State::STATUS_REJECTED, 'PROCESSING_EXCEPTION', false],
            'MISSING_EXPORT_COMPLIANCE' => [State::STATUS_PENDING, 'MISSING_EXPORT_COMPLIANCE', true],
            'IN_EXPORT_COMPLIANCE_REVIEW' => [State::STATUS_PENDING, 'IN_EXPORT_COMPLIANCE_REVIEW', true],
            'READY_FOR_BETA_TESTING' => [State::STATUS_APPROVED, null, false],
            'IN_BETA_TESTING' => [State::STATUS_PUBLISHED, null, false],
            'EXPIRED' => [State::STATUS_REJECTED, 'EXPIRED', false],
        ];

        foreach ($rows as $value => [$status, $issue, $manual]) {
            yield "internal {$value}" => [Release::TRACK_TESTFLIGHT, $value, 'READY_FOR_BETA_SUBMISSION', $status, $issue, $manual];
            yield "external {$value}" => [Release::TRACK_TESTFLIGHT_EXTERNAL, 'IN_BETA_TESTING', $value, $status, $issue, $manual];
        }

        $external = [
            'READY_FOR_BETA_SUBMISSION' => [State::STATUS_PENDING, null, false],
            'WAITING_FOR_BETA_REVIEW' => [State::STATUS_PENDING, null, false],
            'IN_BETA_REVIEW' => [State::STATUS_REVIEWING, null, false],
            'BETA_APPROVED' => [State::STATUS_APPROVED, null, false],
            'BETA_REJECTED' => [State::STATUS_REJECTED, 'BETA_REJECTED', false],
        ];

        foreach ($external as $value => [$status, $issue, $manual]) {
            yield "external {$value}" => [Release::TRACK_TESTFLIGHT_EXTERNAL, 'IN_BETA_TESTING', $value, $status, $issue, $manual];
        }
    }

    #[DataProvider('states')]
    public function testStateMapsBuildBetaDetail(string $track, string $internal, string $external, string $status, ?string $issue, bool $manual): void
    {
        $transport = new Transport($this->detail($internal, $external));

        $state = $this->store($transport)->state(self::EXTERNAL, $track);

        $this->sequence($transport, [['GET', '/v1/builds/' . self::BUILD_ID . '/buildBetaDetail']]);
        $this->assertSame($status, $state->status);
        $this->assertSame($track === Release::TRACK_TESTFLIGHT ? $internal : $external, $state->raw);
        $this->assertSame(1.0, $state->fraction);

        if ($issue === null) {
            $this->assertSame([], $state->issues);

            return;
        }

        $this->assertCount(1, $state->issues);
        $this->assertSame($issue, $state->issues[0]->code);
        $this->assertSame($manual, $state->issues[0]->manual);
    }

    public function testTerminalStatesExplainTheFailure(): void
    {
        $processing = $this->store(new Transport($this->detail('PROCESSING_EXCEPTION', 'PROCESSING_EXCEPTION')))->state(self::EXTERNAL, Release::TRACK_TESTFLIGHT);
        $expired = $this->store(new Transport($this->detail('EXPIRED', 'EXPIRED')))->state(self::EXTERNAL, Release::TRACK_TESTFLIGHT_EXTERNAL);

        $this->assertSame('TestFlight could not process the build', $processing->issues[0]->message);
        $this->assertSame('The TestFlight build expired', $expired->issues[0]->message);
    }

    public function testNotApplicableExternalStateFallsBackToInternalState(): void
    {
        $state = $this->store(new Transport($this->detail('IN_BETA_TESTING', 'NOT_APPLICABLE')))->state(self::EXTERNAL, Release::TRACK_TESTFLIGHT_EXTERNAL);

        $this->assertSame(State::STATUS_PUBLISHED, $state->status);
        $this->assertSame('IN_BETA_TESTING', $state->raw);
    }

    public function testFixtureDetailIsPendingOnExternalTrack(): void
    {
        $state = $this->store(new Transport($this->reply('beta-detail')))->state(self::EXTERNAL, Release::TRACK_TESTFLIGHT_EXTERNAL);

        $this->assertSame(State::STATUS_PENDING, $state->status);
        $this->assertSame('READY_FOR_BETA_SUBMISSION', $state->raw);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function unknownStates(): iterable
    {
        yield 'unknown internal' => [Release::TRACK_TESTFLIGHT, 'QUANTUM', 'READY_FOR_BETA_SUBMISSION'];
        yield 'unknown external' => [Release::TRACK_TESTFLIGHT_EXTERNAL, 'READY_FOR_BETA_TESTING', 'QUANTUM'];
        yield 'internal state on internal track' => [Release::TRACK_TESTFLIGHT, 'NOT_APPLICABLE', 'NOT_APPLICABLE'];
    }

    #[DataProvider('unknownStates')]
    public function testUnknownStateThrowsUnavailable(string $track, string $internal, string $external): void
    {
        $this->expectException(Unavailable::class);

        $this->store(new Transport($this->detail($internal, $external)))->state(self::EXTERNAL, $track);
    }

    public function testDetailWithoutDataThrowsUnavailable(): void
    {
        $this->expectException(Unavailable::class);

        $this->store(new Transport($this->page(200, '{"data":null}')))->state(self::EXTERNAL, Release::TRACK_TESTFLIGHT);
    }

    public function testWithdrawFromTestFlightThrowsInvalid(): void
    {
        $transport = new Transport();

        try {
            $this->store($transport)->withdraw(self::EXTERNAL + ['track' => Release::TRACK_TESTFLIGHT_EXTERNAL, 'betaReviewSubmissionId' => self::SUBMISSION_ID]);
            $this->fail('withdraw should throw');
        } catch (Invalid $invalid) {
            $this->assertSame('A beta review cannot be withdrawn', $invalid->getMessage());
        }

        $this->assertSame([], $transport->requests());
    }

    /**
     * @param array<string, string> $notes
     * @param list<string> $groups
     */
    private function release(string $track, array $notes = [], array $groups = [], ?bool $encryption = null): Release
    {
        return new Release(self::BUNDLE_ID, $track, '1.2.0', '42', notes: $notes, groups: $groups, encryption: $encryption);
    }

    private function detail(string $internal, string $external): ResponseInterface
    {
        $fixture = json_decode((string) $this->reply('beta-detail')->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($fixture);
        $this->assertIsArray($fixture['data']);
        $this->assertIsArray($fixture['data']['attributes']);

        $fixture['data']['attributes']['internalBuildState'] = $internal;
        $fixture['data']['attributes']['externalBuildState'] = $external;

        return $this->page(200, json_encode($fixture, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<array{string, string}> $expected
     *
     * @return list<RequestInterface>
     */
    private function sequence(Transport $transport, array $expected): array
    {
        $requests = $transport->requests();

        $this->assertSame(
            array_map(static fn (array $call): string => $call[0] . ' ' . self::ENDPOINT . $call[1], $expected),
            array_map(static fn (RequestInterface $request): string => $request->getMethod() . ' ' . $request->getUri(), $requests),
        );

        foreach ($requests as $request) {
            $this->assertStringStartsWith('Bearer ', $request->getHeaderLine('Authorization'));
        }

        return $requests;
    }

    /**
     * @return array<mixed>
     */
    private function attributes(RequestInterface $request): array
    {
        $data = $this->body($request)['data'] ?? null;
        $this->assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        $this->assertIsArray($attributes);

        return $attributes;
    }

    private function store(Transport $transport): Frozen
    {
        return new Frozen(new Credential\Apple(self::KEY_ID, self::ISSUER_ID, self::$privateKey), $transport, self::NOW, self::ENDPOINT);
    }

    private function reply(string $fixture, int $status = 200): ResponseInterface
    {
        return Transport::fixture("Apple/{$fixture}", $status, ['X-Rate-Limit' => 'user-hour-lim:3500;user-hour-rem:3499;']);
    }

    private function page(int $status, string $body): ResponseInterface
    {
        return new Response($status, body: new Stream\Factory()->createStream($body));
    }

    /**
     * @return array<mixed>
     */
    private function body(RequestInterface $request): array
    {
        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);

        return $body;
    }
}
