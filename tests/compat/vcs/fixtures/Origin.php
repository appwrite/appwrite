<?php

namespace Tests\Compat\Vcs\Fixtures;

/**
 * Origin's partner API (`api.cursor.com/v1/origin`): proto3 JSON (64-bit
 * integers as strings, camelCase fields), page tokens instead of totals,
 * check runs upserted by key.
 */
final class Origin extends Provider
{
    protected function notFound(): Response
    {
        return $this->error('NOT_FOUND', 'resource not found', 404);
    }

    protected function unauthorized(): Response
    {
        return $this->error('UNAUTHENTICATED', 'invalid credentials', 401);
    }

    private function error(string $code, string $message, int $status): Response
    {
        return Response::json(['code' => $code, 'message' => $message], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function repositoryOf(Repository $repository): array
    {
        return [
            'id' => 'repo_' . $repository->id,
            'name' => $repository->name,
            'owner' => ['slug' => $repository->owner, 'id' => 'ns_' . $this->world->user($repository->owner)['id']],
            'defaultBranch' => $repository->defaultBranch,
            'visibility' => $repository->private ? 'PRIVATE' : 'INTERNAL',
            'createdAt' => World::TIME,
            'updatedAt' => World::TIME,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commitOf(Repository $repository, string $sha): array
    {
        $info = Git::info($repository->path, $sha) ?? ['sha' => $sha, 'tree' => '', 'parents' => [], 'author' => '', 'email' => '', 'message' => ''];
        $person = ['name' => $info['author'], 'email' => $info['email'], 'date' => World::TIME];

        return [
            'sha' => $info['sha'],
            'commit' => ['author' => $person, 'committer' => $person, 'message' => $info['message'], 'tree' => ['sha' => $info['tree']]],
            'parents' => array_map(static fn (string $parent): array => ['sha' => $parent], $info['parents']),
        ];
    }

    /**
     * @param array<string, mixed> $pull
     * @return array<string, mixed>
     */
    private function pullOf(Repository $repository, array $pull): array
    {
        return [
            'id' => 'pr_' . $pull['id'],
            'number' => (string) $pull['number'],
            'state' => $pull['state'],
            'draft' => (bool) ($pull['draft'] ?? false),
            'merged' => $pull['state'] === 'merged',
            'title' => $pull['title'],
            'body' => $pull['body'],
            'head' => ['ref' => 'refs/heads/' . $pull['head'], 'sha' => (string) ($repository->resolve((string) $pull['head']) ?? '')],
            'base' => ['ref' => 'refs/heads/' . $pull['base'], 'sha' => (string) ($repository->resolve((string) $pull['base']) ?? '')],
            'author' => ['user' => ['id' => 'user_1', 'email' => $this->world->config['user'] . '@example.com']],
            'createdAt' => World::TIME,
        ];
    }

    /**
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    private function runOf(Repository $repository, array $run): array
    {
        $suite = $repository->checkSuites[$run['suite']] ?? [];

        return array_filter([
            'id' => $run['id'],
            'key' => $run['key'],
            'name' => $run['name'],
            'sha' => $run['sha'],
            'status' => $run['status'] ?? 'queued',
            'conclusion' => $run['conclusion'] ?? null,
            'externalId' => $run['externalId'] ?? '',
            'externalUpdatedAt' => $run['externalUpdatedAt'] ?? null,
            'startedAt' => $run['startedAt'] ?? null,
            'completedAt' => $run['completedAt'] ?? null,
            'detailsUrl' => $run['detailsUrl'] ?? null,
            'output' => $run['output'] ?? null,
            'checkSuite' => ['id' => $suite['id'] ?? '', 'key' => $suite['key'] ?? '', 'name' => $suite['name'] ?? ''],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * A page of $items under $field, with a token for the next one.
     *
     * @param array<mixed> $items
     */
    private function paged(array $items, string $field): Response
    {
        $size = max(1, (int) $this->request->input('pageSize', 50));
        $token = (string) $this->request->input('pageToken', '');
        $offset = str_starts_with($token, 'offset-') ? (int) substr($token, 7) : 0;
        $out = [$field => \array_slice(array_values($items), $offset, $size)];
        $out['nextPageToken'] = $offset + $size < \count($items) ? 'offset-' . ($offset + $size) : '';

        return Response::json($out);
    }

    private function find(string $owner, string $name): ?Repository
    {
        return $this->world->repository($owner, $name);
    }

    /**
     * Upserts the runs of one suite: the suite keyed on (sha, key), each run
     * on (suite, key).
     *
     * @param array<string, mixed> $suite
     * @param array<array<string, mixed>> $runs
     * @return array<array<string, mixed>>
     */
    private function upsert(Repository $repository, string $sha, array $suite, array $runs): array
    {
        $suiteId = null;
        foreach ($repository->checkSuites as $id => $existing) {
            if ($existing['sha'] === $sha && $existing['key'] === ($suite['key'] ?? '')) {
                $suiteId = $id;
            }
        }
        if ($suiteId === null) {
            $suiteId = 'cs_' . $this->world->id();
            $repository->checkSuites[$suiteId] = ['id' => $suiteId, 'sha' => $sha, 'key' => $suite['key'] ?? '', 'name' => $suite['name'] ?? '', 'externalId' => $suite['externalId'] ?? '', 'detailsUrl' => $suite['detailsUrl'] ?? ''];
        }
        $written = [];
        foreach ($runs as $run) {
            $runId = null;
            foreach ($repository->checkRuns as $id => $existing) {
                if ($existing['suite'] === $suiteId && $existing['key'] === ($run['key'] ?? '')) {
                    $runId = $id;
                }
            }
            $runId ??= 'cr_' . $this->world->id();
            $repository->checkRuns[$runId] = ['id' => $runId, 'suite' => $suiteId, 'sha' => $sha] + $run + ($repository->checkRuns[$runId] ?? []);
            $written[] = $this->runOf($repository, $repository->checkRuns[$runId]);
        }

        return $written;
    }

    protected function routes(): void
    {
        $this->on('POST', '/app/installations/{id}/access_tokens', function (array $p): Response {
            if (!Jwt::is($this->request->token())) {
                return $this->unauthorized();
            }

            return Response::json(['token' => 'oit_fixture_' . $p['id'], 'expiresAt' => World::TIME]);
        });

        $this->on('GET', '/keys', fn (): Response => Response::json(['keys' => $this->world->config['jwks'] ?? []]));

        $this->on('GET', '/app', fn (): Response => Response::json(['id' => 'app_fixture', 'slug' => 'fixture-app', 'name' => 'Fixture App', 'webhookUrl' => 'https://example.com/hooks', 'events' => ['repository.pushed']]));

        $this->on('GET', '/app/installations', fn (): Response => $this->paged([$this->installationOf('i_fixture')], 'installations'));

        $this->on('GET', '/app/installations/{id}', function (array $p): Response {
            if (!Jwt::is($this->request->token())) {
                return $this->unauthorized();
            }
            $missing = $this->world->config['missing'] ?? [];

            return \in_array($p['id'], \is_array($missing) ? $missing : [], true) ? $this->notFound() : Response::json($this->installationOf($p['id']));
        });

        $this->on('DELETE', '/app/installations/{id}', fn (): Response => Response::object());

        $this->on('GET', '/app/webhook/deliveries', function (): Response {
            $deliveries = [
                ['id' => 'whd_1', 'eventType' => 'repository.pushed', 'installationId' => 'i_fixture', 'delivered' => true, 'statusCode' => 200],
                ['id' => 'whd_2', 'eventType' => 'pull_request.created', 'installationId' => 'i_fixture', 'delivered' => false, 'statusCode' => 500],
            ];
            $delivered = $this->request->input('delivered');
            $deliveries = array_values(array_filter($deliveries, static fn (array $d): bool => $delivered === null || ($delivered === 'true') === $d['delivered']));

            return $this->paged($deliveries, 'deliveries');
        });

        $this->on('POST', '/app/webhook/deliveries:batchRedeliver', function (): Response {
            $results = [];
            foreach (array_unique((array) ($this->request->json()['deliveryIds'] ?? [])) as $id) {
                $results[] = ['deliveryId' => $id, 'outcome' => \in_array($id, ['whd_1', 'whd_2'], true) ? 'queued' : 'not_found'];
            }

            return Response::json(['results' => $results]);
        });

        $this->on('GET', '/rate_limit', fn (): Response => Response::json(['resources' => ['core' => ['limit' => '5000', 'remaining' => '4999', 'reset' => '1767225600', 'used' => '1']]]));

        $this->on('GET', '/installation/repos', fn (): Response => $this->paged(array_map($this->repositoryOf(...), $this->world->repositories), 'repositories'));

        $this->on('GET', '/repos/{owner}', function (array $p): Response {
            if (!empty($this->world->config['deny_owner_listing'])) {
                return $this->error('PERMISSION_DENIED', 'listing an owner\'s repositories is not allowed for app installations', 403);
            }
            $filter = (string) $this->request->input('filter', '');
            $items = [];
            foreach ($this->world->repositories as $repository) {
                if (strcasecmp($repository->owner, $p['owner']) === 0 && ($filter === '' || stripos($repository->name, $filter) !== false)) {
                    $items[] = $this->repositoryOf($repository);
                }
            }

            return $this->paged($items, 'repositories');
        });

        $this->on('GET', '/repos/{owner}/{repo}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);

            return $repository === null ? $this->notFound() : Response::json($this->repositoryOf($repository));
        });

        $this->on('POST', '/repos/{owner}/{repo}:syncMirror', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            if (empty($this->world->config['mirror'])) {
                return $this->error('FAILED_PRECONDITION', 'repository is not a mirror', 400);
            }

            return $this->request->json()['wait'] ?? false ? Response::object(200) : Response::object(202);
        });

        $this->on('GET', '/repos/{owner}/{repo}/branches', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            if ($repository->empty()) {
                return $this->error('FAILED_PRECONDITION', 'repository has no commits', 409);
            }
            $branches = [];
            foreach (Git::branches($repository->path) as $name => $sha) {
                $branches[] = ['name' => $name, 'commit' => ['sha' => $sha]];
            }

            return $this->paged($branches, 'branches');
        });

        $this->on('GET', '/repos/{owner}/{repo}/git/matching-refs', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            if ($repository->empty()) {
                return $this->error('FAILED_PRECONDITION', 'repository has no commits', 409);
            }
            $prefix = (string) $this->request->input('ref', '');
            $refs = [];
            foreach (Git::tags($repository->path) as $name => $sha) {
                if (str_starts_with('tags/' . $name, $prefix)) {
                    $refs[] = ['ref' => 'refs/tags/' . $name, 'object' => ['sha' => $sha, 'type' => 'commit']];
                }
            }
            foreach (Git::branches($repository->path) as $name => $sha) {
                if (str_starts_with('heads/' . $name, $prefix)) {
                    $refs[] = ['ref' => 'refs/heads/' . $name, 'object' => ['sha' => $sha, 'type' => 'commit']];
                }
            }

            return Response::json($refs);
        });

        $this->on('GET', '/repos/{owner}/{repo}/git/ref/heads/{name*}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $sha = $repository !== null ? (Git::branches($repository->path)[$p['name']] ?? null) : null;

            return $sha === null ? $this->notFound() : Response::json(['ref' => 'refs/heads/' . $p['name'], 'object' => ['sha' => $sha, 'type' => 'commit']]);
        });

        $this->on('GET', '/repos/{owner}/{repo}/git/trees/{sha}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $sha = $repository !== null ? Git::resolve($repository->path, $p['sha']) : null;
            if ($repository === null || $sha === null) {
                return $this->notFound();
            }
            $tree = array_map(static fn (array $e): array => ['path' => $e['path'], 'mode' => $e['mode'], 'type' => $e['type'], 'sha' => $e['sha'], 'size' => (string) $e['size']], Git::tree($repository->path, $sha, '', (string) $this->request->input('recursive', '') !== '') ?? []);

            return Response::json(['sha' => $sha, 'tree' => $tree, 'truncated' => false]);
        });

        $this->on('GET', '/repos/{owner}/{repo}/git/blobs/{sha}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null || trim((string) Git::out($repository->path, ['cat-file', '-t', $p['sha']])) !== 'blob') {
                return $this->notFound();
            }
            $content = (string) Git::out($repository->path, ['cat-file', 'blob', $p['sha']]);

            return Response::json(['sha' => $p['sha'], 'size' => (string) \strlen($content), 'encoding' => 'base64', 'content' => chunk_split(base64_encode($content), 76, "\n")]);
        });

        $this->on('GET', '/repos/{owner}/{repo}/contents', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $sha = $repository->resolve((string) $this->request->input('ref', $repository->defaultBranch));
            if ($sha === null) {
                return $this->notFound();
            }
            $item = $this->contentAt($repository, $sha, trim((string) $this->request->input('path', ''), '/'));

            return $item === null ? $this->notFound() : Response::json($item);
        });

        $this->on('POST', '/repos/{owner}/{repo}/contents:batchGet', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $body = $this->request->json();
            $sha = $repository->resolve((string) ($body['ref'] ?? $repository->defaultBranch));
            $results = [];
            foreach ((array) ($body['paths'] ?? []) as $path) {
                $item = $sha === null ? null : $this->contentAt($repository, $sha, trim((string) $path, '/'));
                $results[] = $item === null ? ['path' => $path, 'found' => false] : ['path' => $path, 'found' => true, 'content' => $item];
            }

            return Response::json(['results' => $results]);
        });

        $this->on('GET', '/repos/{owner}/{repo}/commits', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $sha = $repository?->resolve((string) $this->request->input('sha', $repository->defaultBranch));
            if ($repository === null || $sha === null) {
                return $this->notFound();
            }

            return $this->paged(array_map(fn (string $s): array => $this->commitOf($repository, $s), Git::log($repository->path, $sha)), 'commits');
        });

        $this->on('GET', '/repos/{owner}/{repo}/commits/{ref}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $sha = $repository?->resolve($p['ref']);

            return $repository === null || $sha === null ? $this->notFound() : Response::json($this->commitOf($repository, $sha));
        });

        $this->on('GET', '/repos/{owner}/{repo}/commits/{sha}/files', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $sha = $repository?->resolve($p['sha']);
            if ($repository === null || $sha === null) {
                return $this->notFound();
            }
            $touched = Git::touched($repository->path, $sha);
            $files = [];
            foreach (['added', 'modified', 'removed'] as $status) {
                foreach ($touched[$status] as $file) {
                    $files[] = ['filename' => $file, 'status' => $status, 'patch' => ''];
                }
            }

            return $this->paged($files, 'files');
        });

        $this->on('GET', '/repos/{owner}/{repo}/commits/{sha}/check-runs', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $runs = array_filter($repository->checkRuns, static fn (array $r): bool => $r['sha'] === $p['sha']);

            return $this->paged(array_map(fn (array $r): array => $this->runOf($repository, $r), $runs), 'checkRuns');
        });

        $this->on('GET', '/repos/{owner}/{repo}/compare/{range}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            [$base, $head] = explode('...', $p['range'], 2) + ['', ''];
            $baseSha = $repository?->resolve($base);
            $headSha = $repository?->resolve($head);
            if ($repository === null || $baseSha === null || $headSha === null) {
                return $this->notFound();
            }
            $mergeBase = trim((string) Git::out($repository->path, ['merge-base', $baseSha, $headSha]));
            $ahead = \count(Git::log($repository->path, $mergeBase . '..' . $headSha));
            $behind = \count(Git::log($repository->path, $mergeBase . '..' . $baseSha));
            $status = match (true) {
                $ahead === 0 && $behind === 0 => 'identical',
                $behind === 0 => 'ahead',
                $ahead === 0 => 'behind',
                default => 'diverged',
            };

            return Response::json(['status' => $status, 'aheadBy' => (string) $ahead, 'behindBy' => (string) $behind, 'baseCommit' => ['sha' => $baseSha], 'headCommit' => ['sha' => $headSha], 'mergeBaseCommit' => ['sha' => $mergeBase]]);
        });

        $this->pullRoutes();
        $this->checkRoutes();
    }

    private function pullRoutes(): void
    {
        $this->on('GET', '/repos/{owner}/{repo}/pulls', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $state = (string) $this->request->input('state', 'open');
            $head = (string) $this->request->input('head', '');
            $pulls = array_filter($repository->pulls, static fn (array $pull): bool => ($state === 'all' || $pull['state'] === $state || ($state === 'closed' && $pull['state'] === 'merged')) && ($head === '' || $pull['head'] === $head));

            return $this->paged(array_map(fn (array $pull): array => $this->pullOf($repository, $pull), array_reverse($pulls)), 'pullRequests');
        });

        $this->on('POST', '/repos/{owner}/{repo}/pulls', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $body = $this->request->json();
            if ($repository->resolve((string) ($body['head'] ?? '')) === null) {
                return $this->error('INVALID_ARGUMENT', 'head branch does not exist', 400);
            }
            $number = $repository->nextPull++;
            $repository->pulls[$number] = ['id' => $this->world->id(), 'number' => $number, 'title' => $body['title'] ?? '', 'body' => $body['body'] ?? '', 'head' => $body['head'], 'base' => $body['base'] ?? $repository->defaultBranch, 'state' => 'open', 'reviews' => []];

            return Response::json($this->pullOf($repository, $repository->pulls[$number]));
        });

        $this->on('GET', '/repos/{owner}/{repo}/pulls/{number}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $pull = $repository?->pulls[(int) $p['number']] ?? null;

            return $repository === null || $pull === null ? $this->notFound() : Response::json($this->pullOf($repository, $pull));
        });

        $this->on('PATCH', '/repos/{owner}/{repo}/pulls/{number}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null || !isset($repository->pulls[(int) $p['number']])) {
                return $this->notFound();
            }
            foreach ($this->request->json() as $field => $value) {
                $repository->pulls[(int) $p['number']][$field] = $value;
            }

            return Response::json($this->pullOf($repository, $repository->pulls[(int) $p['number']]));
        });

        $this->on('POST', '/repos/{owner}/{repo}/pulls/{number}/merge', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $pull = $repository?->pulls[(int) $p['number']] ?? null;
            if ($repository === null || $pull === null) {
                return $this->notFound();
            }
            $head = (string) $repository->resolve((string) $pull['head']);
            Git::setRef($repository->path, 'refs/heads/' . $pull['base'], $head);
            $repository->pulls[(int) $p['number']]['state'] = 'merged';

            return Response::json(['mergeCommitSha' => $head, 'mergedPullNumbers' => [(string) $pull['number']], 'pullRequest' => $this->pullOf($repository, $repository->pulls[(int) $p['number']])]);
        });

        $this->on('GET', '/repos/{owner}/{repo}/pulls/{number}/files', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $pull = $repository?->pulls[(int) $p['number']] ?? null;
            if ($repository === null || $pull === null) {
                return $this->notFound();
            }
            $files = array_map(static fn (array $c): array => ['filename' => $c['path'], 'status' => match ($c['status'][0] ?? '') {
                'A' => 'added',
                'D' => 'removed',
                default => 'modified',
            }], Git::changes($repository->path, 'refs/heads/' . $pull['base'], 'refs/heads/' . $pull['head']));

            return $this->paged($files, 'files');
        });

        $this->on('GET', '/repos/{owner}/{repo}/pulls/{number}/commits', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $pull = $repository?->pulls[(int) $p['number']] ?? null;
            if ($repository === null || $pull === null) {
                return $this->notFound();
            }
            $shas = Git::log($repository->path, 'refs/heads/' . $pull['base'] . '..refs/heads/' . $pull['head']);

            return $this->paged(array_map(fn (string $s): array => $this->commitOf($repository, $s), $shas), 'commits');
        });

        $this->on('GET', '/repos/{owner}/{repo}/pulls/{number}/comments', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null || !isset($repository->pulls[(int) $p['number']])) {
                return $this->notFound();
            }
            $comments = array_filter($repository->comments, static fn (array $c): bool => $c['pull'] === (int) $p['number']);

            return $this->paged(array_map($this->commentOf(...), $comments), 'comments');
        });

        $this->on('POST', '/repos/{owner}/{repo}/pulls/{number}/comments', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null || !isset($repository->pulls[(int) $p['number']])) {
                return $this->notFound();
            }
            $id = 'cmt_' . $this->world->id();
            $repository->comments[$id] = ['id' => $id, 'pull' => (int) $p['number'], 'body' => (string) ($this->request->json()['body'] ?? '')];

            return Response::json($this->commentOf($repository->comments[$id]));
        });

        $this->on('GET', '/repos/{owner}/{repo}/pulls/comments/{id}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $comment = $repository?->comments[$p['id']] ?? null;

            return $comment === null ? $this->notFound() : Response::json($this->commentOf($comment));
        });

        $this->on('PATCH', '/repos/{owner}/{repo}/pulls/comments/{id}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null || !isset($repository->comments[$p['id']])) {
                return $this->notFound();
            }
            $repository->comments[$p['id']]['body'] = (string) ($this->request->json()['body'] ?? '');

            return Response::json($this->commentOf($repository->comments[$p['id']]));
        });

        $this->on('GET', '/repos/{owner}/{repo}/pulls/{number}/reviews', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $pull = $repository?->pulls[(int) $p['number']] ?? null;

            return $pull === null ? $this->notFound() : $this->paged($pull['reviews'] ?? [], 'reviews');
        });

        $this->on('POST', '/repos/{owner}/{repo}/pulls/{number}/reviews', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null || !isset($repository->pulls[(int) $p['number']])) {
                return $this->notFound();
            }
            $body = $this->request->json();
            $id = 'rev_' . $this->world->id();
            $review = ['id' => $id, 'verdict' => $body['verdict'] ?? '', 'body' => $body['body'] ?? '', 'versionNumber' => (string) ($body['versionNumber'] ?? '1'), 'submittedAt' => World::TIME];
            $repository->pulls[(int) $p['number']]['reviews'][$id] = $review;

            return Response::json($review);
        });

        $this->on('PATCH', '/repos/{owner}/{repo}/pulls/{number}/reviews/{id}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null || !isset($repository->pulls[(int) $p['number']]['reviews'][$p['id']])) {
                return $this->notFound();
            }
            $repository->pulls[(int) $p['number']]['reviews'][$p['id']]['body'] = (string) ($this->request->json()['body'] ?? '');

            return Response::json($repository->pulls[(int) $p['number']]['reviews'][$p['id']]);
        });

        $this->on('PUT', '/repos/{owner}/{repo}/pulls/{number}/reviews/{id}/dismissals', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null || !isset($repository->pulls[(int) $p['number']]['reviews'][$p['id']])) {
                return $this->notFound();
            }
            $repository->pulls[(int) $p['number']]['reviews'][$p['id']]['dismissal'] = ['message' => (string) ($this->request->json()['message'] ?? ''), 'dismissedAt' => World::TIME];

            return Response::json($repository->pulls[(int) $p['number']]['reviews'][$p['id']]);
        });
    }

    private function checkRoutes(): void
    {
        $this->on('POST', '/repos/{owner}/{repo}/check-runs', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $body = $this->request->json();
            $sha = Git::resolve($repository->path, (string) ($body['headSha'] ?? ''));
            if ($sha === null) {
                return $this->error('INVALID_ARGUMENT', 'headSha does not name a commit', 400);
            }
            [$run] = $this->upsert($repository, $sha, (array) ($body['checkSuite'] ?? []), [(array) ($body['checkRun'] ?? [])]);

            return Response::json(['checkRun' => $run, 'checkSuite' => $run['checkSuite']]);
        });

        $this->on('POST', '/repos/{owner}/{repo}/check-runs:batchUpsert', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $body = $this->request->json();
            $sha = Git::resolve($repository->path, (string) ($body['headSha'] ?? ''));
            if ($sha === null) {
                return $this->error('INVALID_ARGUMENT', 'headSha does not name a commit', 400);
            }

            return Response::json(['checkRuns' => $this->upsert($repository, $sha, (array) ($body['checkSuite'] ?? []), array_values((array) ($body['checkRuns'] ?? [])))]);
        });

        $this->on('GET', '/repos/{owner}/{repo}/check-runs/{id}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $run = $repository?->checkRuns[$p['id']] ?? null;

            return $repository === null || $run === null ? $this->notFound() : Response::json($this->runOf($repository, $run));
        });

        $this->on('GET', '/repos/{owner}/{repo}/check-suites/{id}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $suite = $repository?->checkSuites[$p['id']] ?? null;

            return $suite === null ? $this->notFound() : Response::json(array_filter($suite, static fn (mixed $v): bool => $v !== ''));
        });

        $this->on('GET', '/repos/{owner}/{repo}/check-suites/{id}/check-runs', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null || !isset($repository->checkSuites[$p['id']])) {
                return $this->notFound();
            }
            $runs = array_filter($repository->checkRuns, static fn (array $r): bool => $r['suite'] === $p['id']);

            return $this->paged(array_map(fn (array $r): array => $this->runOf($repository, $r), $runs), 'checkRuns');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function installationOf(string $id): array
    {
        return ['id' => $id, 'target' => ['slug' => $this->world->config['owner'], 'id' => 'ns_fixture'], 'repoSelectionMode' => $this->world->config['selection'], 'app' => ['id' => 'app_fixture', 'slug' => 'fixture-app']];
    }

    /**
     * A file or directory at a commit, as the contents endpoint reports it.
     *
     * @return array<string, mixed>|null
     */
    private function contentAt(Repository $repository, string $sha, string $path): ?array
    {
        if ($path !== '') {
            $blob = Git::blob($repository->path, $sha, $path);
            if ($blob !== null) {
                return ['type' => 'file', 'name' => basename($path), 'path' => $path, 'sha' => $blob['sha'], 'size' => (string) \strlen($blob['content']), 'encoding' => 'base64', 'content' => base64_encode($blob['content'])];
            }
        }
        $tree = Git::tree($repository->path, $sha, $path);
        if ($tree === null) {
            return null;
        }

        return ['type' => 'dir', 'name' => basename($path), 'path' => $path, 'entries' => array_map(static fn (array $e): array => [
            'type' => $e['type'] === 'tree' ? 'dir' : 'file',
            'name' => basename($e['path']),
            'path' => ltrim($path . '/' . $e['path'], '/'),
            'sha' => $e['sha'],
            'size' => (string) $e['size'],
        ], $tree)];
    }

    /**
     * @param array<string, mixed> $comment
     * @return array<string, mixed>
     */
    private function commentOf(array $comment): array
    {
        return ['id' => $comment['id'], 'body' => $comment['body'], 'author' => ['user' => ['id' => 'user_1']], 'createdAt' => World::TIME];
    }
}
