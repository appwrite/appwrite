<?php

namespace Tests\Compat\Vcs\Fixtures;

/**
 * GitLab's REST API (`/api/v4`) for a personal access token.
 */
final class GitLab extends Provider
{
    protected function notFound(): Response
    {
        return Response::json(['message' => '404 Not Found'], 404);
    }

    private function missing(string $what): Response
    {
        return Response::json(['message' => '404 ' . $what . ' Not Found'], 404);
    }

    protected function unauthorized(): Response
    {
        return Response::json(['message' => '401 Unauthorized'], 401);
    }

    private function web(): string
    {
        return $this->request->base;
    }

    /**
     * The project an id segment names: a number or an encoded path.
     */
    private function project(string $id): ?Repository
    {
        if (ctype_digit($id)) {
            return $this->world->repositoryById((int) $id);
        }
        $path = urldecode($id);
        if (!str_contains($path, '/')) {
            return null;
        }
        [$owner, $name] = explode('/', $path, 2);

        return $this->world->repository($owner, $name);
    }

    /**
     * @return array<string, mixed>
     */
    private function namespaceOf(string $owner): array
    {
        $group = $this->world->organizations[strtolower($owner)] ?? null;
        $user = $this->world->user($owner);

        return [
            'id' => $group['id'] ?? $user['id'],
            'name' => $owner,
            'path' => $owner,
            'kind' => $group === null ? 'user' : 'group',
            'full_path' => $owner,
            'parent_id' => null,
            'avatar_url' => null,
            'web_url' => $this->web() . '/' . $owner,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectOf(Repository $repository): array
    {
        return [
            'id' => $repository->id,
            'description' => $repository->description === '' ? null : $repository->description,
            'name' => $repository->name,
            'name_with_namespace' => $repository->owner . ' / ' . $repository->name,
            'path' => $repository->name,
            'path_with_namespace' => $repository->fullName(),
            'created_at' => '2026-01-01T00:00:00.000Z',
            'default_branch' => $repository->empty() ? null : $repository->defaultBranch,
            'http_url_to_repo' => $this->web() . '/' . $repository->fullName() . '.git',
            'web_url' => $this->web() . '/' . $repository->fullName(),
            'visibility' => $repository->private ? 'private' : 'public',
            'last_activity_at' => '2026-01-01T00:00:00.000Z',
            'namespace' => $this->namespaceOf($repository->owner),
            'empty_repo' => $repository->empty(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commitOf(Repository $repository, string $sha): array
    {
        $info = Git::info($repository->path, $sha) ?? ['sha' => $sha, 'tree' => '', 'parents' => [], 'author' => '', 'email' => '', 'message' => ''];

        return [
            'id' => $info['sha'],
            'short_id' => substr($info['sha'], 0, 8),
            'created_at' => '2026-01-01T00:00:00.000+00:00',
            'parent_ids' => $info['parents'],
            'title' => strtok($info['message'], "\n"),
            'message' => $info['message'],
            'author_name' => $info['author'],
            'author_email' => $info['email'],
            'authored_date' => '2026-01-01T00:00:00.000+00:00',
            'committer_name' => $info['author'],
            'committer_email' => $info['email'],
            'committed_date' => '2026-01-01T00:00:00.000+00:00',
            'web_url' => $this->web() . '/' . $repository->fullName() . '/-/commit/' . $info['sha'],
        ];
    }

    /**
     * @param array<string, mixed> $pull
     * @return array<string, mixed>
     */
    private function mergeRequestOf(Repository $repository, array $pull): array
    {
        return [
            'id' => $pull['id'],
            'iid' => $pull['number'],
            'project_id' => $repository->id,
            'title' => $pull['title'],
            'description' => $pull['body'],
            'state' => $pull['state'] === 'open' ? 'opened' : $pull['state'],
            'target_branch' => $pull['base'],
            'source_branch' => $pull['head'],
            'source_project_id' => $repository->id,
            'target_project_id' => $repository->id,
            'sha' => $repository->resolve((string) $pull['head']),
            'patch_id_sha' => $repository->resolve((string) $pull['head']),
            'web_url' => $this->web() . '/' . $repository->fullName() . '/-/merge_requests/' . $pull['number'],
            'created_at' => '2026-01-01T00:00:00.000Z',
        ];
    }

    /**
     * @param array<array<string, mixed>> $items
     * @return array{0: array<array<string, mixed>>, 1: array<string, string>}
     */
    private function page(array $items): array
    {
        $page = max(1, (int) $this->request->input('page', 1));
        $perPage = max(1, (int) $this->request->input('per_page', 20));

        return [\array_slice($items, ($page - 1) * $perPage, $perPage), ['X-Total' => (string) \count($items), 'X-Page' => (string) $page, 'X-Per-Page' => (string) $perPage]];
    }

    protected function routes(): void
    {
        $this->on('GET', '/api/v4/user', fn (): Response => Response::json($this->userOf((string) $this->world->config['user'])));

        $this->on('GET', '/api/v4/users', function (): Response {
            $username = (string) $this->request->input('username', '');
            $known = strcasecmp($username, (string) $this->world->config['user']) === 0 || isset($this->world->users[strtolower($username)]);

            return Response::json($known ? [$this->userOf($username)] : []);
        });

        $this->on('POST', '/api/v4/groups', function (): Response {
            $body = $this->request->json();
            $path = (string) ($body['path'] ?? '');
            if ($path === '' || isset($this->world->organizations[strtolower($path)])) {
                return Response::json(['message' => 'Failed to save group {:path=>["has already been taken"]}'], 400);
            }
            $id = $this->world->id();
            $this->world->organizations[strtolower($path)] = ['id' => $id, 'name' => $body['name'] ?? $path];

            return Response::json(['id' => $id, 'name' => $body['name'] ?? $path, 'path' => $path, 'full_path' => $path, 'visibility' => $body['visibility'] ?? 'private', 'web_url' => $this->web() . '/groups/' . $path], 201);
        });

        $this->on('GET', '/api/v4/namespaces', function (): Response {
            $search = (string) $this->request->input('search', '');
            $user = (string) $this->world->config['user'];
            $all = [$this->namespaceOf($user)];
            foreach ($this->world->organizations as $key => $group) {
                $all[] = $this->namespaceOf((string) $key);
            }
            $all = array_values(array_filter($all, static fn (array $n): bool => $search === '' || stripos((string) $n['path'], $search) !== false));
            [$items, $headers] = $this->page($all);

            return Response::json($items, 200, $headers);
        });

        $this->on('POST', '/api/v4/projects', function (): Response {
            $body = $this->request->json();
            $path = (string) ($body['path'] ?? '');
            if ($path === '' || preg_match('/^[A-Za-z0-9_.][A-Za-z0-9_.-]*$/', $path) !== 1) {
                return Response::json(['message' => ['path' => ['can contain only letters, digits, \'_\', \'-\' and \'.\'']]], 400);
            }
            $owner = null;
            foreach ($this->world->organizations as $key => $group) {
                if ((int) $group['id'] === (int) ($body['namespace_id'] ?? 0)) {
                    $owner = (string) $key;
                }
            }
            $owner ??= (string) $this->world->config['user'];
            if ($this->world->repository($owner, $path) !== null) {
                return Response::json(['message' => ['name' => ['has already been taken'], 'path' => ['has already been taken']]], 400);
            }
            $repository = $this->world->create($owner, $path, ($body['visibility'] ?? 'private') === 'private', 'main');

            return Response::json($this->projectOf($repository), 201);
        });

        $this->on('GET', '/api/v4/projects', function (): Response {
            $search = (string) $this->request->input('search', '');
            $all = [];
            foreach ($this->world->repositories as $repository) {
                if ($search === '' || stripos($repository->name, $search) !== false) {
                    $all[] = $this->projectOf($repository);
                }
            }
            [$items, $headers] = $this->page($all);

            return Response::json($items, 200, $headers);
        });

        $this->on('GET', '/api/v4/groups/{group}/projects', function (array $p): Response {
            if (!isset($this->world->organizations[strtolower($p['group'])])) {
                return $this->missing('Group');
            }
            $search = (string) $this->request->input('search', '');
            $all = [];
            foreach ($this->world->repositories as $repository) {
                if (strcasecmp($repository->owner, $p['group']) === 0 && ($search === '' || stripos($repository->name, $search) !== false)) {
                    $all[] = $this->projectOf($repository);
                }
            }
            [$items, $headers] = $this->page($all);

            return Response::json($items, 200, $headers);
        });

        $this->on('GET', '/api/v4/projects/{id}', function (array $p): Response {
            $repository = $this->project($p['id']);

            return $repository === null ? $this->missing('Project') : Response::json($this->projectOf($repository));
        });

        $this->on('DELETE', '/api/v4/projects/{id}', function (array $p): Response {
            $repository = $this->project($p['id']);
            if ($repository === null) {
                return $this->missing('Project');
            }
            $this->world->delete($repository);

            return Response::json(['message' => '202 Accepted'], 202);
        });

        $this->on('GET', '/api/v4/projects/{id}/languages', function (array $p): Response {
            $repository = $this->project($p['id']);
            if ($repository === null) {
                return $this->missing('Project');
            }
            $languages = $repository->languages();
            $total = array_sum($languages);
            $shares = [];
            foreach ($languages as $name => $bytes) {
                $shares[$name] = round($bytes * 100 / max(1, $total), 2);
            }

            return Response::json($shares === [] ? new \stdClass() : $shares);
        });

        $this->on('GET', '/api/v4/projects/{id}/repository/tree', function (array $p): Response {
            $repository = $this->project($p['id']);
            if ($repository === null) {
                return $this->missing('Project');
            }
            $ref = (string) $this->request->input('ref', $repository->defaultBranch);
            $sha = $repository->resolve($ref);
            if ($sha === null) {
                return $repository->empty() ? Response::json([]) : $this->missing('Tree');
            }
            $path = trim((string) $this->request->input('path', ''), '/');
            $recursive = (string) $this->request->input('recursive', '') === 'true';
            $tree = Git::tree($repository->path, $sha, $path, $recursive);
            if ($tree === null) {
                return $this->missing('Tree');
            }
            $entries = array_map(static fn (array $e): array => [
                'id' => $e['sha'],
                'name' => basename($e['path']),
                'type' => $e['type'],
                'path' => ltrim($path . '/' . $e['path'], '/'),
                'mode' => $e['mode'],
            ], $tree);
            [$items, $headers] = $this->page($entries);

            return Response::json($items, 200, $headers);
        });

        $this->on('GET', '/api/v4/projects/{id}/repository/files/{file}', function (array $p): Response {
            $repository = $this->project($p['id']);
            $sha = $repository?->resolve((string) $this->request->input('ref', 'HEAD') === 'HEAD' ? $repository->defaultBranch : (string) $this->request->input('ref'));
            $path = urldecode($p['file']);
            $blob = $repository !== null && $sha !== null ? Git::blob($repository->path, $sha, $path) : null;
            if ($blob === null) {
                return $this->missing('File');
            }

            return Response::json([
                'file_name' => basename($path),
                'file_path' => $path,
                'size' => \strlen($blob['content']),
                'encoding' => 'base64',
                'content_sha256' => hash('sha256', $blob['content']),
                'ref' => (string) $this->request->input('ref', 'HEAD'),
                'blob_id' => $blob['sha'],
                'commit_id' => $sha,
                'last_commit_id' => $sha,
                'content' => base64_encode($blob['content']),
            ]);
        });

        $this->on('POST', '/api/v4/projects/{id}/repository/files/{file}', function (array $p): Response {
            $repository = $this->project($p['id']);
            if ($repository === null) {
                return $this->missing('Project');
            }
            $body = $this->request->json();
            $branch = (string) ($body['branch'] ?? '');
            $path = urldecode($p['file']);
            $tip = $repository->resolve($branch);
            if ($tip === null && !$repository->empty()) {
                return Response::json(['message' => 'You can only create or edit files when you are on a branch'], 400);
            }
            if ($tip !== null && Git::blob($repository->path, $tip, $path) !== null) {
                return Response::json(['message' => 'A file with this name already exists'], 400);
            }
            $content = ($body['encoding'] ?? '') === 'base64' ? (string) base64_decode((string) ($body['content'] ?? '')) : (string) ($body['content'] ?? '');
            $wasEmpty = $repository->empty();
            $sha = Git::commit($repository->path, $branch, [$path => $content], (string) ($body['commit_message'] ?? ''), ['name' => (string) ($body['author_name'] ?? 'root'), 'email' => (string) ($body['author_email'] ?? 'root@example.com')]);
            if ($wasEmpty) {
                $repository->defaultBranch = $branch;
            }
            Hooks::push($this->world, $this->server, $repository, $branch, $tip ?? str_repeat('0', 40), (string) $sha, $this->request->base);

            return Response::json(['file_path' => $path, 'branch' => $branch], 201);
        });

        $this->on('GET', '/api/v4/projects/{id}/repository/branches', function (array $p): Response {
            $repository = $this->project($p['id']);
            if ($repository === null) {
                return $this->missing('Project');
            }
            $all = [];
            foreach (Git::branches($repository->path) as $name => $sha) {
                $all[] = ['name' => $name, 'commit' => $this->commitOf($repository, $sha), 'merged' => false, 'protected' => false, 'default' => $name === $repository->defaultBranch, 'web_url' => $this->web() . '/' . $repository->fullName() . '/-/tree/' . $name];
            }
            [$items, $headers] = $this->page($all);

            return Response::json($items, 200, $headers);
        });

        $this->on('POST', '/api/v4/projects/{id}/repository/branches', function (array $p): Response {
            $repository = $this->project($p['id']);
            if ($repository === null) {
                return $this->missing('Project');
            }
            $body = $this->request->json();
            $sha = $repository->resolve((string) ($body['ref'] ?? ''));
            if ($sha === null) {
                return Response::json(['message' => 'Invalid reference name: ' . ($body['ref'] ?? '')], 400);
            }
            if (isset(Git::branches($repository->path)[(string) ($body['branch'] ?? '')])) {
                return Response::json(['message' => 'Branch already exists'], 400);
            }
            Git::setRef($repository->path, 'refs/heads/' . ($body['branch'] ?? ''), $sha);

            return Response::json(['name' => $body['branch'] ?? '', 'commit' => $this->commitOf($repository, $sha), 'merged' => false, 'protected' => false, 'default' => false], 201);
        });

        $this->on('GET', '/api/v4/projects/{id}/repository/tags', function (array $p): Response {
            $repository = $this->project($p['id']);
            if ($repository === null) {
                return $this->missing('Project');
            }
            $all = [];
            foreach (Git::tags($repository->path) as $name => $sha) {
                $all[] = ['name' => $name, 'message' => '', 'target' => $sha, 'commit' => $this->commitOf($repository, $sha), 'protected' => false];
            }
            [$items, $headers] = $this->page($all);

            return Response::json($items, 200, $headers);
        });

        $this->on('POST', '/api/v4/projects/{id}/repository/tags', function (array $p): Response {
            $repository = $this->project($p['id']);
            if ($repository === null) {
                return $this->missing('Project');
            }
            $body = $this->request->json();
            $name = (string) ($body['tag_name'] ?? '');
            $sha = $repository->resolve((string) ($body['ref'] ?? ''));
            if ($sha === null) {
                return Response::json(['message' => 'Target ' . ($body['ref'] ?? '') . ' is invalid'], 400);
            }
            if (isset(Git::tags($repository->path)[$name])) {
                return Response::json(['message' => 'Tag ' . $name . ' already exists'], 400);
            }
            $message = (string) ($body['message'] ?? '');
            $target = $message === '' ? $sha : (string) Git::annotatedTag($repository->path, $name, $sha, $message, $this->author());
            if ($message === '') {
                Git::setRef($repository->path, 'refs/tags/' . $name, $sha);
            }

            return Response::json(['name' => $name, 'message' => $message, 'target' => $target, 'commit' => $this->commitOf($repository, $sha), 'protected' => false], 201);
        });

        $this->on('GET', '/api/v4/projects/{id}/repository/commits', function (array $p): Response {
            $repository = $this->project($p['id']);
            if ($repository === null) {
                return $this->missing('Project');
            }
            $sha = $repository->resolve((string) $this->request->input('ref_name', $repository->defaultBranch));
            if ($sha === null) {
                return Response::json([]);
            }
            $all = array_map(fn (string $s): array => $this->commitOf($repository, $s), Git::log($repository->path, $sha));
            [$items, $headers] = $this->page($all);

            return Response::json($items, 200, $headers);
        });

        $this->on('GET', '/api/v4/projects/{id}/repository/commits/{sha}', function (array $p): Response {
            $repository = $this->project($p['id']);
            $sha = $repository?->resolve(urldecode($p['sha']));

            return $repository === null || $sha === null ? $this->missing('Commit') : Response::json($this->commitOf($repository, $sha));
        });

        $this->on('GET', '/api/v4/projects/{id}/repository/commits/{sha}/statuses', function (array $p): Response {
            $repository = $this->project($p['id']);
            $sha = $repository?->resolve(urldecode($p['sha']));
            if ($repository === null || $sha === null) {
                return $this->missing('Commit');
            }

            return Response::json($repository->statuses[$sha] ?? []);
        });

        $this->on('POST', '/api/v4/projects/{id}/statuses/{sha}', function (array $p): Response {
            $repository = $this->project($p['id']);
            $sha = urldecode($p['sha']);
            if ($repository === null || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1 || Git::resolve($repository->path, $sha) === null) {
                return $this->missing('References for commit');
            }
            $body = $this->request->json();
            $status = [
                'id' => $this->world->id(),
                'sha' => $sha,
                'ref' => $repository->defaultBranch,
                'status' => $body['state'] ?? '',
                'name' => $body['name'] ?? 'default',
                'target_url' => $body['target_url'] ?? null,
                'description' => $body['description'] ?? null,
                'created_at' => '2026-01-01T00:00:00.000Z',
            ];
            $repository->statuses[$sha][] = $status;

            return Response::json($status, 201);
        });

        $this->on('POST', '/api/v4/projects/{id}/merge_requests', function (array $p): Response {
            $repository = $this->project($p['id']);
            if ($repository === null) {
                return $this->missing('Project');
            }
            $body = $this->request->json();
            $number = $repository->nextPull++;
            $repository->pulls[$number] = ['id' => $this->world->id(), 'number' => $number, 'title' => $body['title'] ?? '', 'body' => $body['description'] ?? '', 'head' => $body['source_branch'] ?? '', 'base' => $body['target_branch'] ?? '', 'state' => 'open'];
            $merge = $this->mergeRequestOf($repository, $repository->pulls[$number]);
            $payload = [
                'object_kind' => 'merge_request',
                'event_type' => 'merge_request',
                'user' => ['name' => $this->world->config['user'], 'username' => $this->world->config['user'], 'avatar_url' => 'https://www.gravatar.com/avatar/fixture'],
                'project' => ['id' => $repository->id, 'name' => $repository->name, 'namespace' => $repository->owner, 'path_with_namespace' => $repository->fullName(), 'web_url' => $this->web() . '/' . $repository->fullName()],
                'object_attributes' => $merge + ['action' => 'open', 'url' => $merge['web_url'], 'last_commit' => ['id' => $merge['sha'], 'url' => $this->web() . '/' . $repository->fullName() . '/-/commit/' . $merge['sha']]],
            ];
            $this->server->deliver($this->world, $repository, 'pull_request', ['X-Gitlab-Event' => 'Merge Request Hook', 'X-Gitlab-Token' => '{secret}'], (string) json_encode($payload, JSON_UNESCAPED_SLASHES));

            return Response::json($merge, 201);
        });

        $this->on('GET', '/api/v4/projects/{id}/merge_requests', function (array $p): Response {
            $repository = $this->project($p['id']);
            if ($repository === null) {
                return $this->missing('Project');
            }
            $state = (string) $this->request->input('state', 'all');
            $source = (string) $this->request->input('source_branch', '');
            $out = [];
            foreach (array_reverse($repository->pulls, true) as $pull) {
                if (($state === 'opened' && $pull['state'] !== 'open') || ($source !== '' && $pull['head'] !== $source)) {
                    continue;
                }
                $out[] = $this->mergeRequestOf($repository, $pull);
            }

            return Response::json($out);
        });

        $this->on('GET', '/api/v4/projects/{id}/merge_requests/{iid}', function (array $p): Response {
            $repository = $this->project($p['id']);
            $pull = $repository?->pulls[(int) $p['iid']] ?? null;

            return $repository === null || $pull === null ? $this->missing('Merge Request') : Response::json($this->mergeRequestOf($repository, $pull));
        });

        $this->on('GET', '/api/v4/projects/{id}/merge_requests/{iid}/diffs', function (array $p): Response {
            $repository = $this->project($p['id']);
            $pull = $repository?->pulls[(int) $p['iid']] ?? null;
            if ($repository === null || $pull === null) {
                return $this->missing('Merge Request');
            }
            $all = array_map(static fn (array $c): array => [
                'old_path' => $c['old'],
                'new_path' => $c['path'],
                'a_mode' => '100644',
                'b_mode' => '100644',
                'new_file' => ($c['status'][0] ?? '') === 'A',
                'renamed_file' => false,
                'deleted_file' => ($c['status'][0] ?? '') === 'D',
                'diff' => '',
            ], Git::changes($repository->path, 'refs/heads/' . $pull['base'], 'refs/heads/' . $pull['head']));
            [$items, $headers] = $this->page($all);

            return Response::json($items, 200, $headers);
        });

        $this->on('POST', '/api/v4/projects/{id}/merge_requests/{iid}/notes', function (array $p): Response {
            $repository = $this->project($p['id']);
            if ($repository === null || !isset($repository->pulls[(int) $p['iid']])) {
                return $this->missing('Merge Request');
            }
            $id = $this->world->id();
            $repository->comments[$id] = ['id' => $id, 'pull' => (int) $p['iid'], 'body' => (string) ($this->request->json()['body'] ?? '')];

            return Response::json($this->noteOf($repository->comments[$id]), 201);
        });

        $this->on('GET', '/api/v4/projects/{id}/merge_requests/{iid}/notes/{note}', function (array $p): Response {
            $repository = $this->project($p['id']);
            $note = $repository?->comments[(int) $p['note']] ?? null;
            if ($repository === null || $note === null || $note['pull'] !== (int) $p['iid']) {
                return $this->missing('Note');
            }

            return Response::json($this->noteOf($note));
        });

        $this->on('PUT', '/api/v4/projects/{id}/merge_requests/{iid}/notes/{note}', function (array $p): Response {
            $repository = $this->project($p['id']);
            $note = $repository?->comments[(int) $p['note']] ?? null;
            if ($repository === null || $note === null || $note['pull'] !== (int) $p['iid']) {
                return $this->missing('Note');
            }
            $repository->comments[(int) $p['note']]['body'] = (string) ($this->request->json()['body'] ?? '');

            return Response::json($this->noteOf($repository->comments[(int) $p['note']]));
        });

        $this->on('POST', '/api/v4/projects/{id}/hooks', function (array $p): Response {
            $repository = $this->project($p['id']);
            if ($repository === null) {
                return $this->missing('Project');
            }
            $body = $this->request->json();
            $id = $this->world->id();
            $events = [];
            if (!empty($body['push_events'])) {
                $events[] = 'push';
            }
            if (!empty($body['merge_requests_events'])) {
                $events[] = 'pull_request';
            }
            $repository->hooks[$id] = ['id' => $id, 'events' => $events, 'url' => $body['url'] ?? '', 'secret' => $body['token'] ?? ''];

            return Response::json(['id' => $id, 'url' => $body['url'] ?? '', 'project_id' => $repository->id, 'push_events' => \in_array('push', $events, true), 'merge_requests_events' => \in_array('pull_request', $events, true), 'enable_ssl_verification' => false, 'created_at' => '2026-01-01T00:00:00.000Z'], 201);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function userOf(string $username): array
    {
        $user = $this->world->user($username);

        return ['id' => $user['id'], 'username' => $username, 'name' => ucfirst($username), 'state' => 'active', 'avatar_url' => 'https://www.gravatar.com/avatar/fixture', 'web_url' => $this->web() . '/' . $username];
    }

    /**
     * @param array<string, mixed> $note
     * @return array<string, mixed>
     */
    private function noteOf(array $note): array
    {
        return ['id' => $note['id'], 'type' => null, 'body' => $note['body'], 'author' => $this->userOf((string) $this->world->config['user']), 'created_at' => '2026-01-01T00:00:00.000Z', 'system' => false, 'noteable_iid' => $note['pull'], 'noteable_type' => 'MergeRequest'];
    }
}
