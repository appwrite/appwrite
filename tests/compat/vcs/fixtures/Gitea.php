<?php

namespace Tests\Compat\Vcs\Fixtures;

/**
 * The API Gitea serves (`/api/v1`), and its forks: Forgejo answers alike,
 * Gogs lacks pull requests, statuses, languages and branch and tag writes.
 */
final class Gitea extends Provider
{
    private function gogs(): bool
    {
        return $this->world->provider === 'gogs';
    }

    protected function notFound(): Response
    {
        return Response::json(['errors' => [], 'message' => 'The target couldn\'t be found.', 'url' => $this->request->base . '/api/swagger'], 404);
    }

    protected function unauthorized(): Response
    {
        return Response::json(['message' => 'token is required', 'url' => $this->request->base . '/api/swagger'], 401);
    }

    private function invalid(string $message, int $status = 422): Response
    {
        return Response::json(['message' => $message, 'url' => $this->request->base . '/api/swagger'], $status);
    }

    /**
     * @return array<string, mixed>
     */
    public static function userOf(World $world, string $login, string $base): array
    {
        $user = $world->user($login);
        $avatar = 'https://secure.gravatar.com/avatar/' . md5($login . '@example.com') . '?d=identicon';

        return [
            'id' => $user['id'],
            'login' => $login,
            'login_name' => '',
            'full_name' => '',
            'email' => $login . '@example.com',
            'avatar_url' => $avatar,
            'language' => '',
            'is_admin' => false,
            'created' => World::TIME,
            'username' => $login,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function repositoryOf(World $world, Repository $repository, string $base): array
    {
        $owner = $world->organizations[strtolower($repository->owner)] ?? null;

        return [
            'id' => $repository->id,
            'owner' => $owner === null ? self::userOf($world, $repository->owner, $base) : self::organizationOf($world, $repository->owner),
            'name' => $repository->name,
            'full_name' => $repository->fullName(),
            'description' => $repository->description,
            'empty' => $repository->empty(),
            'private' => $repository->private,
            'fork' => false,
            'mirror' => false,
            'size' => 0,
            'html_url' => $base . '/' . $repository->fullName(),
            'url' => $base . '/api/v1/repos/' . $repository->fullName(),
            'clone_url' => $base . '/' . $repository->fullName() . '.git',
            'default_branch' => $repository->defaultBranch,
            'created_at' => World::TIME,
            'updated_at' => World::TIME,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function organizationOf(World $world, string $name): array
    {
        $organization = $world->organizations[strtolower($name)] ?? ['id' => 0, 'name' => $name];

        return [
            'id' => $organization['id'],
            'name' => $organization['name'],
            'full_name' => '',
            'avatar_url' => 'https://secure.gravatar.com/avatar/' . md5((string) $organization['name']) . '?d=identicon',
            'description' => '',
            'website' => '',
            'location' => '',
            'visibility' => 'public',
            'username' => $organization['name'],
            'login' => $organization['name'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commitOf(Repository $repository, string $sha): array
    {
        $info = Git::info($repository->path, $sha) ?? ['sha' => $sha, 'tree' => '', 'parents' => [], 'author' => '', 'email' => '', 'message' => ''];
        $person = ['name' => $info['author'], 'email' => $info['email'], 'date' => World::TIME];
        $author = self::userOf($this->world, (string) $this->world->config['user'], $this->request->base);

        return [
            'url' => $this->request->base . '/api/v1/repos/' . $repository->fullName() . '/git/commits/' . $info['sha'],
            'sha' => $info['sha'],
            'created' => World::TIME,
            'html_url' => $this->request->base . '/' . $repository->fullName() . '/commit/' . $info['sha'],
            'commit' => [
                'url' => $this->request->base . '/api/v1/repos/' . $repository->fullName() . '/git/commits/' . $info['sha'],
                'author' => $person,
                'committer' => $person,
                'message' => $info['message'] . "\n",
                'tree' => ['sha' => $info['tree']],
            ],
            'author' => $author,
            'committer' => $author,
            'parents' => array_map(static fn (string $parent): array => ['sha' => $parent], $info['parents']),
        ];
    }

    /**
     * @param array<string, mixed> $pull
     * @return array<string, mixed>
     */
    private function pullOf(Repository $repository, array $pull): array
    {
        $repo = self::repositoryOf($this->world, $repository, $this->request->base);
        $side = fn (string $branch): array => [
            'label' => $branch,
            'ref' => $branch,
            'sha' => (string) ($repository->resolve($branch) ?? ''),
            'repo_id' => $repository->id,
            'repo' => $repo,
        ];

        return [
            'id' => $pull['id'],
            'url' => $this->request->base . '/' . $repository->fullName() . '/pulls/' . $pull['number'],
            'number' => $pull['number'],
            'user' => self::userOf($this->world, (string) $this->world->config['user'], $this->request->base),
            'title' => $pull['title'],
            'body' => $pull['body'],
            'state' => $pull['state'],
            'html_url' => $this->request->base . '/' . $repository->fullName() . '/pulls/' . $pull['number'],
            'head' => $side((string) $pull['head']),
            'base' => $side((string) $pull['base']),
            'merged' => false,
            'created_at' => World::TIME,
            'updated_at' => World::TIME,
        ];
    }

    private function find(string $owner, string $name): ?Repository
    {
        return $this->world->repository($owner, $name);
    }

    /**
     * @param array<array<string, mixed>> $items
     * @return array<array<string, mixed>>
     */
    private function page(array $items, int $default = 30): array
    {
        $page = max(1, (int) $this->request->input('page', 1));
        $limit = max(1, (int) $this->request->input('limit', $default));

        return \array_slice($items, ($page - 1) * $limit, $limit);
    }

    /**
     * Creates a repository in an organization (Gogs: with a README on
     * master).
     */
    private function create(string $org): Response
    {
        $body = $this->request->json();
        $name = (string) ($body['name'] ?? '');
        if ($name === '' || preg_match('/^[A-Za-z0-9_.-]+$/', $name) !== 1) {
            return $this->invalid('[Name]: Repository name should contain only alphanumeric, dash (\'-\'), underscore (\'_\') and dot (\'.\') characters');
        }
        if ($this->find($org, $name) !== null) {
            return $this->invalid('The repository with the same name already exists.', 409);
        }
        if (!isset($this->world->organizations[strtolower($org)])) {
            return $this->notFound();
        }
        $branch = $this->gogs() ? 'master' : 'main';
        $repository = $this->world->create($org, $name, (bool) ($body['private'] ?? false), $branch);
        if (!empty($body['auto_init'])) {
            Git::commit($repository->path, $branch, ['README.md' => "# {$name}\n\n"], 'Initial commit', $this->author());
        }

        return Response::json(self::repositoryOf($this->world, $repository, $this->request->base), 201);
    }

    private function createOrganization(): Response
    {
        $body = $this->request->json();
        $name = (string) ($body['username'] ?? '');
        if ($name === '' || isset($this->world->organizations[strtolower($name)])) {
            return $this->invalid('user already exists [name: ' . $name . ']');
        }
        $this->world->organizations[strtolower($name)] = ['id' => $this->world->id(), 'name' => $name];

        return Response::json(self::organizationOf($this->world, $name), 201);
    }

    protected function routes(): void
    {
        $this->on('GET', '/api/v1/user', fn (): Response => Response::json(self::userOf($this->world, (string) $this->world->config['user'], $this->request->base)));

        $this->on('GET', '/api/v1/users/{login}', function (array $p): Response {
            $known = strcasecmp($p['login'], (string) $this->world->config['user']) === 0 || isset($this->world->users[strtolower($p['login'])]);

            return $known ? Response::json(self::userOf($this->world, $p['login'], $this->request->base)) : $this->notFound();
        });

        if ($this->gogs()) {
            $this->on('POST', '/api/v1/user/orgs', fn (): Response => $this->createOrganization());
            $this->on('POST', '/api/v1/org/{org}/repos', fn (array $p): Response => $this->create($p['org']));
            $this->on('GET', '/api/v1/orgs/{org}/repos', function (array $p): Response {
                $out = [];
                foreach ($this->world->repositories as $repository) {
                    if (strcasecmp($repository->owner, $p['org']) === 0) {
                        $out[] = self::repositoryOf($this->world, $repository, $this->request->base);
                    }
                }

                return Response::json($out);
            });
        } else {
            $this->on('POST', '/api/v1/orgs', fn (): Response => $this->createOrganization());
            $this->on('POST', '/api/v1/orgs/{org}/repos', fn (array $p): Response => $this->create($p['org']));
            $this->on('GET', '/api/v1/repositories/{id}', function (array $p): Response {
                $repository = $this->world->repositoryById((int) $p['id']);

                return $repository === null ? $this->notFound() : Response::json(self::repositoryOf($this->world, $repository, $this->request->base));
            });
        }

        $this->on('GET', '/api/v1/repos/search', function (): Response {
            $q = (string) $this->request->input('q', '');
            $out = [];
            foreach ($this->world->repositories as $repository) {
                if ($q !== '' && !$this->matches($repository->name, $q)) {
                    continue;
                }
                $out[] = self::repositoryOf($this->world, $repository, $this->request->base);
            }

            return Response::json(['ok' => true, 'data' => $this->page($out)]);
        });

        $this->on('GET', '/api/v1/repos/{owner}/{repo}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);

            return $repository === null ? $this->notFound() : Response::json(self::repositoryOf($this->world, $repository, $this->request->base));
        });

        $this->on('DELETE', '/api/v1/repos/{owner}/{repo}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $this->world->delete($repository);

            return Response::empty();
        });

        $this->on('GET', '/api/v1/repos/{owner}/{repo}/git/trees/{sha}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $sha = $repository?->resolve($p['sha']);
            if ($repository === null || $sha === null) {
                return $this->notFound();
            }
            $recursive = !$this->gogs() && (string) $this->request->input('recursive', '') !== '';
            $tree = array_map(fn (array $e): array => [
                'path' => $e['path'],
                'mode' => $e['mode'],
                'type' => $e['type'],
                'size' => $e['size'],
                'sha' => $e['sha'],
                'url' => $this->request->base . '/api/v1/repos/' . $repository->fullName() . '/git/' . ($e['type'] === 'tree' ? 'trees' : 'blobs') . '/' . $e['sha'],
            ], Git::tree($repository->path, $sha, '', $recursive) ?? []);

            return Response::json(['sha' => $sha, 'url' => $this->request->base . '/api/v1/repos/' . $repository->fullName() . '/git/trees/' . $sha, 'tree' => $tree, 'truncated' => false, 'page' => 1, 'total_count' => \count($tree)]);
        });

        $write = function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $body = $this->request->json();
            $branch = (string) ($body['branch'] ?? $repository->defaultBranch);
            $tip = $repository->resolve($branch);
            if ($tip === null && !$repository->empty()) {
                return $this->invalid('branch does not exist [name: ' . $branch . ']', 404);
            }
            if ($tip !== null && Git::blob($repository->path, $tip, $p['path']) !== null) {
                return $this->invalid('repository file already exists [path: ' . $p['path'] . ']');
            }
            $sha = (string) Git::commit($repository->path, $branch, [$p['path'] => (string) base64_decode((string) ($body['content'] ?? ''))], (string) ($body['message'] ?? ''), $this->author());
            $blob = Git::blob($repository->path, $sha, $p['path']);
            Hooks::push($this->world, $this->server, $repository, $branch, $tip ?? str_repeat('0', 40), $sha, $this->request->base);

            return Response::json([
                'content' => ['name' => basename($p['path']), 'path' => $p['path'], 'sha' => $blob['sha'] ?? '', 'type' => 'file', 'size' => \strlen($blob['content'] ?? '')],
                'commit' => ['sha' => $sha, 'html_url' => $this->request->base . '/' . $repository->fullName() . '/commit/' . $sha, 'message' => ($body['message'] ?? '') . "\n"],
            ], 201);
        };
        $this->on($this->gogs() ? 'PUT' : 'POST', '/api/v1/repos/{owner}/{repo}/contents/{path*}', $write);

        $this->on('GET', '/api/v1/repos/{owner}/{repo}/contents', fn (array $p): Response => $this->contents($p + ['path' => '']));
        $this->on('GET', '/api/v1/repos/{owner}/{repo}/contents/{path*}', fn (array $p): Response => $this->contents($p));

        $this->on('GET', '/api/v1/repos/{owner}/{repo}/branches', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $out = [];
            foreach (Git::branches($repository->path) as $name => $sha) {
                $out[] = ['name' => $name, 'commit' => ['id' => $sha, 'message' => (Git::info($repository->path, $sha)['message'] ?? '') . "\n", 'timestamp' => World::TIME], 'protected' => false];
            }

            return Response::json($this->gogs() ? $out : $this->page($out));
        });

        $this->on('GET', '/api/v1/repos/{owner}/{repo}/tags', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $out = [];
            foreach (Git::tags($repository->path) as $name => $sha) {
                $out[] = ['name' => $name, 'message' => '', 'id' => $sha, 'commit' => ['sha' => $sha, 'url' => $this->request->base . '/api/v1/repos/' . $repository->fullName() . '/git/commits/' . $sha]];
            }

            return Response::json($this->gogs() ? $out : $this->page($out));
        });

        $this->on('GET', '/api/v1/repos/{owner}/{repo}/commits', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            if ($repository->empty()) {
                return $this->invalid('Git Repository is empty.', 409);
            }
            $ref = $this->gogs() ? $repository->defaultBranch : (string) $this->request->input('sha', $repository->defaultBranch);
            $sha = $repository->resolve($ref);
            if ($sha === null) {
                return $this->notFound();
            }

            return Response::json($this->page(array_map(fn (string $s): array => $this->commitOf($repository, $s), Git::log($repository->path, $sha))));
        });

        $this->on('GET', $this->gogs() ? '/api/v1/repos/{owner}/{repo}/commits/{sha}' : '/api/v1/repos/{owner}/{repo}/git/commits/{sha}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $sha = $repository !== null && preg_match('/^[0-9a-f]{4,40}$/', $p['sha']) === 1 ? Git::resolve($repository->path, $p['sha']) : null;
            if ($repository === null || $sha === null) {
                return $this->notFound();
            }

            return Response::json($this->commitOf($repository, $sha));
        });

        $this->on('POST', '/api/v1/repos/{owner}/{repo}/hooks', fn (array $p): Response => $this->hook($p));

        if (!$this->gogs()) {
            $this->routesBeyondGogs();
        }
    }

    /**
     * Pull requests, statuses, languages, branches, tags and comments: what
     * Gogs' API lacks.
     */
    private function routesBeyondGogs(): void
    {
        $this->on('GET', '/api/v1/repos/{owner}/{repo}/languages', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $languages = $repository->languages();

            return Response::json($languages === [] ? new \stdClass() : $languages);
        });

        $this->on('POST', '/api/v1/repos/{owner}/{repo}/branches', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $body = $this->request->json();
            $sha = $repository->resolve((string) ($body['old_branch_name'] ?? $repository->defaultBranch));
            $name = (string) ($body['new_branch_name'] ?? '');
            if ($sha === null) {
                return $this->notFound();
            }
            if (isset(Git::branches($repository->path)[$name])) {
                return $this->invalid('The branch already exists.', 409);
            }
            Git::setRef($repository->path, 'refs/heads/' . $name, $sha);

            return Response::json(['name' => $name, 'commit' => ['id' => $sha, 'timestamp' => World::TIME], 'protected' => false], 201);
        });

        $this->on('POST', '/api/v1/repos/{owner}/{repo}/tags', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $body = $this->request->json();
            $name = (string) ($body['tag_name'] ?? '');
            $sha = $repository->resolve((string) ($body['target'] ?? $repository->defaultBranch));
            if ($sha === null) {
                return $this->notFound();
            }
            if (isset(Git::tags($repository->path)[$name])) {
                return $this->invalid('tag already exists', 409);
            }
            $message = (string) ($body['message'] ?? '');
            $id = $message === '' ? $sha : (string) Git::annotatedTag($repository->path, $name, $sha, $message, $this->author());
            if ($message === '') {
                Git::setRef($repository->path, 'refs/tags/' . $name, $sha);
            }

            return Response::json(['name' => $name, 'message' => $message, 'id' => $id, 'commit' => ['sha' => $sha, 'url' => $this->request->base . '/api/v1/repos/' . $repository->fullName() . '/git/commits/' . $sha, 'created' => World::TIME]], 201);
        });

        $this->on('POST', '/api/v1/repos/{owner}/{repo}/statuses/{sha}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null || preg_match('/^[0-9a-f]{40}$/', $p['sha']) !== 1 || Git::resolve($repository->path, $p['sha']) === null) {
                return $this->notFound();
            }
            $body = $this->request->json();
            $status = ['id' => $this->world->id(), 'status' => $body['state'] ?? '', 'target_url' => $body['target_url'] ?? '', 'description' => $body['description'] ?? '', 'context' => $body['context'] ?? '', 'created_at' => World::TIME, 'updated_at' => World::TIME];
            $repository->statuses[$p['sha']][] = $status;

            return Response::json($status, 201);
        });

        $this->on('GET', '/api/v1/repos/{owner}/{repo}/commits/{sha}/statuses', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }

            return Response::json(array_reverse($repository->statuses[$p['sha']] ?? []));
        });

        $this->on('POST', '/api/v1/repos/{owner}/{repo}/pulls', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $body = $this->request->json();
            if ($repository->resolve((string) ($body['head'] ?? '')) === null || $repository->resolve((string) ($body['base'] ?? '')) === null) {
                return $this->notFound();
            }
            $number = $repository->nextPull++;
            $repository->pulls[$number] = ['id' => $this->world->id(), 'number' => $number, 'title' => $body['title'] ?? '', 'body' => $body['body'] ?? '', 'head' => $body['head'], 'base' => $body['base'], 'state' => 'open'];
            $pull = $this->pullOf($repository, $repository->pulls[$number]);
            $payload = [
                'action' => 'opened',
                'number' => $number,
                'pull_request' => $pull,
                'repository' => self::repositoryOf($this->world, $repository, $this->request->base),
                'sender' => self::userOf($this->world, (string) $this->world->config['user'], $this->request->base),
            ];
            $this->server->deliver($this->world, $repository, 'pull_request', Hooks::giteaHeaders($this->world, 'pull_request'), (string) json_encode($payload, JSON_UNESCAPED_SLASHES));

            return Response::json($pull, 201);
        });

        $this->on('GET', '/api/v1/repos/{owner}/{repo}/pulls', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null) {
                return $this->notFound();
            }
            $state = (string) $this->request->input('state', 'open');
            $out = [];
            foreach (array_reverse($repository->pulls, true) as $pull) {
                if ($state === 'all' || $pull['state'] === $state) {
                    $out[] = $this->pullOf($repository, $pull);
                }
            }

            return Response::json($out);
        });

        $this->on('GET', '/api/v1/repos/{owner}/{repo}/pulls/{number}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $pull = $repository?->pulls[(int) $p['number']] ?? null;

            return $repository === null || $pull === null ? $this->notFound() : Response::json($this->pullOf($repository, $pull));
        });

        $this->on('GET', '/api/v1/repos/{owner}/{repo}/pulls/{number}/files', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $pull = $repository?->pulls[(int) $p['number']] ?? null;
            if ($repository === null || $pull === null) {
                return $this->notFound();
            }
            $files = array_map(static fn (array $c): array => [
                'filename' => $c['path'],
                'status' => match ($c['status'][0] ?? '') {
                    'A' => 'added',
                    'D' => 'deleted',
                    default => 'changed',
                },
                'additions' => 1,
                'deletions' => 0,
                'changes' => 1,
            ], Git::changes($repository->path, 'refs/heads/' . $pull['base'], 'refs/heads/' . $pull['head']));

            return Response::json($this->page($files));
        });

        $this->on('POST', '/api/v1/repos/{owner}/{repo}/issues/{number}/comments', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null || !isset($repository->pulls[(int) $p['number']])) {
                return $this->notFound();
            }
            $id = $this->world->id();
            $repository->comments[$id] = ['id' => $id, 'pull' => (int) $p['number'], 'body' => (string) ($this->request->json()['body'] ?? '')];

            return Response::json($this->commentOf($repository, $repository->comments[$id]), 201);
        });

        $this->on('GET', '/api/v1/repos/{owner}/{repo}/issues/comments/{id}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            $comment = $repository?->comments[(int) $p['id']] ?? null;

            return $repository === null || $comment === null ? $this->notFound() : Response::json($this->commentOf($repository, $comment));
        });

        $this->on('PATCH', '/api/v1/repos/{owner}/{repo}/issues/comments/{id}', function (array $p): Response {
            $repository = $this->find($p['owner'], $p['repo']);
            if ($repository === null || !isset($repository->comments[(int) $p['id']])) {
                return $this->notFound();
            }
            $repository->comments[(int) $p['id']]['body'] = (string) ($this->request->json()['body'] ?? '');

            return Response::json($this->commentOf($repository, $repository->comments[(int) $p['id']]));
        });
    }

    /**
     * @param array<string, string> $p
     */
    private function hook(array $p): Response
    {
        $repository = $this->find($p['owner'], $p['repo']);
        if ($repository === null) {
            return $this->notFound();
        }
        $body = $this->request->json();
        $id = $this->world->id();
        $repository->hooks[$id] = ['id' => $id, 'events' => $body['events'] ?? ['push'], 'url' => $body['config']['url'] ?? '', 'secret' => $body['config']['secret'] ?? ''];

        return Response::json(['id' => $id, 'type' => $body['type'] ?? 'gitea', 'config' => ['url' => $body['config']['url'] ?? '', 'content_type' => 'json'], 'events' => $body['events'] ?? [], 'active' => true, 'created_at' => World::TIME, 'updated_at' => World::TIME], 201);
    }

    /**
     * Gitea matches a substring; Gogs a SQL LIKE pattern (`_` any character).
     */
    private function matches(string $name, string $q): bool
    {
        if (!$this->gogs()) {
            return stripos($name, $q) !== false;
        }
        $pattern = '/' . str_replace(['%', '_'], ['.*', '.'], preg_quote($q, '/')) . '/i';

        return preg_match($pattern, $name) === 1;
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
        $sha = $repository->resolve((string) $this->request->input('ref', $repository->defaultBranch));
        if ($sha === null) {
            return $this->notFound();
        }
        $path = trim($p['path'], '/');
        $link = fn (string $file): string => $this->request->base . '/api/v1/repos/' . $repository->fullName() . '/contents/' . $file;
        if ($path !== '') {
            $blob = Git::blob($repository->path, $sha, $path);
            if ($blob !== null) {
                return Response::json([
                    'name' => basename($path),
                    'path' => $path,
                    'sha' => $blob['sha'],
                    'last_commit_sha' => $sha,
                    'type' => 'file',
                    'size' => \strlen($blob['content']),
                    'encoding' => 'base64',
                    'content' => base64_encode($blob['content']),
                    'url' => $link($path),
                ]);
            }
        }
        $tree = Git::tree($repository->path, $sha, $path);
        if ($tree === null) {
            return $this->notFound();
        }

        return Response::json(array_map(static fn (array $e): array => [
            'name' => basename($e['path']),
            'path' => ltrim($path . '/' . $e['path'], '/'),
            'sha' => $e['sha'],
            'last_commit_sha' => $sha,
            'type' => $e['type'] === 'tree' ? 'dir' : 'file',
            'size' => $e['size'],
            'encoding' => null,
            'content' => null,
            'url' => $link(ltrim($path . '/' . $e['path'], '/')),
        ], $tree));
    }

    /**
     * @param array<string, mixed> $comment
     * @return array<string, mixed>
     */
    private function commentOf(Repository $repository, array $comment): array
    {
        return [
            'id' => $comment['id'],
            'html_url' => $this->request->base . '/' . $repository->fullName() . '/pulls/' . $comment['pull'] . '#issuecomment-' . $comment['id'],
            'user' => self::userOf($this->world, (string) $this->world->config['user'], $this->request->base),
            'body' => $comment['body'],
            'created_at' => World::TIME,
            'updated_at' => World::TIME,
        ];
    }
}
