<?php

namespace Tests\Compat\Vcs\Fixtures;

/**
 * Bitbucket Cloud's REST API (`/2.0`); the browser and clone host is the
 * same base without `/2.0`.
 */
final class Bitbucket extends Provider
{
    protected function notFound(): Response
    {
        return $this->error('Resource not found', 404);
    }

    protected function unauthorized(): Response
    {
        return $this->error('Unauthorized', 401);
    }

    private function error(string $message, int $status): Response
    {
        return Response::json(['type' => 'error', 'error' => ['message' => $message]], $status);
    }

    private function api(): string
    {
        return $this->request->base . '/2.0';
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceOf(string $slug): array
    {
        $id = $this->world->user($slug)['id'];

        return [
            'type' => 'workspace',
            'uuid' => \sprintf('{%08d-1111-4000-8000-%012d}', $id, $id),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'links' => ['avatar' => ['href' => 'https://bitbucket.org/workspaces/' . $slug . '/avatar/'], 'html' => ['href' => 'https://bitbucket.org/' . $slug . '/']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userOf(): array
    {
        $login = (string) $this->world->config['user'];
        $id = $this->world->user($login)['id'];

        return [
            'type' => 'user',
            'display_name' => ucfirst($login),
            'nickname' => $login,
            'uuid' => \sprintf('{%08d-2222-4000-8000-%012d}', $id, $id),
            'account_id' => 'acct-' . $id,
            'links' => ['avatar' => ['href' => 'https://avatar-management.example/' . $login . '.png'], 'html' => ['href' => 'https://bitbucket.org/' . $login . '/']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function repositoryOf(Repository $repository): array
    {
        return [
            'type' => 'repository',
            'uuid' => $repository->uuid,
            'full_name' => $repository->fullName(),
            'name' => $repository->name,
            'slug' => strtolower($repository->name),
            'is_private' => $repository->private,
            'scm' => 'git',
            'description' => $repository->description,
            'language' => $this->world->config['language'] ?? '',
            'mainbranch' => $repository->empty() ? null : ['name' => $repository->defaultBranch, 'type' => 'branch'],
            'workspace' => $this->workspaceOf($repository->owner),
            'created_on' => World::TIME,
            'updated_on' => World::TIME,
            'size' => 0,
            'links' => [
                'html' => ['href' => $this->request->base . '/' . $repository->fullName()],
                'self' => ['href' => $this->api() . '/repositories/' . $repository->fullName()],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commitOf(Repository $repository, string $sha): array
    {
        $info = Git::info($repository->path, $sha) ?? ['sha' => $sha, 'tree' => '', 'parents' => [], 'author' => '', 'email' => '', 'message' => ''];
        $user = $this->userOf();

        return [
            'type' => 'commit',
            'hash' => $info['sha'],
            'date' => '2026-01-01T00:00:00+00:00',
            'author' => ['type' => 'author', 'raw' => $info['author'] . ' <' . $info['email'] . '>', 'user' => $user],
            'message' => $info['message'] . "\n",
            'parents' => array_map(fn (string $parent): array => ['hash' => $parent, 'type' => 'commit'], $info['parents']),
            'links' => [
                'self' => ['href' => $this->api() . '/repositories/' . $repository->fullName() . '/commit/' . $info['sha']],
                'html' => ['href' => $this->request->base . '/' . $repository->fullName() . '/commits/' . $info['sha']],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $pull
     * @return array<string, mixed>
     */
    private function pullOf(Repository $repository, array $pull): array
    {
        $side = fn (string $branch): array => [
            'branch' => ['name' => $branch],
            'commit' => ['hash' => (string) ($repository->resolve($branch) ?? ''), 'type' => 'commit'],
            'repository' => ['type' => 'repository', 'uuid' => $repository->uuid, 'full_name' => $repository->fullName(), 'name' => $repository->name],
        ];

        return [
            'type' => 'pullrequest',
            'id' => $pull['number'],
            'title' => $pull['title'],
            'description' => $pull['body'],
            'state' => strtoupper((string) $pull['state']),
            'author' => $this->userOf(),
            'source' => $side((string) $pull['head']),
            'destination' => $side((string) $pull['base']),
            'created_on' => World::TIME,
            'updated_on' => World::TIME,
            'links' => ['html' => ['href' => $this->request->base . '/' . $repository->fullName() . '/pull-requests/' . $pull['number']]],
        ];
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function statusOf(Repository $repository, string $sha, array $status): array
    {
        return [
            'type' => 'build',
            'key' => $status['key'],
            'name' => $status['name'] ?? $status['key'],
            'state' => $status['state'],
            'url' => $status['url'] ?? '',
            'description' => $status['description'] ?? '',
            'created_on' => World::TIME,
            'updated_on' => World::TIME,
            'links' => [
                'self' => ['href' => $this->api() . '/repositories/' . $repository->fullName() . '/commit/' . $sha . '/statuses/build/' . rawurlencode((string) $status['key'])],
                'commit' => ['href' => $this->api() . '/repositories/' . $repository->fullName() . '/commit/' . $sha],
            ],
        ];
    }

    /**
     * A page of values, with the next page's URL when there is one.
     *
     * @param array<mixed> $values
     */
    private function paged(array $values, int $default = 10): Response
    {
        $page = max(1, (int) $this->request->input('page', 1));
        $pagelen = max(1, (int) $this->request->input('pagelen', $default));
        $out = ['pagelen' => $pagelen, 'size' => \count($values), 'page' => $page, 'values' => \array_slice($values, ($page - 1) * $pagelen, $pagelen)];
        if ($page * $pagelen < \count($values)) {
            $query = $this->request->query;
            $query['page'] = $page + 1;
            $out['next'] = $this->request->base . $this->request->path . '?' . http_build_query($query);
        }

        return Response::json($out);
    }

    /**
     * The value a `field~"value"` filter quotes.
     */
    private static function filter(string $q, string $field): ?string
    {
        if (preg_match('/^' . $field . '~"((?:[^"\\\\]|\\\\.)*)"$/', $q, $m) !== 1) {
            return null;
        }

        return stripslashes($m[1]);
    }

    private function find(string $workspace, string $slug): ?Repository
    {
        return $this->world->repository($workspace, $slug);
    }

    /**
     * A form body's fields, names untouched (parse_str would turn dots into
     * underscores).
     *
     * @return array<string, string>
     */
    private function fields(): array
    {
        $fields = [];
        foreach (explode('&', $this->request->body) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2) + ['', ''];
            $fields[urldecode($name)] = urldecode($value);
        }

        return $fields;
    }

    protected function routes(): void
    {
        $prefix = '/2.0/repositories/{ws}/{slug}';

        $this->on('GET', '/2.0/user', fn (): Response => Response::json($this->userOf()));

        $this->on('GET', '/2.0/users/{user}', function (array $p): Response {
            $user = $this->userOf();

            return $p['user'] === $user['uuid'] || $p['user'] === $user['account_id'] ? Response::json($user) : $this->notFound();
        });

        $this->on('GET', '/2.0/user/workspaces', function (): Response {
            $slug = (string) $this->world->config['workspace'];
            $values = [];
            $wanted = self::filter((string) $this->request->input('q', ''), 'slug');
            if ($wanted === null || stripos($slug, $wanted) !== false) {
                $values[] = ['type' => 'workspace_access', 'administrator' => true, 'workspace' => $this->workspaceOf($slug)];
            }

            return $this->paged($values);
        });

        $this->on('GET', '/2.0/repositories/{ws}', function (array $p): Response {
            $name = self::filter((string) $this->request->input('q', ''), 'name');
            $values = [];
            foreach ($this->world->repositories as $repository) {
                if (strcasecmp($repository->owner, $p['ws']) === 0 && ($name === null || stripos($repository->name, $name) !== false)) {
                    $values[] = $this->repositoryOf($repository);
                }
            }

            return $this->paged($values);
        });

        $this->on('POST', $prefix, function (array $p): Response {
            if (preg_match('/^[a-z0-9_.-]+$/i', $p['slug']) !== 1) {
                return $this->error('Invalid slug. Slugs must be alphanumeric, underscore, dash, or period.', 400);
            }
            if ($this->find($p['ws'], $p['slug']) !== null) {
                return $this->error('Repository with this Slug and Owner already exists.', 400);
            }
            $body = $this->request->json();
            $repository = $this->world->create($p['ws'], $p['slug'], (bool) ($body['is_private'] ?? false), 'master');

            return Response::json($this->repositoryOf($repository));
        });

        $this->on('GET', $prefix, function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);

            return $repository === null ? $this->error('Repository ' . $p['ws'] . '/' . $p['slug'] . ' not found', 404) : Response::json($this->repositoryOf($repository));
        });

        $this->on('DELETE', $prefix, function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            if ($repository === null) {
                return $this->error('Repository ' . $p['ws'] . '/' . $p['slug'] . ' not found', 404);
            }
            $this->world->delete($repository);

            return Response::empty();
        });

        $this->on('GET', $prefix . '/refs/branches/{name*}', function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            $sha = $repository !== null ? (Git::branches($repository->path)[$p['name']] ?? null) : null;
            if ($repository === null || $sha === null) {
                return $this->error('Branch "' . $p['name'] . '" not found', 404);
            }

            return Response::json(['type' => 'branch', 'name' => $p['name'], 'target' => $this->commitOf($repository, $sha)]);
        });

        foreach (['branches' => 'branch', 'tags' => 'tag'] as $kind => $type) {
            $this->on('GET', $prefix . '/refs/' . $kind, function (array $p) use ($kind, $type): Response {
                $repository = $this->find($p['ws'], $p['slug']);
                if ($repository === null) {
                    return $this->notFound();
                }
                $refs = $kind === 'branches' ? Git::branches($repository->path) : Git::tags($repository->path);
                $values = [];
                foreach ($refs as $name => $sha) {
                    $values[] = ['type' => $type, 'name' => $name, 'target' => ['hash' => $sha, 'type' => 'commit']];
                }

                return $this->paged($values);
            });
        }

        $this->on('POST', $prefix . '/refs/branches', function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            if ($repository === null) {
                return $this->notFound();
            }
            $body = $this->request->json();
            $name = (string) ($body['name'] ?? '');
            $sha = $repository->resolve((string) ($body['target']['hash'] ?? ''));
            if ($sha === null) {
                return $this->error('Commit not found', 400);
            }
            if (isset(Git::branches($repository->path)[$name])) {
                return $this->error('BRANCH_ALREADY_EXISTS', 400);
            }
            Git::setRef($repository->path, 'refs/heads/' . $name, $sha);

            return Response::json(['type' => 'branch', 'name' => $name, 'target' => $this->commitOf($repository, $sha)], 201);
        });

        $this->on('POST', $prefix . '/refs/tags', function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            if ($repository === null) {
                return $this->notFound();
            }
            $body = $this->request->json();
            $name = (string) ($body['name'] ?? '');
            $sha = $repository->resolve((string) ($body['target']['hash'] ?? ''));
            if ($sha === null) {
                return $this->error('Commit not found', 400);
            }
            if (isset(Git::tags($repository->path)[$name])) {
                return $this->error('Tag ' . $name . ' already exists', 400);
            }
            $message = (string) ($body['message'] ?? '');
            if ($message === '') {
                Git::setRef($repository->path, 'refs/tags/' . $name, $sha);
            } else {
                Git::annotatedTag($repository->path, $name, $sha, $message, $this->author());
            }

            return Response::json(['type' => 'tag', 'name' => $name, 'message' => $message, 'target' => $this->commitOf($repository, $sha)], 201);
        });

        $this->on('GET', $prefix . '/src/{ref}/', fn (array $p): Response => $this->source($p + ['path' => '']));
        $this->on('GET', $prefix . '/src/{ref}/{path*}', fn (array $p): Response => $this->source($p));

        $this->on('POST', $prefix . '/src', function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            if ($repository === null) {
                return $this->notFound();
            }
            $fields = $this->fields();
            $message = $fields['message'] ?? 'Commit';
            $branch = $fields['branch'] ?? ($repository->empty() ? 'master' : $repository->defaultBranch);
            // A branch that does not exist yet grows from the main branch.
            $wasEmpty = $repository->empty();
            $files = [];
            foreach ($fields as $name => $value) {
                if (str_starts_with($name, '/')) {
                    $files[ltrim($name, '/')] = $value;
                }
            }
            $sha = Git::commit($repository->path, $branch, $files, $message, $this->author(), 'refs/heads/' . $repository->defaultBranch);
            if ($wasEmpty) {
                $repository->defaultBranch = $branch;
            }

            return new Response(201, ['Location' => $this->api() . '/repositories/' . $repository->fullName() . '/commit/' . $sha], '');
        });

        $this->on('GET', $prefix . '/commit/{hash}', function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            $sha = $repository !== null && preg_match('/^[0-9a-f]{4,40}$/', $p['hash']) === 1 ? Git::resolve($repository->path, $p['hash']) : null;

            return $repository === null || $sha === null ? $this->error('Commit not found', 404) : Response::json($this->commitOf($repository, $sha));
        });

        $this->on('GET', $prefix . '/commits/{ref}', function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            $sha = $repository?->resolve($p['ref']);
            if ($repository === null || $sha === null) {
                return $this->error('Commit not found', 404);
            }

            return $this->paged(array_map(fn (string $s): array => $this->commitOf($repository, $s), Git::log($repository->path, $sha)), 30);
        });

        $this->on('POST', $prefix . '/commit/{hash}/statuses/build', function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            $sha = $repository !== null && preg_match('/^[0-9a-f]{40}$/', $p['hash']) === 1 ? Git::resolve($repository->path, $p['hash']) : null;
            if ($repository === null || $sha === null) {
                return $this->error('Commit not found', 404);
            }
            $body = $this->request->json();
            $key = (string) ($body['key'] ?? '');
            $statuses = array_values(array_filter($repository->statuses[$sha] ?? [], static fn (array $s): bool => $s['key'] !== $key));
            $statuses[] = $body;
            $repository->statuses[$sha] = $statuses;

            return Response::json($this->statusOf($repository, $sha, $body), 201);
        });

        $this->on('GET', $prefix . '/commit/{hash}/statuses', function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            if ($repository === null) {
                return $this->notFound();
            }

            return $this->paged(array_map(fn (array $s): array => $this->statusOf($repository, $p['hash'], $s), $repository->statuses[$p['hash']] ?? []));
        });

        $this->on('GET', $prefix . '/commit/{hash}/statuses/build/{key}', function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            foreach ($repository->statuses[$p['hash']] ?? [] as $status) {
                if ($repository !== null && $status['key'] === $p['key']) {
                    return Response::json($this->statusOf($repository, $p['hash'], $status));
                }
            }

            return $this->error('Build status not found', 404);
        });

        $this->on('POST', $prefix . '/pullrequests', function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            if ($repository === null) {
                return $this->notFound();
            }
            $body = $this->request->json();
            $head = (string) ($body['source']['branch']['name'] ?? '');
            $base = (string) ($body['destination']['branch']['name'] ?? $repository->defaultBranch);
            if ($repository->resolve($head) === null) {
                return $this->error('Source branch not found', 400);
            }
            $number = $repository->nextPull++;
            $repository->pulls[$number] = ['id' => $number, 'number' => $number, 'title' => $body['title'] ?? '', 'body' => $body['description'] ?? '', 'head' => $head, 'base' => $base, 'state' => 'open'];

            return Response::json($this->pullOf($repository, $repository->pulls[$number]), 201);
        });

        $this->on('GET', $prefix . '/pullrequests', function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            if ($repository === null) {
                return $this->notFound();
            }
            $branch = self::filter((string) $this->request->input('q', ''), 'source\.branch\.name');
            $branch ??= (preg_match('/^source\.branch\.name="(.*)"$/', (string) $this->request->input('q', ''), $m) === 1 ? $m[1] : null);
            $values = [];
            foreach ($repository->pulls as $pull) {
                if ($pull['state'] === 'open' && ($branch === null || $pull['head'] === $branch)) {
                    $values[] = $this->pullOf($repository, $pull);
                }
            }

            return $this->paged($values);
        });

        $this->on('GET', $prefix . '/pullrequests/{id}', function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            $pull = $repository?->pulls[(int) $p['id']] ?? null;

            return $repository === null || $pull === null ? $this->error('Pull request not found', 404) : Response::json($this->pullOf($repository, $pull));
        });

        $this->on('GET', $prefix . '/pullrequests/{id}/diffstat', function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            $pull = $repository?->pulls[(int) $p['id']] ?? null;
            if ($repository === null || $pull === null) {
                return $this->error('Pull request not found', 404);
            }
            $values = array_map(static fn (array $c): array => [
                'type' => 'diffstat',
                'status' => match ($c['status'][0] ?? '') {
                    'A' => 'added',
                    'D' => 'removed',
                    default => 'modified',
                },
                'lines_added' => 1,
                'lines_removed' => 0,
                'old' => ($c['status'][0] ?? '') === 'A' ? null : ['path' => $c['old'], 'type' => 'commit_file'],
                'new' => ($c['status'][0] ?? '') === 'D' ? null : ['path' => $c['path'], 'type' => 'commit_file'],
            ], Git::changes($repository->path, 'refs/heads/' . $pull['base'], 'refs/heads/' . $pull['head']));

            return $this->paged($values, 500);
        });

        $this->on('POST', $prefix . '/pullrequests/{id}/comments', function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            if ($repository === null || !isset($repository->pulls[(int) $p['id']])) {
                return $this->error('Pull request not found', 404);
            }
            $id = $this->world->id();
            $repository->comments[$id] = ['id' => $id, 'pull' => (int) $p['id'], 'body' => (string) ($this->request->json()['content']['raw'] ?? '')];

            return Response::json($this->commentOf($repository->comments[$id]), 201);
        });

        $this->on('GET', $prefix . '/pullrequests/{id}/comments/{comment}', function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            $comment = $repository?->comments[(int) $p['comment']] ?? null;

            return $comment === null || $comment['pull'] !== (int) $p['id'] ? $this->error('Comment not found', 404) : Response::json($this->commentOf($comment));
        });

        $this->on('PUT', $prefix . '/pullrequests/{id}/comments/{comment}', function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            $comment = $repository?->comments[(int) $p['comment']] ?? null;
            if ($repository === null || $comment === null || $comment['pull'] !== (int) $p['id']) {
                return $this->error('Comment not found', 404);
            }
            $repository->comments[(int) $p['comment']]['body'] = (string) ($this->request->json()['content']['raw'] ?? '');

            return Response::json($this->commentOf($repository->comments[(int) $p['comment']]));
        });

        $this->on('POST', $prefix . '/hooks', function (array $p): Response {
            $repository = $this->find($p['ws'], $p['slug']);
            if ($repository === null) {
                return $this->notFound();
            }
            $body = $this->request->json();
            $id = $this->world->id();
            $uuid = \sprintf('{%08d-3333-4000-8000-%012d}', $id, $id);
            $repository->hooks[$id] = ['id' => $id, 'events' => $body['events'] ?? [], 'url' => $body['url'] ?? '', 'secret' => $body['secret'] ?? ''];

            return Response::json(['type' => 'webhook_subscription', 'uuid' => $uuid, 'url' => $body['url'] ?? '', 'description' => $body['description'] ?? '', 'subject_type' => 'repository', 'active' => true, 'events' => $body['events'] ?? [], 'created_at' => World::TIME], 201);
        });
    }

    /**
     * @param array<string, string> $p
     */
    private function source(array $p): Response
    {
        $repository = $this->find($p['ws'], $p['slug']);
        $sha = $repository?->resolve($p['ref']);
        $path = trim($p['path'], '/');
        if ($repository === null || $sha === null) {
            return $this->error('No such file or directory: ' . $path, 404);
        }
        $blob = $path === '' ? null : Git::blob($repository->path, $sha, $path);
        if ($blob !== null) {
            if ((string) $this->request->input('format', '') === 'meta') {
                return Response::json(['path' => $path, 'type' => 'commit_file', 'size' => \strlen($blob['content']), 'commit' => ['hash' => $sha, 'type' => 'commit'], 'mimetype' => null, 'escaped_path' => $path, 'attributes' => []]);
            }

            return Response::text($blob['content'], 200, 'text/plain');
        }
        $depth = max(1, (int) $this->request->input('max_depth', 1));
        $entries = Git::tree($repository->path, $sha, $path, $depth > 1);
        if ($entries === null) {
            return $this->error('No such file or directory: ' . $path, 404);
        }
        if ((string) $this->request->input('format', '') === 'meta') {
            return Response::json(['path' => $path, 'type' => 'commit_directory', 'commit' => ['hash' => $sha, 'type' => 'commit']]);
        }
        $values = array_map(static fn (array $e): array => [
            'path' => ltrim($path . '/' . $e['path'], '/'),
            'type' => $e['type'] === 'tree' ? 'commit_directory' : 'commit_file',
            'commit' => ['hash' => $sha, 'type' => 'commit'],
        ] + ($e['type'] === 'tree' ? [] : ['size' => $e['size'], 'mimetype' => null, 'escaped_path' => ltrim($path . '/' . $e['path'], '/')]), $entries);

        return $this->paged($values);
    }

    /**
     * @param array<string, mixed> $comment
     * @return array<string, mixed>
     */
    private function commentOf(array $comment): array
    {
        return ['type' => 'pullrequest_comment', 'id' => $comment['id'], 'content' => ['type' => 'rendered', 'raw' => $comment['body'], 'markup' => 'markdown', 'html' => '<p>' . htmlspecialchars((string) $comment['body']) . '</p>'], 'user' => $this->userOf(), 'created_on' => World::TIME, 'updated_on' => World::TIME, 'deleted' => false];
    }
}
