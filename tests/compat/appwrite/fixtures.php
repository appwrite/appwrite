<?php

/**
 * Fixtures for `bin/compat appwrite diff` that no route creates on a
 * self-hosted instance without outside services (account and organization
 * API keys; custom domain rules, which need DNS verification), written to
 * the platform database the way the E2E scopes seed organizations
 * (tests/e2e/Scopes).
 *
 * Reads JSON on stdin and prints JSON:
 *   {"create": [{"resourceType": "users"|"teams", "resourceId": "...",
 *                "secret": "...", "scopes": [...], "expire": null}]}
 *     -> {"keys": ["<keyId>", ...]}
 *   {"rules": [{"projectId": "...", "domain": "..."}]}
 *     -> {"rules": ["<ruleId>", ...]}
 *   {"prune": true} -> {"pruned": n}: removes every key, console account and
 *     rule a run created (this run's, or an interrupted run's)
 *
 * Run inside the API container:
 *   docker exec -i appwrite php /usr/src/code/tests/compat/appwrite/fixtures.php
 */

require_once __DIR__ . '/../../../app/init.php';

use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

$input = \json_decode((string) \stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$output = [];

\Swoole\Coroutine\run(function () use ($input, &$output) {
    global $register;
    $authorization = new Authorization();
    $database = $register->get('databaseFactory')($register->get('pools'), $register->get('cache'), $authorization)->platform();

    $authorization->skip(function () use ($database, $input, &$output) {
        if (isset($input['create'])) {
            $output['keys'] = [];
            foreach ($input['create'] as $key) {
                $owner = $database->getDocument($key['resourceType'], $key['resourceId']);
                if ($owner->isEmpty()) {
                    throw new \Exception("No {$key['resourceType']} document {$key['resourceId']}");
                }
                $created = $database->createDocument('keys', new Document([
                    '$id' => \class_exists(\Utopia\Database\Id::class) ? \Utopia\Database\Id::unique() : \Utopia\Database\Helpers\ID::unique(),
                    '$permissions' => [],
                    'resourceType' => $key['resourceType'],
                    'resourceId' => $owner->getId(),
                    'resourceInternalId' => $owner->getSequence(),
                    'name' => 'compat-diff',
                    'scopes' => $key['scopes'],
                    'secret' => $key['secret'],
                    'expire' => $key['expire'] ?? null,
                    'sdks' => [],
                    'accessedAt' => null,
                ]));
                $database->purgeCachedDocument($key['resourceType'], $owner->getId());
                $output['keys'][] = $created->getId();
            }
        }
        if (isset($input['rules'])) {
            $output['rules'] = [];
            foreach ($input['rules'] as $rule) {
                $project = $database->getDocument('projects', $rule['projectId']);
                if ($project->isEmpty()) {
                    throw new \Exception("No project {$rule['projectId']}");
                }
                $created = $database->createDocument('rules', new Document([
                    '$id' => \class_exists(\Utopia\Database\Id::class) ? \Utopia\Database\Id::unique() : \Utopia\Database\Helpers\ID::unique(),
                    '$permissions' => [],
                    'projectId' => $project->getId(),
                    'projectInternalId' => $project->getSequence(),
                    'domain' => $rule['domain'],
                    'type' => 'api',
                    'trigger' => 'manual',
                    'status' => 'verified',
                    'owner' => '',
                    'region' => $project->getAttribute('region', 'default'),
                ]));
                $output['rules'][] = $created->getId();
            }
        }
        if (isset($input['prune'])) {
            $pruned = 0;
            foreach ($database->find('keys', [Query::equal('name', ['compat-diff']), Query::limit(APP_LIMIT_SUBQUERY)]) as $key) {
                $database->deleteDocument('keys', $key->getId());
                $database->purgeCachedDocument($key->getAttribute('resourceType'), $key->getAttribute('resourceId'));
                $pruned++;
            }
            foreach ($database->find('users', [Query::startsWith('$id', 'diff'), Query::endsWith('$id', '-account'), Query::limit(APP_LIMIT_SUBQUERY)]) as $user) {
                $database->deleteDocument('users', $user->getId());
                $pruned++;
            }
            foreach ($database->find('rules', [Query::endsWith('domain', '.compat-diff.test'), Query::limit(APP_LIMIT_SUBQUERY)]) as $rule) {
                $database->deleteDocument('rules', $rule->getId());
                $pruned++;
            }
            $output['pruned'] = $pruned;
        }
    });
});

echo \json_encode($output), "\n";
