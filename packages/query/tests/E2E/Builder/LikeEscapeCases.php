<?php

namespace Utopia\Query\Tests\E2E\Builder;

use Closure;
use PDO;
use Utopia\Query\Query;

trait LikeEscapeCases
{
    private const array PATTERN_VALUES = ['50%', '500', 'a_c', 'abc', 'wow!', 'wow', 'x!_y', 'x!zy', 'C:\\dir', 'C:dir'];

    abstract protected static function jsonSearchMatchesNumbers(): bool;

    /**
     * @return iterable<string, array{bool, Query, list<string>}>
     */
    public static function likePatterns(): iterable
    {
        $cases = [
            'contains percent' => [Query::containsString('value', ['50%']), ['50%']],
            'starts with underscore' => [Query::startsWith('value', 'a_'), ['a_c']],
            'ends with escape' => [Query::endsWith('value', 'w!'), ['wow!']],
            'contains escape and underscore' => [Query::containsString('value', ['!_']), ['x!_y']],
            'contains backslash' => [Query::containsString('value', ['\\']), ['C:\\dir']],
            'starts with backslash' => [Query::startsWith('value', 'C:\\'), ['C:\\dir']],
            'not contains percent' => [Query::notContains('value', ['%']), ['500', 'a_c', 'abc', 'wow!', 'wow', 'x!_y', 'x!zy', 'C:\\dir', 'C:dir']],
        ];

        foreach (['default' => false, 'no backslash escapes' => true] as $mode => $noBackslashEscapes) {
            foreach ($cases as $name => [$query, $expected]) {
                yield $mode . ' ' . $name => [$noBackslashEscapes, $query, $expected];
            }
        }
    }

    /**
     * @return iterable<string, array{bool, string, mixed, list<mixed>}>
     */
    public static function jsonRemovals(): iterable
    {
        $cases = [
            'percent' => ['["500", "50%"]', '50%', ['500']],
            'underscore' => ['["axb", "a_b"]', 'a_b', ['axb']],
            'escape' => ['["wow", "wow!"]', 'wow!', ['wow']],
            'backslash' => ['["axb", "a\\\\b"]', 'a\\b', ['axb']],
            'absent' => ['["a", "b"]', 'c', ['a', 'b']],
            'absent wildcard' => ['["a", "b"]', '%', ['a', 'b']],
            'integer' => ['[5, "a"]', 5, static::jsonSearchMatchesNumbers() ? ['a'] : [5, 'a']],
        ];

        foreach (['default' => false, 'no backslash escapes' => true] as $mode => $noBackslashEscapes) {
            foreach ($cases as $name => [$tags, $value, $expected]) {
                yield $mode . ' ' . $name => [$noBackslashEscapes, $tags, $value, $expected];
            }
        }
    }

    private function seedPatterns(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS `patterns`');
        $pdo->exec('CREATE TABLE `patterns` (`id` INT AUTO_INCREMENT PRIMARY KEY, `value` VARCHAR(100) NOT NULL) ENGINE=InnoDB');

        $insert = $pdo->prepare('INSERT INTO `patterns` (`value`) VALUES (?)');
        foreach (self::PATTERN_VALUES as $value) {
            $insert->execute([$value]);
        }
    }

    private function seedTags(PDO $pdo, string $tags): void
    {
        $pdo->exec('DROP TABLE IF EXISTS `tagged`');
        $pdo->exec('CREATE TABLE `tagged` (`id` INT AUTO_INCREMENT PRIMARY KEY, `tags` JSON NOT NULL) ENGINE=InnoDB');
        $pdo->prepare('INSERT INTO `tagged` (`tags`) VALUES (?)')->execute([$tags]);
    }

    /**
     * @return list<mixed>
     */
    private function storedTags(PDO $pdo): array
    {
        $statement = $pdo->prepare('SELECT `tags` FROM `tagged` WHERE `id` = 1');
        $statement->execute();
        $tags = $statement->fetchColumn();
        $this->assertIsString($tags);

        /** @var list<mixed> */
        return \json_decode($tags, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @template T
     * @param  Closure(): T  $run
     * @return T
     */
    private function inSqlMode(PDO $pdo, bool $noBackslashEscapes, Closure $run): mixed
    {
        if (!$noBackslashEscapes) {
            return $run();
        }

        $statement = $pdo->prepare('SELECT @@SESSION.sql_mode');
        $statement->execute();
        $original = $statement->fetchColumn();
        $this->assertIsString($original);
        $pdo->exec("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'NO_BACKSLASH_ESCAPES')");

        try {
            return $run();
        } finally {
            $pdo->prepare('SET SESSION sql_mode = ?')->execute([$original]);
        }
    }
}
