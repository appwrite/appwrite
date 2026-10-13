<?php

namespace Tests\Compat\Vcs\Fixtures;

/**
 * What each provider's world starts with.
 */
final class Defaults
{
    /**
     * @return array<string, mixed>
     */
    public static function config(string $provider): array
    {
        return match ($provider) {
            'github' => ['user' => 'octo-fixture', 'owner' => 'test-owner', 'selection' => 'all'],
            'gitlab' => ['user' => 'root'],
            'bitbucket' => ['user' => 'fixture-user', 'workspace' => 'test-owner'],
            'origin' => ['user' => 'fixture-user', 'owner' => 'test-owner', 'selection' => 'all'],
            default => ['user' => 'utopia'],
        };
    }
}

/**
 * Describes the JWT an Authorization header carries in a form both
 * runtimes' requests compare in: the header and claims with the time
 * claims relative to now, and whether the signature verifies against the
 * key a case configured.
 */
final class Jwt
{
    public static function describe(string $authorization, World $world): string
    {
        if (preg_match('/^Bearer ([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]*)$/', $authorization, $m) !== 1) {
            return $authorization;
        }
        $header = json_decode(self::decode($m[1]), true);
        $claims = json_decode(self::decode($m[2]), true);
        if (!\is_array($header) || !\is_array($claims)) {
            return $authorization;
        }
        $iat = $claims['iat'] ?? null;
        if (\is_int($iat) && abs($iat - time()) < 120) {
            if (\is_int($claims['exp'] ?? null)) {
                $claims['exp'] = '<iat+' . ($claims['exp'] - $iat) . '>';
            }
            $claims['iat'] = '<now>';
        }
        $signature = self::decode($m[3]);
        $input = $m[1] . '.' . $m[2];
        $verified = 'unchecked';
        if (($header['alg'] ?? '') === 'RS256' && \is_string($world->config['rs256'] ?? null)) {
            $verified = openssl_verify($input, $signature, $world->config['rs256'], OPENSSL_ALGO_SHA256) === 1 ? 'valid' : 'invalid';
        }
        if (($header['alg'] ?? '') === 'EdDSA' && \is_string($world->config['ed25519'] ?? null)) {
            $key = self::decode($world->config['ed25519']);
            $verified = \strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES && \strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                && sodium_crypto_sign_verify_detached($signature, $input, $key) ? 'valid' : 'invalid';
        }

        return 'Bearer <jwt ' . json_encode(['header' => $header, 'claims' => $claims, 'signature' => $verified], JSON_UNESCAPED_SLASHES) . '>';
    }

    public static function decode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $data .= str_repeat('=', (4 - \strlen($data) % 4) % 4);

        return (string) base64_decode($data, true);
    }

    /** Whether a bearer token is a JWT (an app credential). */
    public static function is(string $token): bool
    {
        return substr_count($token, '.') === 2;
    }
}

/**
 * Git smart HTTP for the providers that serve clones from their own host.
 */
final class Transport
{
    public function __construct(private readonly World $world, private readonly Server $server)
    {
    }

    public function match(Request $request): ?Response
    {
        $pattern = match ($this->world->provider) {
            'github' => null,
            'origin' => '#^/git/([^/]+)/([^/]+?)(?:\.git)?/(info/refs|git-upload-pack|git-receive-pack)$#',
            default => '#^/([^/]+)/([^/]+?)(?:\.git)?/(info/refs|git-upload-pack|git-receive-pack)$#',
        };
        if ($pattern === null || preg_match($pattern, $request->path, $m) !== 1) {
            return null;
        }
        $repository = $this->world->repository(rawurldecode($m[1]), rawurldecode($m[2]));
        if ($repository === null) {
            return Response::text("Repository not found\n", 404);
        }
        $service = $m[3] === 'info/refs' ? (string) $request->input('service', '') : 'git-' . substr($m[3], 4);
        if (!\in_array($service, ['git-upload-pack', 'git-receive-pack'], true)) {
            return Response::text("Unsupported service\n", 403);
        }
        $authorization = $request->header('authorization');
        if (($repository->private || $service === 'git-receive-pack') && !str_starts_with($authorization, 'Basic ')) {
            return new Response(401, ['WWW-Authenticate' => 'Basic realm="vcs-fixtures"', 'Content-Type' => 'text/plain'], "Authentication required\n");
        }

        $env = [];
        $protocol = $request->header('git-protocol');
        if ($protocol !== '') {
            $env['GIT_PROTOCOL'] = $protocol;
        }
        $command = substr($service, 4);

        if ($m[3] === 'info/refs') {
            [, $out] = Git::run([$command, '--stateless-rpc', '--advertise-refs', $repository->path], '', $env);
            $preamble = str_contains($protocol, 'version=2') ? '' : self::pkt("# service={$service}\n") . '0000';

            return new Response(200, [
                'Content-Type' => "application/x-{$service}-advertisement",
                'Cache-Control' => 'no-cache',
            ], $preamble . $out);
        }

        $before = Git::branches($repository->path);
        [, $out] = Git::run([$command, '--stateless-rpc', $repository->path], $request->body, $env);
        if ($service === 'git-receive-pack') {
            $after = Git::branches($repository->path);
            foreach ($after as $branch => $sha) {
                if (($before[$branch] ?? null) !== $sha) {
                    Hooks::push($this->world, $this->server, $repository, $branch, $before[$branch] ?? str_repeat('0', 40), $sha, $request->base);
                }
            }
        }

        return new Response(200, ['Content-Type' => "application/x-{$service}-result", 'Cache-Control' => 'no-cache'], $out);
    }

    private static function pkt(string $line): string
    {
        return \sprintf('%04x', \strlen($line) + 4) . $line;
    }
}

/**
 * The webhook deliveries providers make for pushes and pull requests.
 */
final class Hooks
{
    public static function push(World $world, Server $server, Repository $repository, string $branch, string $before, string $after, string $base): void
    {
        if ($repository->hooks === []) {
            return;
        }
        $info = Git::info($repository->path, $after);
        $touched = Git::touched($repository->path, $after);
        $author = ['name' => $info['author'] ?? '', 'email' => $info['email'] ?? ''];
        if ($world->provider === 'gitlab') {
            $web = $base . '/' . $repository->fullName();
            $payload = [
                'object_kind' => 'push',
                'event_name' => 'push',
                'before' => $before,
                'after' => $after,
                'ref' => 'refs/heads/' . $branch,
                'checkout_sha' => $after,
                'user_username' => (string) $world->config['user'],
                'user_avatar' => 'https://www.gravatar.com/avatar/fixture',
                'project_id' => $repository->id,
                'project' => [
                    'id' => $repository->id,
                    'name' => $repository->name,
                    'namespace' => $repository->owner,
                    'path_with_namespace' => $repository->fullName(),
                    'web_url' => $web,
                    'default_branch' => $repository->defaultBranch,
                ],
                'commits' => [[
                    'id' => $after,
                    'message' => $info['message'] ?? '',
                    'title' => strtok($info['message'] ?? '', "\n"),
                    'timestamp' => World::TIME,
                    'url' => $web . '/-/commit/' . $after,
                    'author' => $author,
                    'added' => $touched['added'],
                    'modified' => $touched['modified'],
                    'removed' => $touched['removed'],
                ]],
                'total_commits_count' => 1,
                'repository' => ['name' => $repository->name, 'homepage' => $web],
            ];
            $server->deliver($world, $repository, 'push', ['X-Gitlab-Event' => 'Push Hook', 'X-Gitlab-Token' => '{secret}'], (string) json_encode($payload, JSON_UNESCAPED_SLASHES));

            return;
        }

        $web = $base . '/' . $repository->fullName();
        $commit = [
            'id' => $after,
            'message' => ($info['message'] ?? '') . "\n",
            'url' => $web . '/commit/' . $after,
            'author' => $author + ['username' => (string) $world->config['user']],
            'committer' => $author + ['username' => (string) $world->config['user']],
            'timestamp' => World::TIME,
            'added' => $touched['added'],
            'removed' => $touched['removed'],
            'modified' => $touched['modified'],
        ];
        $payload = [
            'ref' => 'refs/heads/' . $branch,
            'before' => $before,
            'after' => $after,
            'compare_url' => $web . '/compare/' . $before . '...' . $after,
            'commits' => [$commit],
            'total_commits' => 1,
            'head_commit' => $commit,
            'repository' => Gitea::repositoryOf($world, $repository, $base),
            'pusher' => Gitea::userOf($world, (string) $world->config['user'], $base),
            'sender' => Gitea::userOf($world, (string) $world->config['user'], $base),
            'created' => $before === str_repeat('0', 40),
            'deleted' => false,
        ];
        $server->deliver($world, $repository, 'push', self::giteaHeaders($world, 'push'), (string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, string>
     */
    public static function giteaHeaders(World $world, string $event): array
    {
        $delivery = '00000000-0000-4000-8000-000000000001';
        $headers = ['X-Gogs-Delivery' => $delivery, 'X-Gogs-Event' => $event, 'X-Gogs-Signature' => '{signature}'];
        if ($world->provider !== 'gogs') {
            $headers += ['X-Gitea-Delivery' => $delivery, 'X-Gitea-Event' => $event, 'X-Gitea-Signature' => '{signature}'];
        }
        if ($world->provider === 'forgejo') {
            $headers += ['X-Forgejo-Delivery' => $delivery, 'X-Forgejo-Event' => $event, 'X-Forgejo-Signature' => '{signature}'];
        }

        return $headers;
    }
}
