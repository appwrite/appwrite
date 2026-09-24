<?php

namespace Tests\Query\Builder\Feature\ClickHouse;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\ClickHouse as Builder;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Query;

class DeleteSettingsTest extends TestCase
{
    use AssertsBindingCount;

    public function testDefaultDeleteEmitsLightweightDeleteFrom(): void
    {
        $result = (new Builder())
            ->from('audit_log')
            ->filter([Query::lessThan('time', '2024-01-01 00:00:00')])
            ->delete();

        $this->assertBindingCount($result);
        $this->assertSame(
            'DELETE FROM `audit_log` WHERE `time` < ?',
            $result->query
        );
        $this->assertSame(['2024-01-01 00:00:00'], $result->bindings);
    }

    public function testLightweightDeleteWithAsyncSetting(): void
    {
        $result = (new Builder())
            ->from('audit_log')
            ->settings(['lightweight_deletes_sync' => '0'])
            ->filter([Query::lessThan('time', '2024-01-01 00:00:00')])
            ->delete();

        $this->assertBindingCount($result);
        $this->assertSame(
            'DELETE FROM `audit_log` WHERE `time` < ? SETTINGS lightweight_deletes_sync=0',
            $result->query
        );
        $this->assertSame(['2024-01-01 00:00:00'], $result->bindings);
    }

    public function testMutationDeleteOptIn(): void
    {
        $result = (new Builder())
            ->from('audit_log')
            ->deleteMode(Builder::DELETE_MODE_MUTATION)
            ->filter([Query::lessThan('time', '2024-01-01 00:00:00')])
            ->delete();

        $this->assertBindingCount($result);
        $this->assertSame(
            'ALTER TABLE `audit_log` DELETE WHERE `time` < ?',
            $result->query
        );
        $this->assertSame(['2024-01-01 00:00:00'], $result->bindings);
    }

    public function testMutationDeleteWithAsyncSetting(): void
    {
        $result = (new Builder())
            ->from('audit_log')
            ->deleteMode(Builder::DELETE_MODE_MUTATION)
            ->settings(['mutations_sync' => '0'])
            ->filter([Query::lessThan('time', '2024-01-01 00:00:00')])
            ->delete();

        $this->assertSame(
            'ALTER TABLE `audit_log` DELETE WHERE `time` < ? SETTINGS mutations_sync=0',
            $result->query
        );
    }

    public function testBuilderEmitsSettingsClauseUnchangedForBothModes(): void
    {
        $lightweight = (new Builder())
            ->from('audit_log')
            ->settings([
                'lightweight_deletes_sync' => '0',
                'mutations_sync' => '0',
            ])
            ->filter([Query::lessThan('time', '2024-01-01 00:00:00')])
            ->delete();

        $this->assertSame(
            'DELETE FROM `audit_log` WHERE `time` < ?'
            . ' SETTINGS lightweight_deletes_sync=0, mutations_sync=0',
            $lightweight->query
        );

        $mutation = (new Builder())
            ->from('audit_log')
            ->deleteMode(Builder::DELETE_MODE_MUTATION)
            ->settings([
                'lightweight_deletes_sync' => '0',
                'mutations_sync' => '0',
            ])
            ->filter([Query::lessThan('time', '2024-01-01 00:00:00')])
            ->delete();

        $this->assertSame(
            'ALTER TABLE `audit_log` DELETE WHERE `time` < ?'
            . ' SETTINGS lightweight_deletes_sync=0, mutations_sync=0',
            $mutation->query
        );
    }

    public function testLightweightDeleteWithHint(): void
    {
        $result = (new Builder())
            ->from('audit_log')
            ->hint('lightweight_deletes_sync=0')
            ->filter([Query::lessThan('time', '2024-01-01 00:00:00')])
            ->delete();

        $this->assertSame(
            'DELETE FROM `audit_log` WHERE `time` < ? SETTINGS lightweight_deletes_sync=0',
            $result->query
        );
    }

    public function testLightweightDeleteWithCompoundWhereAndSettings(): void
    {
        $result = (new Builder())
            ->from('audit_log')
            ->settings(['lightweight_deletes_sync' => '0'])
            ->filter([
                Query::lessThan('time', '2024-01-01 00:00:00'),
                Query::equal('tenant', ['acme']),
            ])
            ->delete();

        $this->assertSame(
            'DELETE FROM `audit_log` WHERE `time` < ? AND `tenant` IN (?) SETTINGS lightweight_deletes_sync=0',
            $result->query
        );
        $this->assertSame(['2024-01-01 00:00:00', 'acme'], $result->bindings);
    }

    public function testDeleteModeRejectsUnknownMode(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())->deleteMode('truncate');
    }

    public function testResetRestoresLightweightDefault(): void
    {
        $builder = (new Builder())
            ->from('audit_log')
            ->deleteMode(Builder::DELETE_MODE_MUTATION);

        $builder->reset();

        $result = $builder
            ->from('audit_log')
            ->filter([Query::lessThan('time', '2024-01-01 00:00:00')])
            ->delete();

        $this->assertSame(
            'DELETE FROM `audit_log` WHERE `time` < ?',
            $result->query
        );
    }

    public function testSelectStillEmitsSettingsAfterDeleteFeatureAdded(): void
    {
        $result = (new Builder())
            ->from('events')
            ->settings(['max_threads' => '4'])
            ->build();

        $this->assertSame(
            'SELECT * FROM `events` SETTINGS max_threads=4',
            $result->query
        );
    }
}
