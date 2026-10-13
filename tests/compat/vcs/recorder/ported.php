<?php

/*
 * Writes the vcs cases the recorder cannot take from a test run: the tests
 * whose adapters are test doubles (GitHubTest's mocked calls, OriginTest's
 * fixture keys), CloneCommandTest (a local repository instead of a
 * provider), Origin's API (no live suite: the partner API cannot create a
 * repository), and the interop cases. The doubles' canned answers become
 * fixture.script routes or fixture.config, and keys are fixed test keys, so
 * the cases are the same every time this runs. Expectations come from
 * `bin/compat record vcs`.
 *
 *   php tests/compat/vcs/recorder/ported.php    (in the dev image: sodium, openssl)
 */

namespace Tests\Compat\Vcs\Recorder;

$cases = __DIR__ . '/../cases';
$githubPem = (string) file_get_contents(__DIR__ . '/github-app.test.pem');
$githubPublic = (string) (openssl_pkey_get_details(openssl_pkey_get_private($githubPem) ?: throw new \RuntimeException('github-app.test.pem'))['key'] ?? '');

/** @param array<string, mixed> $file */
function write(string $path, array $file): void
{
    file_put_contents($path, json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/** @param array<string, mixed> $args @return array<string, mixed> */
function step(string $op, array $args = [], ?string $bind = null): array
{
    $step = ['op' => $op, 'args' => $args === [] ? new \stdClass() : $args];
    if ($bind !== null) {
        $step['bind'] = $bind;
    }
    if ($op === 'vcs.new') {
        $step['expect'] = ['$handle' => '*'];
    }

    return $step;
}

/** @param array<string, mixed> $args @return array<string, mixed> */
function call(string $op, array $args = [], ?string $bind = null, string $adapter = 'a'): array
{
    return step('vcs.' . $op, ['adapter' => ['$ref' => $adapter]] + $args, $bind);
}

function ref(string $name, string $path = ''): array
{
    return $path === '' ? ['$ref' => $name] : ['$ref' => $name, 'path' => $path];
}

$b64url = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

/*
 * CloneCommandTest: the clone command every Git adapter shares, run against
 * a bare repository on disk.
 */
$hostile = "src\t--checkpoint=1\t--checkpoint-action=exec=touch pwned;touch pwned\$(touch pwned)`touch pwned`";
$ws = '/tmp/compat-fs/${ns}/ws';
$setup = step('clone.setup', ['workspace' => $ws, 'files' => [
    'README.md' => '# Root',
    'docs/guide.md' => '# Guide',
    'astro/starter/index.md' => '# Starter',
    '0/zero.md' => '# Zero',
    $hostile . '/index.md' => '# Hostile',
]]);
$clone = static function (string $root, string $version = 'main', string $type = 'branch', string $dir = 'clone') use ($ws): array {
    $bind = 'c' . $dir;

    return [
        step('clone.command', ['repository' => $ws . '/remote.git', 'version' => $version, 'versionType' => $type, 'directory' => $ws . '/' . $dir, 'rootDirectory' => $root], $bind),
        step('clone.run', ['command' => ref($bind), 'directory' => $ws . '/' . $dir, 'workspace' => $ws]),
    ];
};
$tag = static fn (string $name, string $content, bool $annotated = false): array => step('clone.tag', ['workspace' => $ws, 'name' => $name, 'content' => $content, 'annotated' => $annotated]);
$list = [];
foreach ([
    'repository root' => '', 'dot' => '.', 'dot slash' => './', 'slash' => '/', 'bare' => 'docs', 'trailing slash' => 'docs/',
    'dot slash prefix' => './docs', 'dot slash prefix and trailing slash' => './docs/', 'nested' => './astro/starter', 'zero' => '0',
] as $name => $root) {
    $list[] = ['name' => "testCloneChecksOutTheRootDirectory with data set \"{$name}\"", 'steps' => [$setup, ...$clone($root)]];
}
$list[] = ['name' => 'testCloneTreatsTheRootDirectoryAsData', 'steps' => [$setup, ...$clone($hostile)]];
$list[] = ['name' => 'testCloneChecksOutATagStartingWithADash', 'steps' => [
    $setup,
    step('clone.ref', ['repository' => $ws . '/remote.git', 'ref' => 'refs/tags/-release', 'target' => 'main']),
    ...$clone('', '-release', 'tag'),
]];
$list[] = ['name' => 'testCloneChecksOutATagPattern', 'steps' => [
    $setup,
    $tag('0.1.0', "tag-0.1.0\n"), $tag('0.1.10', "tag-0.1.10\n"), $tag('0.1.2', "tag-0.1.2\n", true), $tag('0.2.0', "tag-0.2.0\n"),
    ...$clone('', '0.1.*', 'tag'),
    $tag('release/0.1.0', "tag-release-0.1.0\n"), $tag('release/0.1.10', "tag-release-0.1.10\n"), $tag('release/0.1.2', "tag-release-0.1.2\n", true), $tag('release/0.2.0', "tag-release-0.2.0\n"),
    ...$clone('', 'release/0.1.*', 'tag', 'clone2'),
]];
$list[] = ['name' => 'testCloneChecksOutAnExactTag', 'steps' => [$setup, $tag('1.2.3', "tag-1.2.3\n"), $tag('1.2.4', "tag-1.2.4\n"), ...$clone('', '1.2.3', 'tag')]];
$list[] = ['name' => 'testCloneFailsWhenNoTagMatches', 'steps' => [$setup, $tag('0.1.0', "tag-0.1.0\n"), ...$clone('', '9.9.*', 'tag')]];
write("{$cases}/CloneCommandTest.json", ['source' => 'packages/vcs/tests/CloneCommandTest.php', 'cases' => $list]);

/*
 * GitHubTest's mocked calls: the doubles' answers as scripted routes, the
 * JWTs the app key signs checked by the fixture server (`signature: valid`
 * in the recorded request).
 */
$github = static fn (string $appId = '5678', ?string $key = null): array => [
    step('fixture.config', ['config' => ['github' => ['rs256' => $githubPublic]]]),
    step('vcs.new', ['adapter' => 'github', 'endpoint' => '${vcs}/~${ns}/github'], 'a'),
    call('initialize_variables', ['installationId' => '1234', 'privateKey' => $key ?? $githubPem, 'appId' => $appId]),
];
$script = static fn (array $routes): array => step('fixture.script', ['routes' => $routes]);
$list = [
    ['name' => 'testInitializeVariablesSignsJwtWithPemString', 'steps' => [...$github(), step('fixture.requests')]],
];
foreach ([
    'base64' => base64_encode($githubPem),
    'wrapped base64' => chunk_split(base64_encode($githubPem), 76, "\n"),
    'escaped newlines' => str_replace("\n", '\n', $githubPem),
] as $name => $key) {
    $list[] = ['name' => "testInitializeVariablesSignsJwtWithEncodedPem with data set \"{$name}\"", 'steps' => [...$github('5678', $key), step('fixture.requests')]];
}
$list[] = ['name' => 'testInitializeVariablesKeepsClientIdIssuerAsString', 'steps' => [...$github('Iv1.0123456789abcdef'), step('fixture.requests')]];
$list[] = ['name' => 'testSearchRepositoriesPrivateProfile', 'steps' => [
    $script([
        ['method' => 'GET', 'path' => '/app/installations/1234', 'body' => ['repository_selection' => 'all', 'account' => ['login' => 'Private-Owner']]],
        ['method' => 'GET', 'path' => '/search/repositories', 'status' => 422, 'body' => ['message' => 'Validation Failed']],
        ['method' => 'GET', 'path' => '/installation/repositories', 'body' => ['repositories' => [['id' => 1, 'name' => 'private-repository']], 'total_count' => 1]],
    ]),
    ...$github(),
    call('search_repositories', ['owner' => 'private-owner', 'page' => 1, 'per_page' => 10]),
    step('fixture.requests'),
]];
$list[] = ['name' => 'testSearchRepositoriesUnknownOwner', 'steps' => [
    $script([
        ['method' => 'GET', 'path' => '/app/installations/1234', 'body' => ['repository_selection' => 'all', 'account' => ['login' => 'installation-owner']]],
        ['method' => 'GET', 'path' => '/search/repositories', 'status' => 422, 'body' => ['message' => 'Validation Failed']],
    ]),
    ...$github(),
    call('search_repositories', ['owner' => 'unknown-owner', 'page' => 1, 'per_page' => 10]),
    step('fixture.requests'),
]];
$installation = static fn (string $name, int $status, array $body): array => ['name' => $name, 'steps' => [
    $script([['method' => 'GET', 'path' => '/app/installations/1234', 'status' => $status, 'body' => $body]]),
    ...$github(),
    call('get_owner_name', ['installationId' => '1234']),
    step('fixture.requests'),
]];
$list[] = $installation('testGetOwnerNameForMissingInstallationThrowsOwnerNotFound', 404, ['message' => 'Not Found']);
$list[] = $installation('testGetOwnerNameKeepsTransientFailuresRetryable with data set "rate limited"', 403, ['message' => 'API rate limit exceeded']);
$list[] = $installation('testGetOwnerNameKeepsTransientFailuresRetryable with data set "too many requests"', 429, ['message' => 'You have exceeded a secondary rate limit']);
$list[] = $installation('testGetOwnerNameKeepsTransientFailuresRetryable with data set "server error"', 502, ['message' => 'Server Error']);
$list[] = $installation('testGetOwnerNameReturnsInstallationLogin', 200, ['account' => ['login' => 'appwrite']]);
write("{$cases}/GitHubTest-calls.json", ['source' => 'packages/vcs/tests/GitHubTest.php', 'cases' => $list]);

/*
 * OriginTest's receipts and signing keys: the fixture keys are the JWKS the
 * fixture server publishes, the receipts signed with fixed test keys and
 * expiring in 2100 (one already expired).
 */
$originKeys = sodium_crypto_sign_seed_keypair(str_repeat("\x01", SODIUM_CRYPTO_SIGN_SEEDBYTES));
$otherKeys = sodium_crypto_sign_seed_keypair(str_repeat("\x02", SODIUM_CRYPTO_SIGN_SEEDBYTES));
$receipt = static function (array $header, array $claims, string $keys) use ($b64url): string {
    $input = $b64url((string) json_encode($header)) . '.' . $b64url((string) json_encode($claims));

    return $input . '.' . $b64url(sodium_crypto_sign_detached($input, sodium_crypto_sign_secretkey($keys)));
};
$origin = static fn (array $config = []): array => [
    step('fixture.config', ['config' => ['origin' => $config]]),
    step('vcs.new', ['adapter' => 'origin', 'endpoint' => '${vcs}/~${ns}/origin', 'web' => '${vcs}/~${ns}/origin/web', 'git' => '${vcs-tls}/~${ns}/origin/git'], 'a'),
];
$jwks = [['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'k1', 'x' => $b64url(sodium_crypto_sign_publickey($originKeys))]];
$header = ['alg' => 'EdDSA', 'kid' => 'k1', 'typ' => 'origin-installation-receipt+jwt'];
$claims = ['iss' => 'https://api.cursor.com/v1/origin', 'aud' => 'app_0123456789', 'sub' => 'i_0123456789', 'iat' => 1767225600, 'exp' => 4102444800];
$verify = static fn (string $receipt, string $appId = 'app_0123456789'): array => call('verify_receipt', ['receipt' => $receipt, 'appId' => $appId]);
$rejections = [];
foreach ([
    'not a JWT' => ['not-a-jwt', 'app_0123456789'],
    'wrong algorithm' => [$receipt(['alg' => 'HS256'] + $header, $claims, $originKeys), 'app_0123456789'],
    'wrong token type' => [$receipt(['typ' => 'other+jwt'] + $header, $claims, $originKeys), 'app_0123456789'],
    'unknown key id' => [$receipt(['kid' => 'k2'] + $header, $claims, $originKeys), 'app_0123456789'],
    'wrong audience' => [$receipt($header, $claims, $originKeys), 'app_other'],
    'wrong issuer' => [$receipt($header, ['iss' => 'https://evil.test'] + $claims, $originKeys), 'app_0123456789'],
    'expired' => [$receipt($header, ['exp' => 1767225600 - 300] + $claims, $originKeys), 'app_0123456789'],
    'missing installation id' => [$receipt($header, ['sub' => ''] + $claims, $originKeys), 'app_0123456789'],
    'signed by a different key' => [$receipt($header, $claims, $otherKeys), 'app_0123456789'],
] as [$token, $appId]) {
    $rejections[] = $verify($token, $appId);
}
write("{$cases}/OriginTest-keys.json", ['source' => 'packages/vcs/tests/OriginTest.php', 'cases' => [
    ['name' => 'testVerifyReceipt', 'steps' => [
        ...$origin(['jwks' => $jwks]),
        $verify($receipt($header, $claims + ['state' => '{"projectId":"p1"}', 'namespace_id' => 'ns_0123456789'], $originKeys)),
        step('fixture.requests'),
    ]],
    ['name' => 'testVerifyReceiptRejections', 'steps' => [...$origin(['jwks' => $jwks]), ...$rejections, step('fixture.requests')]],
    ['name' => 'testGetSigningKeys', 'steps' => [
        ...$origin(['jwks' => [
            ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'k1', 'x' => 'first-key'],
            ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'k2', 'x' => 'second-key'],
            ['kty' => 'RSA', 'kid' => 'k3', 'n' => 'not-ed25519'],
        ]]),
        call('get_signing_keys'),
        call('get_signing_keys', ['refresh' => true]),
        step('fixture.requests'),
    ]],
]]);

/*
 * Origin's API, end to end against the fixture server: the app, its
 * installation, repositories it reads, Git writes over HTTPS, pull requests,
 * reviews, check suites and runs, deliveries and mirrors.
 */
$appSeed = str_repeat("\x03", SODIUM_CRYPTO_SIGN_SEEDBYTES);
$appKeys = sodium_crypto_sign_seed_keypair($appSeed);
$appPem = "-----BEGIN PRIVATE KEY-----\n" . chunk_split(base64_encode(hex2bin('302e020100300506032b657004220420') . $appSeed), 64, "\n") . "-----END PRIVATE KEY-----\n";
$repository = ['owner' => 'test-owner', 'repositoryName' => 'app'];
$seed = ['owner' => 'test-owner', 'name' => 'app', 'files' => ['README.md' => "# App\n", 'src/index.js' => "console.log('app');\n", 'docs/guide.md' => "# Guide\n"]];
$init = call('initialize_variables', ['installationId' => 'i_fixture', 'privateKey' => $appPem, 'appId' => 'app_fixture']);
$origin = static fn (array $config): array => [
    step('fixture.config', ['config' => ['origin' => ['ed25519' => $b64url(sodium_crypto_sign_publickey($appKeys))] + $config]]),
    step('vcs.new', ['adapter' => 'origin', 'endpoint' => '${vcs}/~${ns}/origin', 'web' => '${vcs}/~${ns}/origin/web', 'git' => '${vcs-tls}/~${ns}/origin/git'], 'a'),
];
$list = [];
$list[] = ['name' => 'app, installation and metadata', 'steps' => [
    ...$origin(['repositories' => [$seed]]),
    $init,
    call('get_name'), call('get_type'), call('get_event_header_name'), call('get_signature_header_name'), call('get_supported_webhook_scopes'),
    call('supports_repository_creation'), call('supports_repository_deletion'), call('supports_repository_archives'),
    call('supports_comment_images'), call('supports_public_repositories'),
    call('get_install_url', ['appId' => 'app_fixture', 'scopes' => ['repository:contents:read'], 'redirectUri' => 'https://appwrite.test/v1/vcs/origin/callback', 'state' => '{"projectId":"p1"}']),
    call('get_install_url', ['appId' => 'app_fixture']),
    call('get_authenticated_app'),
    call('list_installations'),
    call('has_access_to_all_repositories'),
    call('get_owner_name', ['installationId' => 'i_fixture']),
    call('get_owner_name', ['installationId' => 'i_fixture', 'repositoryId' => 1]),
    call('get_rate_limit'),
    call('list_webhook_deliveries'),
    call('list_webhook_deliveries', ['delivered' => false, 'eventType' => 'repository.pushed', 'installationId' => 'i_fixture', 'pageToken' => 'next']),
    call('redeliver_webhook_deliveries', ['deliveryIds' => ['whd_1', 'whd_2']]),
    call('get_user', ['username' => 'someone']),
    call('list_namespaces', ['page' => 1, 'per_page' => 10]),
    call('get_organization_url', ['owner' => 'test-owner']),
    call('get_repository_url', $repository),
    call('get_branch_url', $repository + ['branch' => 'feature/x']),
    call('get_commit_url', $repository + ['commitHash' => 'abc123']),
    call('get_file_url', $repository + ['reference' => 'main']),
    call('get_repository_clone_url', $repository),
    call('get_repository_clone_headers'),
    call('get_repository_presigned_url_headers'),
    call('get_repository_presigned_url', $repository),
    call('create_repository', $repository + ['private' => false]),
    call('delete_repository', $repository),
    call('create_webhook', $repository + ['url' => '${vcs}/~${ns}/catcher', 'secret' => 'webhook-secret']),
    call('delete_installation', ['installationId' => 'i_fixture']),
    step('fixture.requests'),
]];
$list[] = ['name' => 'repositories and contents', 'steps' => [
    ...$origin(['repositories' => [$seed, ['owner' => 'test-owner', 'name' => 'empty']]]),
    $init,
    call('search_repositories', ['owner' => 'test-owner', 'page' => 1, 'per_page' => 10]),
    call('search_repositories', ['owner' => 'test-owner', 'page' => 1, 'per_page' => 10, 'search' => 'ap']),
    call('search_repositories', ['owner' => 'test-owner', 'page' => 2, 'per_page' => 1]),
    call('get_installation_repository', ['repositoryName' => 'app']),
    call('get_repository', $repository, 'r'),
    call('get_repository_name', ['repositoryId' => ref('r', '/id')]),
    call('get_repository_name', ['repositoryId' => 'repo_missing']),
    call('get_repository', ['owner' => 'test-owner', 'repositoryName' => 'missing']),
    call('list_branches', $repository),
    call('list_tags', $repository),
    call('get_repository_tree', $repository + ['branch' => 'main']),
    call('get_repository_tree', $repository + ['branch' => 'main', 'recursive' => true]),
    call('get_repository_tree', $repository + ['branch' => 'missing']),
    call('list_repository_languages', $repository),
    call('list_repository_contents', $repository),
    call('list_repository_contents', $repository + ['path' => 'src', 'ref' => 'main']),
    call('list_repository_contents', $repository + ['path' => 'nowhere']),
    call('get_repository_content', $repository + ['path' => 'README.md'], 'f'),
    call('get_repository_content', $repository + ['path' => 'missing.md']),
    call('get_blob', $repository + ['sha' => ref('f', '/sha')]),
    call('batch_get_repository_contents', $repository + ['paths' => ['README.md', 'docs/guide.md', 'missing.md'], 'ref' => 'main']),
    call('get_latest_commit', $repository + ['branch' => 'main'], 'c'),
    call('get_latest_commit', ['owner' => 'test-owner', 'repositoryName' => 'empty', 'branch' => 'main']),
    call('get_commit', $repository + ['commitHash' => ref('c', '/commitHash')]),
    call('get_commit', $repository + ['commitHash' => '0000000000000000000000000000000000000000']),
    call('list_commits', $repository),
    call('list_commits', $repository + ['sha' => 'main']),
    call('list_commit_files', $repository + ['sha' => ref('c', '/commitHash')]),
    call('generate_clone_command', $repository + ['version' => 'main', 'versionType' => 'branch', 'directory' => '/tmp/compat-fs/${ns}/clone', 'rootDirectory' => 'src'], 'cmd'),
    step('clone.run', ['command' => ref('cmd'), 'directory' => '/tmp/compat-fs/${ns}/clone', 'workspace' => '/tmp/compat-fs/${ns}']),
    step('fixture.requests'),
]];
$list[] = ['name' => 'Git writes, pull requests and reviews', 'steps' => [
    ...$origin(['repositories' => [$seed]]),
    $init,
    call('create_file', $repository + ['filepath' => 'notes/todo.md', 'content' => "- ship\n", 'message' => 'Add notes']),
    call('create_branch', $repository + ['newBranchName' => 'feature', 'oldBranchName' => 'main']),
    call('create_file', $repository + ['filepath' => 'src/feature.js', 'content' => "export const feature = true;\n", 'branch' => 'feature']),
    call('create_file', $repository + ['filepath' => '../escape.txt', 'content' => 'x']),
    call('create_file', $repository + ['filepath' => './.', 'content' => 'x']),
    call('create_tag', $repository + ['tagName' => 'v1.0.0', 'target' => 'main', 'message' => 'First release']),
    call('create_tag', $repository + ['tagName' => 'v1.0.1', 'target' => 'main']),
    call('list_tags', $repository),
    call('list_tags', $repository + ['search' => 'v1.0.1']),
    call('list_branches', $repository),
    call('compare_commits', $repository + ['base' => 'main', 'head' => 'feature']),
    call('create_pull_request', $repository + ['title' => 'Add feature', 'head' => 'feature', 'base' => 'main', 'body' => 'Adds the feature']),
    call('get_pull_request', $repository + ['pullRequestNumber' => 1]),
    call('get_pull_request', $repository + ['pullRequestNumber' => 99]),
    call('get_pull_request_files', $repository + ['pullRequestNumber' => 1]),
    call('get_pull_request_from_branch', $repository + ['branch' => 'feature']),
    call('get_pull_request_from_branch', $repository + ['branch' => 'nothing']),
    call('list_pull_requests', $repository),
    call('list_pull_requests', $repository + ['state' => 'all', 'head' => 'feature']),
    call('update_pull_request', $repository + ['pullRequestNumber' => 1, 'title' => 'Add the feature', 'draft' => false]),
    call('update_pull_request', $repository + ['pullRequestNumber' => 1, 'body' => 'Updated', 'state' => 'open', 'base' => 'main']),
    call('list_pull_request_commits', $repository + ['pullRequestNumber' => 1]),
    call('create_comment', $repository + ['pullRequestNumber' => 1, 'comment' => 'Looks good'], 'cm'),
    call('get_comment', $repository + ['commentId' => ref('cm')]),
    call('update_comment', $repository + ['commentId' => ref('cm'), 'comment' => 'Looks great']),
    call('get_comment', $repository + ['commentId' => 'missing']),
    call('list_pull_request_comments', $repository + ['pullRequestNumber' => 1]),
    call('create_pull_request_review', $repository + ['pullRequestNumber' => 1, 'verdict' => 'APPROVE', 'body' => 'Approved'], 'rv'),
    call('create_pull_request_review', $repository + ['pullRequestNumber' => 1, 'verdict' => 'COMMENT', 'body' => 'A note', 'versionNumber' => '1']),
    call('list_pull_request_reviews', $repository + ['pullRequestNumber' => 1]),
    call('update_pull_request_review', $repository + ['pullRequestNumber' => 1, 'reviewId' => ref('rv', '/id'), 'body' => 'Approved again']),
    call('dismiss_pull_request_review', $repository + ['pullRequestNumber' => 1, 'reviewId' => ref('rv', '/id'), 'message' => 'Stale']),
    call('merge_pull_request', $repository + ['pullRequestNumber' => 1]),
    call('merge_pull_request', $repository + ['pullRequestNumber' => 1]),
    step('fixture.requests'),
]];
$list[] = ['name' => 'statuses, check suites and check runs', 'steps' => [
    ...$origin(['repositories' => [$seed]]),
    $init,
    call('get_latest_commit', $repository + ['branch' => 'main'], 'c'),
    call('update_commit_status', ['repositoryName' => 'app', 'SHA' => ref('c', '/commitHash'), 'owner' => 'test-owner', 'state' => 'success', 'description' => 'Built', 'target_url' => 'https://appwrite.test/build', 'context' => 'appwrite']),
    call('get_commit_statuses', $repository + ['commitHash' => ref('c', '/commitHash')]),
    call('create_check_run', $repository + ['headSha' => ref('c', '/commitHash'), 'name' => 'ci/build', 'status' => 'in_progress', 'externalId' => 'build-1', 'startedAt' => '2026-01-01T00:00:00Z', 'detailsUrl' => 'https://appwrite.test/build'], 'cr'),
    call('create_check_run', $repository + ['headSha' => ref('c', '/commitHash'), 'name' => 'ci/lint', 'status' => 'completed']),
    call('create_check_run', $repository + ['headSha' => ref('c', '/commitHash'), 'name' => 'ci/test', 'conclusion' => 'success', 'title' => 'Tests', 'summary' => 'All passed', 'text' => 'Details', 'externalId' => 'test-1', 'completedAt' => '2026-01-01T00:05:00Z']),
    call('get_check_run', $repository + ['checkRunId' => ref('cr', '/id')]),
    call('get_check_run', $repository + ['checkRunId' => 'cr_missing']),
    call('update_check_run', $repository + ['checkRunId' => ref('cr', '/id'), 'status' => 'completed', 'conclusion' => 'failure', 'title' => 'Build', 'summary' => 'Failed', 'completedAt' => '2026-01-01T00:10:00Z']),
    call('update_check_run', $repository + ['checkRunId' => ref('cr', '/id'), 'status' => 'completed']),
    call('list_check_runs_for_commit', $repository + ['sha' => ref('c', '/commitHash')]),
    call('batch_upsert_check_runs', $repository + ['headSha' => ref('c', '/commitHash'), 'suiteName' => 'appwrite', 'checkRuns' => [
        ['name' => 'build', 'key' => 'build', 'status' => 'in_progress', 'externalId' => 'b-1', 'startedAt' => '2026-01-01T00:00:00Z'],
        ['name' => 'test', 'key' => 'test', 'conclusion' => 'success', 'title' => 'Tests', 'summary' => 'All passed', 'externalId' => 't-1', 'completedAt' => '2026-01-01T00:05:00Z', 'detailsUrl' => 'https://appwrite.test/t'],
    ]], 'bu'),
    call('batch_upsert_check_runs', $repository + ['headSha' => ref('c', '/commitHash'), 'suiteName' => 'appwrite', 'checkRuns' => [
        ['name' => 'build', 'status' => 'completed'],
    ]]),
    call('get_check_suite', $repository + ['checkSuiteId' => ref('bu', '/0/check_suite_id')]),
    call('list_check_runs_for_suite', $repository + ['checkSuiteId' => ref('bu', '/0/check_suite_id')]),
    call('get_check_suite', $repository + ['checkSuiteId' => 'cs_missing']),
    step('fixture.requests'),
]];
$list[] = ['name' => 'mirrors and restricted installations', 'steps' => [
    ...$origin(['repositories' => [$seed], 'mirror' => true, 'deny_owner_listing' => true, 'selection' => 'selected']),
    $init,
    call('has_access_to_all_repositories'),
    call('search_repositories', ['owner' => 'test-owner', 'page' => 1, 'per_page' => 10]),
    call('sync_mirror', $repository + ['ref' => 'main']),
    call('sync_mirror', $repository + ['ref' => 'main', 'wait' => true, 'sha' => '0123456789abcdef0123456789abcdef01234567']),
    call('sync_mirror', ['owner' => 'test-owner', 'repositoryName' => 'missing', 'ref' => 'main']),
    step('fixture.requests'),
]];
$list[] = ['name' => 'an app the API refuses', 'steps' => [
    ...$origin(['repositories' => [$seed]]),
    call('initialize_variables', ['installationId' => 'i_fixture', 'privateKey' => $appPem]),
    call('initialize_variables', ['installationId' => 'i_fixture', 'privateKey' => 'not a key', 'appId' => 'app_fixture']),
    call('initialize_variables', ['installationId' => 'i_fixture', 'privateKey' => base64_encode(str_repeat("\x05", 16)), 'appId' => 'app_fixture']),
    call('initialize_variables', ['installationId' => 'i_fixture', 'privateKey' => base64_encode(sodium_crypto_sign_secretkey($appKeys)), 'appId' => 'app_fixture']),
    call('initialize_variables', ['installationId' => 'i_fixture', 'privateKey' => $b64url($appSeed), 'appId' => 'app_fixture']),
    step('fixture.script', ['routes' => [['method' => 'POST', 'path' => '/app/installations/i_denied/access_tokens', 'status' => 403, 'body' => ['code' => 'PERMISSION_DENIED', 'message' => 'installation suspended', 'detail' => 'hidden']]]]),
    call('initialize_variables', ['installationId' => 'i_denied', 'privateKey' => $appPem, 'appId' => 'app_fixture']),
    step('fixture.requests'),
]];
write("{$cases}/Origin.json", ['cases' => $list]);

/*
 * Interop: the installation tokens GitHub and Origin cache, written by one
 * runtime and read by the other (no second token request).
 */
$side = static function (string $side, array $step): array {
    return ['side' => $side] + $step;
};
$githubInterop = [
    $side('a', step('fixture.config', ['config' => ['github' => ['rs256' => $githubPublic]]])),
    $side('a', step('vcs.new', ['adapter' => 'github', 'endpoint' => '${vcs}/~${ns}/github', 'cache' => 'redis'], 'w')),
    $side('a', call('initialize_variables', ['installationId' => '${ns}', 'privateKey' => $githubPem, 'appId' => '5678'], null, 'w')),
    $side('b', step('vcs.new', ['adapter' => 'github', 'endpoint' => '${vcs}/~${ns}/github', 'cache' => 'redis'], 'r')),
    $side('b', call('initialize_variables', ['installationId' => '${ns}', 'privateKey' => 'unused: the token is cached', 'appId' => '5678'], null, 'r')),
    $side('b', call('get_owner_name', ['installationId' => '${ns}'], null, 'r')),
    $side('b', step('fixture.requests')),
];
$originInterop = [
    $side('a', step('fixture.config', ['config' => ['origin' => ['ed25519' => $b64url(sodium_crypto_sign_publickey($appKeys)), 'repositories' => [$seed]]]])),
    $side('a', step('vcs.new', ['adapter' => 'origin', 'endpoint' => '${vcs}/~${ns}/origin', 'web' => '${vcs}/~${ns}/origin/web', 'git' => '${vcs-tls}/~${ns}/origin/git', 'cache' => 'redis'], 'w')),
    $side('a', call('initialize_variables', ['installationId' => '${ns}', 'privateKey' => $appPem, 'appId' => 'app_fixture'], null, 'w')),
    $side('b', step('vcs.new', ['adapter' => 'origin', 'endpoint' => '${vcs}/~${ns}/origin', 'web' => '${vcs}/~${ns}/origin/web', 'git' => '${vcs-tls}/~${ns}/origin/git', 'cache' => 'redis'], 'r')),
    $side('b', call('initialize_variables', ['installationId' => '${ns}', 'privateKey' => 'unused: the token is cached', 'appId' => 'app_fixture'], null, 'r')),
    $side('b', call('get_repository', $repository, null, 'r')),
    $side('b', step('fixture.requests')),
];
write("{$cases}/interop.json", ['source' => 'interop', 'cases' => [
    ['name' => 'GitHub: the installation token one runtime cached is the other runtime\'s', 'interop' => true, 'steps' => $githubInterop],
    ['name' => 'Origin: the installation token one runtime cached is the other runtime\'s', 'interop' => true, 'steps' => $originInterop],
]]);

/*
 * A delivery the providers send for a push reaches the fixture server's
 * catcher, signed with the webhook's secret, and parses into the same event
 * on both runtimes.
 */
$gitea = [
    step('vcs.new', ['adapter' => 'gitea'], 'a'),
    call('initialize_variables', ['installationId' => '', 'privateKey' => '', 'accessToken' => 'test-token-gitea']),
    call('set_endpoint', ['endpoint' => '${vcs}/~${ns}/gitea']),
    call('create_organization', ['orgName' => 'hooks']),
    call('create_repository', ['owner' => 'hooks', 'repositoryName' => 'site', 'private' => false]),
    call('create_webhook', ['owner' => 'hooks', 'repositoryName' => 'site', 'url' => '${vcs}/~${ns}/catcher', 'secret' => 'webhook-secret']),
    call('create_file', ['owner' => 'hooks', 'repositoryName' => 'site', 'filepath' => 'index.html', 'content' => '<h1>Hi</h1>']),
    ['op' => 'fixture.caught', 'args' => new \stdClass(), 'bind' => 'd', 'mask' => [
        'paths' => ['/headers/X-Gogs-Signature', '/headers/X-Gitea-Signature'],
        'reason' => 'the payload carries the fixture server\'s address as each runtime reaches it; the next step checks the signature on both',
    ]],
    call('validate_webhook_event', ['payload' => ref('d', '/data'), 'signature' => ref('d', '/headers/X-Gitea-Signature'), 'signatureKey' => 'webhook-secret']),
    call('get_events', ['event' => 'push', 'payload' => ref('d', '/data')]),
];
write("{$cases}/Webhooks.json", ['cases' => [['name' => 'Gitea: a push delivery reaches the catcher, signed and parseable', 'steps' => $gitea]]]);
