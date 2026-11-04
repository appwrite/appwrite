<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Functions;

use Appwrite\Database\Factory as DatabaseFactory;
use Swoole\Coroutine;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Console\Command;
use Utopia\Console\Console;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\System\System;

use function Swoole\Coroutine\run;

/**
 * Maintenance queues renewals for due certificate rows oldest first, and only for rows the certificates worker can act
 * on. More rows than the per-run limit that it cannot renew are due before a renewable row, and the renewable row is
 * still renewed, so skipped rows do not use up the limit.
 *
 * The worker writes the rule's logs last, so they show the renewable row's job finished. The row of another region's
 * rule would be renewed by this region's worker if maintenance queued it, so its untouched logs show the region gate.
 * The worker leaves rows without a rule, rows of a failed rule and rows their rule does not point to untouched even
 * when queued, so for those only the limit shows that maintenance skipped them.
 *
 * It runs the whole daily maintenance, so it lives in the Functions lane, whose tests already run it alongside each
 * other, and not in a lane with tests that need that maintenance to stay away from their projects.
 */
final class CertificateRenewalTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    private const int ORPHANS = 250; // more than the 200 rows maintenance queues per run

    private const int TIMEOUT = 120_000;

    public function testRenewPastUnrenewableRows(): void
    {
        $projectId = $this->getProject()['$id'];
        $region = System::getEnv('_APP_REGION', 'default');
        $suffix = Id::unique() . '.example.com';

        // Due before any row another test leaves behind, so this run walks these first and the renewable row last
        $blockedAt = '2000-01-01 00:00:00.000';

        $renewable = $this->certificate('renewable.' . $suffix, '2000-01-02 00:00:00.000');
        $renewable->removeAttribute('attempts'); // as the worker leaves a row it created while waiting on its provider
        $superseded = $this->certificate('renewable.' . $suffix, $blockedAt);
        $failed = $this->certificate('failed.' . $suffix, $blockedAt);
        $foreign = $this->certificate('foreign.' . $suffix, $blockedAt);

        $certificates = [$renewable, $superseded, $failed, $foreign];
        for ($i = 0; $i < self::ORPHANS; $i++) {
            $certificates[] = $this->certificate('orphan' . $i . '.' . $suffix, $blockedAt);
        }
        $orphan = $certificates[4];

        $rules = [
            [$renewable, $region, RULE_STATUS_VERIFIED],
            [$failed, $region, RULE_STATUS_CERTIFICATE_GENERATION_FAILED],
            [$foreign, 'elsewhere', RULE_STATUS_VERIFIED],
        ];

        $this->withFactory(function (DatabaseFactory $factory) use ($projectId, $certificates, $rules): void {
            $platform = $factory->platform();
            $project = $platform->getDocument('projects', $projectId);

            $platform->createDocuments('certificates', $certificates);
            $platform->createDocuments('rules', \array_map(fn (array $rule): Document => new Document([
                '$id' => \md5($rule[0]->getAttribute('domain')),
                'projectId' => $project->getId(),
                'projectInternalId' => (string) $project->getSequence(),
                'domain' => $rule[0]->getAttribute('domain'),
                'type' => 'api',
                'trigger' => 'manual',
                'status' => $rule[2],
                'certificateId' => $rule[0]->getId(),
                'owner' => '',
                'region' => $rule[1],
                'search' => $rule[0]->getAttribute('domain'),
                'logs' => '',
            ]), $rules));
        });

        try {
            $stdout = '';
            $stderr = '';
            $code = Console::execute((new Command('docker'))->argument('exec')->argument('appwrite')->argument('maintenance')->argument('--type=trigger'), '', $stdout, $stderr);
            $this->assertSame(0, $code, "Maintenance command failed with code $code: $stderr ($stdout)");
            $this->assertStringContainsString('Certificates for renewal:', $stdout);

            /**
             * Test for SUCCESS
             */
            $this->assertEventually(function () use ($renewable): void {
                $this->assertNotSame('', $this->getDocument('rules', \md5($renewable->getAttribute('domain')))->getAttribute('logs', ''), 'the worker finished the renewal due after more unrenewable rows than the limit');
            }, self::TIMEOUT, 1_000);

            $row = $this->getDocument('certificates', $renewable->getId());
            $this->assertNotSame('', $row->getAttribute('logs', ''), 'the worker renewed the row its rule points to');
            $this->assertNotNull($row->getAttribute('attempts'), 'a row without attempts was selected and renewed');

            /**
             * Test for FAILURE
             */
            $skipped = [
                'superseded' => $superseded,
                'failed' => $failed,
                'region' => $foreign,
                'orphaned' => $orphan,
            ];
            foreach ($skipped as $reason => $certificate) {
                $row = $this->getDocument('certificates', $certificate->getId());
                $this->assertSame('', $row->getAttribute('logs', ''), "the {$reason} row was not renewed");
                $this->assertSame(0, $row->getAttribute('attempts'), "the {$reason} row kept its attempts");
            }

            $this->assertSame(RULE_STATUS_VERIFIED, $this->getDocument('rules', \md5($foreign->getAttribute('domain')))->getAttribute('status'), 'the other region\'s rule was left to its own region');
        } finally {
            $this->withFactory(function (DatabaseFactory $factory) use ($certificates, $rules): void {
                $platform = $factory->platform();
                foreach ($rules as $rule) {
                    $platform->deleteDocument('rules', \md5($rule[0]->getAttribute('domain')));
                }
                foreach (\array_chunk(\array_map(fn (Document $certificate): string => $certificate->getId(), $certificates), 100) as $ids) {
                    $platform->deleteDocuments('certificates', [Query::equal('$id', $ids)]);
                }
            });
        }
    }

    private function certificate(string $domain, string $renewDate): Document
    {
        return new Document([
            '$id' => Id::unique(),
            'domain' => $domain,
            'renewDate' => $renewDate,
            'attempts' => 0,
            'logs' => '',
        ]);
    }

    private function getDocument(string $collection, string $id): Document
    {
        $document = new Document();
        $this->withFactory(function (DatabaseFactory $factory) use ($collection, $id, &$document): void {
            $document = $factory->platform()->getDocument($collection, $id);
        });

        return $document;
    }

    /**
     * @param callable(DatabaseFactory): void $callback receives the server's database factory
     */
    private function withFactory(callable $callback): void
    {
        $read = function () use ($callback): void {
            global $register;
            $authorization = new Authorization();
            $factory = $register->get('databaseFactory')($register->get('pools'), $register->get('cache'), $authorization);

            $authorization->skip(fn () => $callback($factory));
        };

        if (Coroutine::getCid() >= 0) {
            $read();
        } else {
            run($read);
        }
    }
}
