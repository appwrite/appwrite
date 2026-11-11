<?php

namespace Tests\Compat\Vcs\Fixtures;

/**
 * GitHub's REST API (api.github.com) as a GitHub App installation sees it.
 */
final class GitHub extends Provider
{
    protected function notFound(): Response
    {
        return Response::json(['message' => 'Not Found', 'documentation_url' => 'https://docs.github.com/rest', 'status' => '404'], 404);
    }

    protected function unauthorized(): Response
    {
        return Response::json(['message' => 'Bad credentials', 'documentation_url' => 'https://docs.github.com/rest', 'status' => '401'], 401);
    }

    private function invalid(string $message): Response
    {
        return Response::json(['message' => $message, 'documentation_url' => 'https://docs.github.com/rest', 'status' => '422'], 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function account(string $login): array
    {
        $user = $this->world->user($login);

        return [
            'login' => $login,
            'id' => $user['id'],
            'node_id' => 'U_' . $user['id'],
            'avatar_url' => 'https://avatars.githubusercontent.com/u/' . $user['id'] . '?v=4',
            'html_url' => 'https://github.com/' . $login,
            'type' => isset($this->world->organizations[strtolower($login)]) ? 'Organization' : 'User',
            'site_admin' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function repository(Repository $repository): array
    {
        return [
            'id' => $repository->id,
            'node_id' => 'R_' . $repository->id,
            'name' => $repository->name,
            'full_name' => $repository->fullName(),
            'private' => $repository->private,
            'owner' => $this->account($repository->owner),
            'html_url' => 'https://github.com/' . $repository->fullName(),
            'description' => $repository->description === '' ? null : $repository->description,
            'fork' => false,
            'url' => $this->request->base . '/repos/' . $repository->fullName(),
            'created_at' => World::TIME,
            'updated_at' => World::TIME,
            'pushed_at' => World::TIME,
            'clone_url' => 'https://github.com/' . $repository->fullName() . '.git',
            'size' => 0,
            'visibility' => $repository->private ? 'private' : 'public',
            'default_branch' => $repository->defaultBranch,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commit(Repository $repository, string $sha): array
    {
        $info = Git::info($repository->path, $sha) ?? ['sha' => $sha, 'tree' => '', 'parents' => [], 'author' => '', 'email' => '', 'message' => ''];
        $person = ['name' => $info['author'], 'email' => $info['email'], 'date' => World::TIME];

        return [
            'sha' => $info['sha'],
            'node_id' => 'C_' . $info['sha'],
            'commit' => [
                'author' => $person,
                'committer' => $person,
                'message' => $info['message'],
                'tree' => ['sha' => $info['tree']],
            ],
            'url' => $this->request->base . '/repos/' . $repository->fullName() . '/commits/' . $info['sha'],
            'html_url' => 'https://github.com/' . $repository->fullName() . '/commit/' . $info['sha'],
            'author' => $this->account((string) $this->world->config['user']),
            'committer' => $this->account((string) $this->world->config['user']),
            'parents' => array_map(fn (string $parent): array => ['sha' => $parent], $info['parents']),
        ];
    }

    /**
     * @param array<string, mixed> $pull
     * @return array<string, mixed>
     */
    private function pull(Repository $repository, array $pull): array
    {
        $side = fn (string $branch): array => [
            'label' => $repository->owner . ':' . $branch,
            'ref' => $branch,
            'sha' => (string) ($repository->resolve($branch) ?? ''),
            'user' => $this->account($repository->owner),
            'repo' => $this->repository($repository),
        ];

        return [
            'id' => $pull['id'],
            'number' => $pull['number'],
            'state' => $pull['state'],
            'title' => $pull['title'],
            'body' => $pull['body'],
            'user' => $this->account((string) $this->world->config['user']),
            'html_url' => 'https://github.com/' . $repository->fullName() . '/pull/' . $pull['number'],
            'head' => $side((string) $pull['head']),
            'base' => $side((string) $pull['base']),
            'created_at' => World::TIME,
            'updated_at' => World::TIME,
        ];
    }

    /**
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    private function checkRun(Repository $repository, array $run): array
    {
        return [
            'id' => $run['id'],
            'head_sha' => $run['head_sha'],
            'node_id' => 'CR_' . $run['id'],
            'external_id' => $run['external_id'] ?? '',
            'url' => $this->request->base . '/repos/' . $repository->fullName() . '/check-runs/' . $run['id'],
            'html_url' => 'https://github.com/' . $repository->fullName() . '/runs/' . $run['id'],
            'details_url' => $run['details_url'] ?? 'https://github.com/' . $repository->fullName() . '/runs/' . $run['id'],
            'status' => $run['status'],
            'conclusion' => $run['conclusion'] ?? null,
            'started_at' => $run['started_at'] ?? World::TIME,
            'completed_at' => $run['completed_at'] ?? null,
            'output' => [
                'title' => $run['output']['title'] ?? null,
                'summary' => $run['output']['summary'] ?? null,
                'text' => $run['output']['text'] ?? null,
                'annotations_count' => \count($run['output']['annotations'] ?? []),
            ],
            'name' => $run['name'],
        ];
    }

    private function find(string $owner, string $name): ?Repository
    {
        return $this->world->repository($owner, $name);
    }

    protected function routes(): void
    {
        $this->on('POST', '/app/installations/{id}/access_tokens', function (array $p): Response {
            if (!Jwt::is($this->request->token())) {
                return $this->unauthorized();
            }

            return Response::json(['token' => 'ghs_fixture' . $p['id'], 'expires_at' => World::TIME, 'permissions' => ['contents' => 'write']], 201);
        });

        $this->on('GET', '/app/installations/{id}', function (array $p): Response {
            if (!Jwt::is($this->request->token())) {
                return $this->unauthorized();
            }
            $missing = $this->world->config['missing'] ?? [];
            if (\in_array($p['id'], \is_array($missing) ? $missing : [], true)) {
                return $this->notFound();
            }

            return Response::json([
                'id' => (int) $p['id'],
                'account' => $this->account((string) $this->world->config['owner']),
                'repository_selection' => $this->world->config['selection'],
                'target_type' => 'Organization',
            ]);
        });

        $this->on('GET', '/users/{login}', function (array $p): Response {
            return Response::json($this->account($p['login']) + ['name' => $p['login'], 'public_repos' => 0]);
        });

        $this->on('POST', '/orgs/{org}/repos', function (array $p): Response {
            $body = $this->request->json();
            $name = (string) preg_replace('/[^A-Za-z0-9_.-]/', '-', (string) ($body['name'] ?? ''));
            if ($name === '' || $this->find($p['org'], $name) !== null) {
                return $this->invalid('Repository creation failed.');
            }
            $this->world->organizations[strtolower($p['org'])] ??= ['name' => $p['org']];
            $repository = $this->world->create($p['org'], $name, (bool) ($body['private'] ?? false), 'main');

            return Response::json($this->repository($repository), 201);
        });

        $this->on('GET', '/search/repositories', function (): Response {
            $q = (string) $this->request->input('q', '');
            $owner = null;
            $terms = [];
            foreach (preg_split('/\s+/', trim($q)) ?: [] as $token) {
                if (str_starts_with($token, 'user:')) {
                    $owner = substr($token, 5);
                } elseif ($token !== '' && !str_contains($token, ':')) {
                    $terms[] = $token;
                }
            }
            $private = $this->world->config['private_owners'] ?? [];
            if ($owner !== null && \in_array($owner, \is_array($private) ? $private : [], true)) {
                return $this->invalid('Validation Failed');
            }
            $items = [];
            foreach ($this->world->repositories as $repository) {
                if ($owner !== null && strcasecmp($repository->owner, $owner) !== 0) {
                    continue;
                }
                foreach ($terms as $term) {
                    if (stripos($repository->name, $term) === false) {
                        continue 2;
                    }
                }
                $items[] = $this->repository($repository);
            }
            $page = max(1, (int) $this->request->input('page', 1));
            $perPage = max(1, (int) $this->request->input('per_page', 30));

            return Response::json(['total_count' => \count($items), 'incomplete_results' => false, 'items' => \array_slice($items, ($page - 1) * $perPage, $perPage)]);
        });

        $this->on('GET', '/installation/repositories', function (): Response {
            $all = array_values(array_map($this->repository(...), $this->world->repositories));
            $page = max(1, (int) $this->request->input('page', 1));
            $perPage = max(1, (int) $this->request->input('per_page', 30));

            return Response::json(['total_count' => \count($all), 'repositories' => \array_slice($all, ($page - 1) * $perPage, $perPage)]);
        });

        $this->on('GET', '/repositories/{id}', function (array $p): Response {
            $repository = $this->world->repositoryById($p['id']);

            return $repository === null ? $this->notFound() : Response::json($this->repository($repository));
        });

        $this->on('GET', '/repos/{owner}/{repo}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);

            return $repository === null ? $this->notFound() : Response::json($this->repository($repository));
        });

        $this->on('DELETE', '/repos/{owner}/{repo}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $this->world->delete($repository);

            return Response::empty();
        });

        $this->on('POST', '/repos/{owner}/{repo}/hooks', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $body = $this->request->json();
            $id = $this->world->id();
            $repository->hooks[$id] = ['id' => $id, 'events' => $body['events'] ?? ['push'], 'url' => $body['config']['url'] ?? '', 'secret' => $body['config']['secret'] ?? ''];

            return Response::json(['type' => 'Repository', 'id' => $id, 'name' => 'web', 'active' => true, 'events' => $body['events'] ?? ['push'], 'config' => ['content_type' => 'json', 'insecure_ssl' => '0', 'url' => $body['config']['url'] ?? '']], 201);
        });

        $this->on('PUT', '/repos/{owner}/{repo}/contents/{path*}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $body = $this->request->json();
            $branch = (string) ($body['branch'] ?? $repository->defaultBranch);
            $tip = $repository->resolve($branch);
            if ($tip === null && !$repository->empty()) {
                return $this->notFound();
            }
            if ($tip !== null && Git::blob($repository->path, $tip, $p['path']) !== null && !isset($body['sha'])) {
                return $this->invalid('Invalid request.\n\n"sha" wasn\'t supplied.');
            }
            $sha = Git::commit($repository->path, $branch, [$p['path'] => (string) base64_decode((string) ($body['content'] ?? ''))], (string) ($body['message'] ?? ''), $this->author());
            $blob = Git::blob($repository->path, (string) $sha, $p['path']);

            return Response::json([
                'content' => ['name' => basename($p['path']), 'path' => $p['path'], 'sha' => $blob['sha'] ?? '', 'size' => \strlen($blob['content'] ?? ''), 'type' => 'file'],
                'commit' => ['sha' => $sha, 'message' => $body['message'] ?? '', 'html_url' => 'https://github.com/' . $repository->fullName() . '/commit/' . $sha],
            ], 201);
        });

        $this->on('POST', '/repos/{owner}/{repo}/git/refs', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $body = $this->request->json();
            $ref = (string) ($body['ref'] ?? '');
            $sha = (string) ($body['sha'] ?? '');
            if (!str_starts_with($ref, 'refs/') || Git::resolve($repository->path, $sha) === null) {
                return $this->invalid('Reference update failed');
            }
            if (Git::resolve($repository->path, $ref) !== null) {
                return $this->invalid('Reference already exists');
            }
            Git::setRef($repository->path, $ref, $sha);

            return Response::json(['ref' => $ref, 'node_id' => 'REF_' . $sha, 'url' => $this->request->base . '/repos/' . $repository->fullName() . '/git/' . $ref, 'object' => ['sha' => $sha, 'type' => 'commit']], 201);
        });

        $this->on('GET', '/repos/{owner}/{repo}/git/matching-refs/{kind}/{prefix*}', fn (array $p): Response => $this->matchingRefs($p));
        $this->on('GET', '/repos/{owner}/{repo}/git/matching-refs/{kind}/', fn (array $p): Response => $this->matchingRefs($p + ['prefix' => '']));

        $this->on('GET', '/repos/{owner}/{repo}/git/trees/{ref*}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $sha = $repository?->resolve($p['ref']);
            if ($repository === null || $sha === null) {
                return $this->notFound();
            }
            $recursive = (string) $this->request->input('recursive', '') !== '';
            $tree = array_map(fn (array $e): array => [
                'path' => $e['path'],
                'mode' => $e['mode'],
                'type' => $e['type'],
                'sha' => $e['sha'],
            ] + ($e['type'] === 'blob' ? ['size' => $e['size']] : []), Git::tree($repository->path, $sha, '', $recursive) ?? []);

            return Response::json(['sha' => $sha, 'url' => $this->request->base . '/repos/' . $repository->fullName() . '/git/trees/' . $sha, 'tree' => $tree, 'truncated' => false]);
        });

        $this->on('GET', '/repos/{owner}/{repo}/languages', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $languages = $repository->languages();

            return Response::json($languages === [] ? new \stdClass() : $languages);
        });

        $this->on('GET', '/repos/{owner}/{repo}/contents', fn (array $p): Response => $this->contents($p + ['path' => '']));
        $this->on('GET', '/repos/{owner}/{repo}/contents/{path*}', fn (array $p): Response => $this->contents($p));

        $this->on('POST', '/repos/{owner}/{repo}/issues/{number}/comments', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null || !isset($repository->pulls[(int) $p['number']])) {
                return $this->notFound();
            }
            $id = $this->world->id();
            $repository->comments[$id] = ['id' => $id, 'pull' => (int) $p['number'], 'body' => (string) ($this->request->json()['body'] ?? '')];

            return Response::json($this->comment($repository, $repository->comments[$id]), 201);
        });

        $this->on('GET', '/repos/{owner}/{repo}/issues/comments/{id}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $comment = $repository?->comments[(int) $p['id']] ?? null;

            return $repository === null || $comment === null ? $this->notFound() : Response::json($this->comment($repository, $comment));
        });

        $this->on('PATCH', '/repos/{owner}/{repo}/issues/comments/{id}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null || !isset($repository->comments[(int) $p['id']])) {
                return $this->notFound();
            }
            $repository->comments[(int) $p['id']]['body'] = (string) ($this->request->json()['body'] ?? '');

            return Response::json($this->comment($repository, $repository->comments[(int) $p['id']]));
        });

        $this->on('GET', '/repos/{owner}/{repo}/pulls', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $head = (string) $this->request->input('head', '');
            $state = (string) $this->request->input('state', 'open');
            $pulls = [];
            foreach (array_reverse($repository->pulls, true) as $pull) {
                if ($state !== 'all' && $pull['state'] !== $state) {
                    continue;
                }
                if ($head !== '' && $head !== $repository->owner . ':' . $pull['head'] && $head !== $pull['head']) {
                    continue;
                }
                $pulls[] = $this->pull($repository, $pull);
            }

            return Response::json(\array_slice($pulls, 0, max(1, (int) $this->request->input('per_page', 30))));
        });

        $this->on('GET', '/repos/{owner}/{repo}/pulls/{number}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $pull = $repository?->pulls[(int) $p['number']] ?? null;

            return $repository === null || $pull === null ? $this->notFound() : Response::json($this->pull($repository, $pull));
        });

        $this->on('GET', '/repos/{owner}/{repo}/pulls/{number}/files', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $pull = $repository?->pulls[(int) $p['number']] ?? null;
            if ($repository === null || $pull === null) {
                return $this->notFound();
            }
            $files = array_map(static fn (array $c): array => [
                'sha' => '',
                'filename' => $c['path'],
                'status' => match ($c['status'][0] ?? '') {
                    'A' => 'added',
                    'D' => 'removed',
                    default => 'modified',
                },
                'additions' => 1,
                'deletions' => 0,
                'changes' => 1,
            ], Git::changes($repository->path, 'refs/heads/' . $pull['base'], 'refs/heads/' . $pull['head']));
            $page = max(1, (int) $this->request->input('page', 1));
            $perPage = max(1, (int) $this->request->input('per_page', 30));

            return Response::json(\array_slice($files, ($page - 1) * $perPage, $perPage));
        });

        $this->on('GET', '/repos/{owner}/{repo}/branches', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $branches = [];
            foreach (Git::branches($repository->path) as $name => $sha) {
                $branches[] = ['name' => $name, 'commit' => ['sha' => $sha, 'url' => $this->request->base . '/repos/' . $repository->fullName() . '/commits/' . $sha], 'protected' => false];
            }
            $page = max(1, (int) $this->request->input('page', 1));
            $perPage = max(1, (int) $this->request->input('per_page', 30));

            return Response::json(\array_slice($branches, ($page - 1) * $perPage, $perPage));
        });

        $this->on('GET', '/repos/{owner}/{repo}/commits/{ref*}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $sha = $repository->resolve($p['ref']);
            if ($sha === null) {
                return preg_match('/^[0-9a-f]{40}$/', $p['ref']) === 1 || $repository->empty()
                    ? $this->notFound()
                    : $this->invalid('No commit found for SHA: ' . $p['ref']);
            }

            return Response::json($this->commit($repository, $sha));
        });

        foreach (['tarball', 'zipball'] as $format) {
            $this->on('GET', '/repos/{owner}/{repo}/' . $format . '/{ref*}', fn (array $p): Response => $this->archive($p + ['format' => $format]));
            $this->on('GET', '/repos/{owner}/{repo}/' . $format, fn (array $p): Response => $this->archive($p + ['format' => $format, 'ref' => '']));
        }

        $this->on('POST', '/repos/{owner}/{repo}/statuses/{sha}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            if (preg_match('/^[0-9a-f]{40}$/', $p['sha']) !== 1 || Git::resolve($repository->path, $p['sha']) === null) {
                return $this->invalid('No commit found for SHA: ' . $p['sha']);
            }
            $body = $this->request->json();
            $status = ['id' => $this->world->id(), 'state' => $body['state'] ?? '', 'description' => $body['description'] ?? '', 'target_url' => $body['target_url'] ?? '', 'context' => $body['context'] ?? 'default', 'created_at' => World::TIME];
            $repository->statuses[$p['sha']][] = $status;

            return Response::json($status, 201);
        });

        $this->on('POST', '/repos/{owner}/{repo}/check-runs', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $body = $this->request->json();
            if (Git::resolve($repository->path, (string) ($body['head_sha'] ?? '')) === null) {
                return $this->invalid('No commit found for SHA: ' . ($body['head_sha'] ?? ''));
            }
            $id = (string) $this->world->id();
            $repository->checkRuns[$id] = ['id' => (int) $id] + $body;

            return Response::json($this->checkRun($repository, $repository->checkRuns[$id]), 201);
        });

        $this->on('GET', '/repos/{owner}/{repo}/check-runs/{id}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $run = $repository?->checkRuns[$p['id']] ?? null;

            return $repository === null || $run === null ? $this->notFound() : Response::json($this->checkRun($repository, $run));
        });

        $this->on('PATCH', '/repos/{owner}/{repo}/check-runs/{id}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null || !isset($repository->checkRuns[$p['id']])) {
                return $this->notFound();
            }
            $repository->checkRuns[$p['id']] = array_merge($repository->checkRuns[$p['id']], $this->request->json());

            return Response::json($this->checkRun($repository, $repository->checkRuns[$p['id']]));
        });
    }

    /**
     * @param array<string, string> $p
     */
    private function matchingRefs(array $p): Response
    {
        $repository = $this->find($p['owner'], $p['repo']);
        if ($repository === null) {
            return $this->notFound();
        }
        $refs = match ($p['kind']) {
            'heads' => Git::branches($repository->path),
            'tags' => Git::tags($repository->path),
            default => [],
        };
        $out = [];
        foreach ($refs as $name => $sha) {
            if (!str_starts_with($name, $p['prefix'])) {
                continue;
            }
            $out[] = ['ref' => 'refs/' . $p['kind'] . '/' . $name, 'node_id' => 'REF_' . $sha, 'object' => ['sha' => $sha, 'type' => 'commit']];
        }

        return Response::json($out);
    }

    /**
     * @param array<string, string> $p
     */
    private function contents(array $p): Response
    {
        $repository = $this->find($p['owner'], $p['repo']);
        if ($repository === null) {
            return $this->notFound();
        }
        $ref = (string) $this->request->input('ref', $repository->defaultBranch);
        $sha = $repository->resolve($ref);
        if ($sha === null) {
            return $this->notFound();
        }
        $path = trim($p['path'], '/');
        if ($path !== '') {
            $blob = Git::blob($repository->path, $sha, $path);
            if ($blob !== null) {
                return Response::json([
                    'type' => 'file',
                    'encoding' => 'base64',
                    'size' => \strlen($blob['content']),
                    'name' => basename($path),
                    'path' => $path,
                    'content' => chunk_split(base64_encode($blob['content']), 60, "\n"),
                    'sha' => $blob['sha'],
                ]);
            }
        }
        $tree = Git::tree($repository->path, $sha, $path);
        if ($tree === null) {
            return $this->notFound();
        }

        return Response::json(array_map(static fn (array $e): array => [
            'type' => $e['type'] === 'tree' ? 'dir' : 'file',
            'size' => $e['size'],
            'name' => basename($e['path']),
            'path' => ltrim($path . '/' . $e['path'], '/'),
            'sha' => $e['sha'],
        ], $tree));
    }

    /**
     * @param array<string, string> $p
     */
    private function archive(array $p): Response
    {
        if (!\in_array($p['format'], ['tarball', 'zipball'], true)) {
            return $this->notFound();
        }
        $repository = $this->find($p['owner'], $p['repo']);
        $ref = $p['ref'] === '' ? ($repository->defaultBranch ?? '') : $p['ref'];
        if ($repository === null || $repository->resolve($ref) === null) {
            return $this->notFound();
        }
        $kind = $p['format'] === 'tarball' ? 'legacy.tar.gz' : 'legacy.zip';

        return new Response(302, ['Location' => 'https://codeload.github.com/' . $repository->fullName() . '/' . $kind . '/refs/heads/' . $ref . '?token=FIXTURE'], '');
    }

    /**
     * @param array<string, mixed> $comment
     * @return array<string, mixed>
     */
    private function comment(Repository $repository, array $comment): array
    {
        return [
            'id' => $comment['id'],
            'node_id' => 'IC_' . $comment['id'],
            'html_url' => 'https://github.com/' . $repository->fullName() . '/pull/' . $comment['pull'] . '#issuecomment-' . $comment['id'],
            'body' => $comment['body'],
            'user' => $this->account((string) $this->world->config['user']),
            'created_at' => World::TIME,
            'updated_at' => World::TIME,
        ];
    }
}
