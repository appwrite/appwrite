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
// The command sets the default branch for its own repository only: no step
// writes the global git config, which concurrent clones would lock
$list[] = ['name' => 'testCloneLeavesTheGlobalConfigAlone', 'steps' => [$setup, ...$clone('docs')]];
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
$list[] = ['name' => 'testUpdateCheckNamesTheStatusAfterItsSuite', 'steps' => [
    $script([['method' => 'POST', 'path' => '/repos/owner/repo/statuses/abc123', 'status' => 201, 'body' => []]]),
    ...$github(),
    call('update_check', ['owner' => 'owner', 'repositoryName' => 'repo', 'SHA' => 'abc123', 'state' => 'queued', 'name' => 'Billing', 'title' => 'Starting...', 'summary' => '- Function Billing', 'detailsUrl' => 'https://cloud.appwrite.io', 'suite' => 'Atlas Production']),
    call('update_check', ['owner' => 'owner', 'repositoryName' => 'repo', 'SHA' => 'abc123', 'state' => 'success', 'name' => 'Billing', 'title' => 'Build succeeded.']),
    step('fixture.requests'),
]];
$pages = [];
foreach (range(1, 11) as $page) {
    $repositories = [];
    foreach (range(1, $page <= 10 ? 100 : 1) as $index) {
        $id = ($page - 1) * 100 + $index;
        $repositories[] = ['id' => $id, 'name' => 'repository-' . $id];
    }
    $pages[] = ['method' => 'GET', 'path' => "/installation/repositories?page={$page}&per_page=100", 'body' => ['repositories' => $repositories]];
}
$list[] = ['name' => 'testGetInstallationRepositoryScansPastThousandRepositories', 'steps' => [
    $script($pages),
    ...$github(),
    call('get_installation_repository', ['repositoryName' => 'repository-1001']),
    call('get_installation_repository', ['repositoryName' => 'repository-1002']),
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

/*
 * EntireTest: delivery tokens, receipts, deliveries, URLs, capabilities and
 * check run writes. Keys are fixed test seeds and tokens expire in 2100;
 * receipts name the core by the fixture server's address, so `jwt.sign`
 * signs them in each runtime. The core and the cell are scripted routes
 * under the fixture server's origin prefix: nothing reaches Entire.
 */
$entireKeys = sodium_crypto_sign_seed_keypair(str_repeat("\x07", SODIUM_CRYPTO_SIGN_SEEDBYTES));
$entireOther = sodium_crypto_sign_seed_keypair(str_repeat("\x08", SODIUM_CRYPTO_SIGN_SEEDBYTES));
$entirePublic = $b64url(sodium_crypto_sign_publickey($entireKeys));
$core = '${vcs}/~${ns}/origin/entire-core';
$cell = '${vcs}/~${ns}/origin/entire-cell';
$issuer = 'https://appwrite.test';
$newEntire = static fn (array $extra = []): array => step('vcs.new', ['adapter' => 'entire', 'issuer' => $issuer] + $extra, 'a');
$deliveryClaims = static fn (string $payload): array => [
    'iss' => 'https://aws-us-east-2.api.entire.io',
    'aud' => 'appwrite',
    'iat' => 1767225600,
    'exp' => 4102444800,
    'jti' => 'jti_0123456789',
    'body_sha256' => rtrim(strtr(base64_encode(hash('sha256', $payload, true)), '+/', '-_'), '='),
];
$delivery = '{"delivery_id":"dlv_0123456789"}';
$jwtHeader = ['alg' => 'EdDSA', 'kid' => 'k1', 'typ' => 'JWT'];
$token = $receipt($jwtHeader, $deliveryClaims($delivery), $entireKeys);
$validate = static fn (string $payload, string $signature, string $key): array => call('validate_webhook_event', ['payload' => $payload, 'signature' => $signature, 'signatureKey' => $key]);
$publicB64 = base64_encode(sodium_crypto_sign_publickey($entireKeys));
$noExp = $deliveryClaims($delivery);
unset($noExp['exp']);
$noDigest = $deliveryClaims($delivery);
unset($noDigest['body_sha256']);
$spki = hex2bin('302a300506032b6570032100') . sodium_crypto_sign_publickey($entireKeys);
$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . '-----END PUBLIC KEY-----';
$refPayload = static function (string $type, string $ref): array {
    $created = $type === 'ref.created';
    $deleted = $type === 'ref.deleted';

    return [
        'delivery_id' => 'dlv_0123456789',
        'installation_id' => 'ins_01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'plugin_id' => 'appwrite',
        'api_url' => 'https://aws-us-east-2.api.entire.io/',
        'core_url' => 'https://us.console.entire.io',
        'event' => [
            'id' => 'evt_0123456789',
            'type' => $type,
            'time' => '2026-10-15T10:00:00Z',
            'payload' => [
                'ref' => $ref,
                'before' => $created ? str_repeat('0', 40) : 'abc1234567abc1234567abc1234567abc1234567',
                'after' => $deleted ? str_repeat('0', 40) : 'def4567890def4567890def4567890def4567890',
                'forced' => false,
                'pusher' => ['account' => ['id' => 'acc_0123456789', 'handle' => 'github:alice']],
                'repository' => [
                    'id' => 'repo_01ARZ3NDEKTSV4RRFFQ69G5FAV',
                    'path' => 'et/test-project/test-repo',
                    // Entire names the default branch by its full ref
                    'default_branch' => 'refs/heads/main',
                    'clone_url' => 'https://aws-us-east-2.entire.io/et/test-project/test-repo',
                    'entire_clone_url' => 'entire://aws-us-east-2.entire.io/et/test-project/test-repo',
                ],
            ],
        ],
    ];
};
$events = static fn (string $event, mixed $payload): array => call('get_events', ['event' => $event, 'payload' => \is_string($payload) ? $payload : (string) json_encode($payload)]);
$receiptHeader = ['alg' => 'EdDSA', 'kid' => 'k1', 'typ' => 'entire-install-receipt+jwt'];
$receiptClaims = [
    'iss' => $core,
    'aud' => [$issuer],
    'plugin_id' => 'appwrite',
    'installation_id' => 'ins_01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'iat' => 1767225600,
    'exp' => 4102444800,
];
$sign = static fn (array $header, array $claims, string $bind, string $seed = "\x07"): array => step('jwt.sign', ['header' => $header, 'claims' => $claims, 'seed' => bin2hex(str_repeat($seed, 32))], $bind);
$jwksRoute = static fn (string $prefix, array $keys): array => ['method' => 'GET', 'path' => $prefix . '/.well-known/jwks.json', 'body' => ['keys' => $keys]];
$coreKeys = [['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'k1', 'x' => $entirePublic]];
$verifyEntire = static fn (string $bind, string $plugin = 'appwrite'): array => call('verify_receipt', ['receipt' => ref($bind, '/token'), 'plugin' => $plugin]);
$rejections = [];
foreach ([
    'wrong algorithm' => [['alg' => 'HS256'] + $receiptHeader, $receiptClaims, "\x07", 'appwrite'],
    'wrong token type' => [['typ' => 'JWT'] + $receiptHeader, $receiptClaims, "\x07", 'appwrite'],
    'unknown key id' => [['kid' => 'k2'] + $receiptHeader, $receiptClaims, "\x07", 'appwrite'],
    'another plugin' => [$receiptHeader, $receiptClaims, "\x07", 'other-plugin'],
    'another audience' => [$receiptHeader, ['aud' => ['https://other.test']] + $receiptClaims, "\x07", 'appwrite'],
    'issuer not a core' => [$receiptHeader, ['iss' => 'https://evil.test'] + $receiptClaims, "\x07", 'appwrite'],
    'issuer a lookalike' => [$receiptHeader, ['iss' => 'https://eu.auth.entire.io.evil.test'] + $receiptClaims, "\x07", 'appwrite'],
    'expired' => [$receiptHeader, ['exp' => 1767225600 - 300] + $receiptClaims, "\x07", 'appwrite'],
    'missing installation id' => [$receiptHeader, ['installation_id' => ''] + $receiptClaims, "\x07", 'appwrite'],
    'signed by a different key' => [$receiptHeader, $receiptClaims, "\x08", 'appwrite'],
    'not yet valid' => [$receiptHeader, ['nbf' => 4102444800] + $receiptClaims, "\x07", 'appwrite'],
    'issued in the future' => [$receiptHeader, ['iat' => 4102444800] + $receiptClaims, "\x07", 'appwrite'],
] as [$h, $cl, $seedByte, $plugin]) {
    $bind = 'r' . \count($rejections);
    $rejections[] = $sign($h, $cl, $bind, $seedByte);
    $rejections[] = $verifyEntire($bind, $plugin);
}
$p = '/entire-core/api/v1/plugins/appwrite/installations/ins_fixture';
$c = '/entire-cell/api/v1';
$sha = 'def4567890def4567890def4567890def4567890';
$checkRoutes = [
    ['method' => 'GET', 'path' => $p . '/repositories', 'body' => ['repositories' => [['id' => 'repo_1', 'full_name' => 'et/test-project/app', 'clone_url' => 'https://cell.entire.test/et/test-project/app.git', 'placements' => [['api_base_url' => '${base}/entire-cell', 'role' => 'processing_primary']]]]]],
    ['method' => 'POST', 'path' => $p . '/access-tokens', 'body' => ['token' => 'tok_fixture', 'expires_at' => '2100-01-01T00:00:00Z']],
    ['method' => 'GET', 'path' => $c . '/repos/repo_1', 'body' => ['id' => 'grepo_1', 'default_branch' => 'main']],
    ['method' => 'POST', 'path' => $c . '/git/repos/grepo_1/branches-containing', 'body' => ['abc123' => ['refs/heads/main']]],
    ['method' => 'POST', 'path' => $c . '/repos/repo_1/commits/abc123/check-runs', 'status' => 201, 'body' => ['ok' => true]],
];
$check = static fn (string $state, string $name, string $title, string $summary = '', string $detailsUrl = ''): array => call('update_check', ['owner' => 'test-project', 'repositoryName' => 'app', 'SHA' => 'abc123', 'state' => $state, 'name' => $name, 'title' => $title, 'summary' => $summary, 'detailsUrl' => $detailsUrl]);
$entireInit = call('initialize_variables', ['installationId' => 'ins_fixture', 'privateKey' => $appPem, 'appId' => 'appwrite']);
$entireConfig = step('fixture.config', ['config' => ['origin' => ['ed25519' => $b64url(sodium_crypto_sign_publickey($appKeys))]]]);
$list = [
    ['name' => 'testValidateWebhookEvent', 'steps' => [
        $newEntire(),
        $validate($delivery, 'Bearer ' . $token, $publicB64),
        $validate($delivery, $token, $publicB64),
        $validate($delivery, 'not-a-token', $publicB64),
        $validate($delivery, $receipt($jwtHeader, $deliveryClaims($delivery), $entireOther), $publicB64),
        $validate($delivery . ' ', 'Bearer ' . $token, $publicB64),
        $validate($delivery, $receipt($jwtHeader, ['exp' => 1767225600 - 300] + $deliveryClaims($delivery), $entireKeys), $publicB64),
        $validate($delivery, $receipt($jwtHeader, $noExp, $entireKeys), $publicB64),
        $validate($delivery, $receipt($jwtHeader, $noDigest, $entireKeys), $publicB64),
        $validate($delivery, $receipt(['alg' => 'HS256'] + $jwtHeader, $deliveryClaims($delivery), $entireKeys), $publicB64),
        $validate($delivery, $receipt($jwtHeader, ['iat' => 4102444800] + $deliveryClaims($delivery), $entireKeys), $publicB64),
        $validate($delivery, 'Bearer ' . $token, 'not a key'),
        $validate($delivery, 'Bearer ' . $token, $entirePublic),
    ]],
    ['name' => 'testValidateWebhookEventAcceptsPemPublicKey', 'steps' => [
        $newEntire(),
        $validate($delivery, 'Bearer ' . $receipt(['alg' => 'EdDSA', 'kid' => 'k1'], $deliveryClaims($delivery), $entireKeys), $pem),
        $validate($delivery, 'Bearer ' . $token, "-----BEGIN PUBLIC KEY-----\nAAAA\n-----END PUBLIC KEY-----"),
    ]],
    ['name' => 'testGetWebhookClaims', 'steps' => [
        $newEntire(),
        call('get_webhook_claims', ['authorization' => 'Bearer ' . $receipt(['alg' => 'EdDSA', 'kid' => 'k1'], $deliveryClaims('{}'), $entireKeys)]),
        call('get_webhook_claims', ['authorization' => $receipt(['alg' => 'EdDSA'], $deliveryClaims('{}'), $entireKeys)]),
        call('get_webhook_claims', ['authorization' => 'Bearer not-a-token']),
        call('get_webhook_claims', ['authorization' => 'Bearer bm90.anNvbg.x']),
    ]],
    ['name' => 'testIsTrustedIssuer', 'steps' => [
        $newEntire(),
        ...array_map(static fn (string $i): array => call('is_trusted_issuer', ['issuer' => $i]), [
            'https://aws-us-east-2.api.entire.io', 'https://aws-eu-central-1.api.entire.io/', 'https://entire.io',
            'https://evil.example', 'https://entire.io.evil.example', 'https://evilentire.io', 'http://aws-us-east-2.api.entire.io',
            'https://aws-us-east-2.api.entire.io/cells', '',
        ]),
        call('set_endpoint', ['endpoint' => 'http://localhost:8787']),
        call('is_trusted_issuer', ['issuer' => 'http://localhost:8788']),
        call('is_trusted_issuer', ['issuer' => 'http://entire.io']),
    ]],
    ['name' => 'testGetInstallUrl', 'steps' => [
        $newEntire(),
        call('get_install_url', ['plugin' => 'appwrite', 'redirectUri' => 'https://appwrite.test/v1/vcs/entire/callback', 'state' => '{"projectId":"p1"}']),
        call('get_install_url', ['plugin' => 'appwrite']),
    ]],
    ['name' => 'testVerifyReceipt', 'steps' => [
        step('fixture.script', ['routes' => [$jwksRoute('/entire-core', $coreKeys)]]),
        $newEntire(['endpoint' => $core]),
        $sign($receiptHeader, $receiptClaims + ['project' => 'test-project', 'project_id' => '01M4ZC35447W79310H702K9276', 'state' => '{"projectId":"p1"}', 'jti' => 'rcp_0123456789'], 'rc'),
        $verifyEntire('rc'),
        $sign($receiptHeader, ['aud' => $issuer] + $receiptClaims, 'rs'),
        $verifyEntire('rs'),
        call('set_issuer', ['issuer' => 'https://other.test']),
        $verifyEntire('rs'),
        call('set_issuer', ['issuer' => '']),
        $verifyEntire('rs'),
        step('fixture.requests'),
    ]],
    ['name' => 'testVerifyReceiptRejections', 'steps' => [
        step('fixture.script', ['routes' => [$jwksRoute('/entire-core', $coreKeys)]]),
        $newEntire(['endpoint' => $core]),
        call('verify_receipt', ['receipt' => 'not-a-jwt', 'plugin' => 'appwrite']),
        call('verify_receipt', ['receipt' => 'bm90.anNvbg.x', 'plugin' => 'appwrite']),
        ...$rejections,
        step('fixture.requests'),
    ]],
    ['name' => 'testIsTrustedCore', 'steps' => [
        $newEntire(),
        ...array_map(static fn (string $o): array => call('is_trusted_core', ['origin' => $o]), [
            'https://us.auth.entire.io', 'https://eu.auth.entire.io', 'https://au.auth.entire.io', 'https://in.auth.entire.io',
            'https://uk.auth.entire.io', 'https://sg.auth.entire.io', 'https://us.auth.entire.io/', 'https://us.console.entire.io',
            'https://aws-us-east-2.api.entire.io', '',
        ]),
        call('set_endpoint', ['endpoint' => 'http://localhost:8787']),
        call('is_trusted_core', ['origin' => 'http://localhost:8787']),
    ]],
    ['name' => 'testGetSigningKeys', 'steps' => [
        step('fixture.script', ['routes' => [
            $jwksRoute('/entire-core', [
                ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'k1', 'x' => 'first-key'],
                ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'k2', 'x' => 'second-key'],
                ['kty' => 'RSA', 'kid' => 'k3', 'n' => 'not-ed25519'],
                ['kty' => 'OKP', 'crv' => 'X25519', 'kid' => 'k4', 'x' => 'not-signing'],
                'not a key',
            ]),
            $jwksRoute('/entire-cell', [['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'c1', 'x' => 'cell-key']]),
        ]]),
        $newEntire(['endpoint' => $core]),
        call('get_signing_keys'),
        call('get_signing_keys'),
        call('get_signing_keys', ['refresh' => true]),
        call('get_signing_keys', ['issuer' => $cell . '/']),
        step('fixture.requests'),
    ]],
    ['name' => 'testGetEventType', 'steps' => [
        $newEntire(),
        call('get_event_type', ['payload' => (string) json_encode($refPayload('ref.updated', 'refs/heads/main'))]),
        call('get_event_type', ['payload' => '{}']),
        call('get_event_type', ['payload' => 'not json']),
        call('get_event_type', ['payload' => '{"event":{"type":7}}']),
    ]],
    ['name' => 'testGetEventRefUpdated', 'steps' => [$newEntire(), $events('ref.updated', $refPayload('ref.updated', 'refs/heads/main'))]],
    ['name' => 'testGetEventRefLifecycle', 'steps' => [
        $newEntire(),
        $events('ref.created', $refPayload('ref.created', 'refs/heads/new-branch')),
        $events('ref.deleted', $refPayload('ref.deleted', 'refs/heads/old-branch')),
    ]],
    ['name' => 'testGetEventIgnoresTags', 'steps' => [$newEntire(), $events('ref.created', $refPayload('ref.created', 'refs/tags/v1.0.0'))]],
    ['name' => 'testGetEventReadsTypeFromBody', 'steps' => [$newEntire(), $events('', $refPayload('ref.deleted', 'refs/heads/main'))]],
    ['name' => 'testGetEventInstallation', 'steps' => [
        $newEntire(),
        $events('installation.deleted', [
            'delivery_id' => 'dlv_0123456789',
            'installation_id' => 'ins_01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'plugin_id' => 'appwrite',
            'api_url' => 'https://aws-us-east-2.api.entire.io',
            'event' => ['id' => 'evt_0123456789', 'type' => 'installation.deleted', 'payload' => ['installation' => [
                'project_id' => 'prj_0123456789', 'repository_selection' => 'all', 'repository_count' => 0, 'revision' => 3,
                'added_repository_ids' => ['repo_1', 2, null, ['x']], 'removed_repository_ids' => 'none',
            ]]],
        ]),
    ]],
    ['name' => 'testGetEventsIgnoresUnknownTypes', 'steps' => [$newEntire(), $events('trail.opened', $refPayload('trail.opened', 'refs/heads/main'))]],
    ['name' => 'testGetEventsRejectsInvalidPayload', 'steps' => [$newEntire(), $events('ref.updated', 'not json'), $events('ref.updated', '"text"')]],
    ['name' => 'testUrls', 'steps' => [
        $newEntire(),
        call('get_organization_url', ['owner' => 'acme']),
        call('get_repository_url', ['owner' => 'acme', 'repositoryName' => 'api']),
        call('get_branch_url', ['owner' => 'acme', 'repositoryName' => 'api', 'branch' => 'main']),
        call('get_commit_url', ['owner' => 'acme', 'repositoryName' => 'api', 'commitHash' => 'abc']),
        call('get_file_url', ['owner' => 'acme', 'repositoryName' => 'api', 'reference' => 'main']),
        call('get_pull_request_url', ['owner' => 'acme', 'repositoryName' => 'api', 'pullRequestNumber' => 7]),
    ]],
    ['name' => 'testCapabilities', 'steps' => [
        $newEntire(),
        call('get_name'), call('get_type'), call('get_supported_webhook_scopes'), call('get_signature_header_name'), call('get_event_header_name'),
        call('supports_repository_creation'), call('supports_repository_deletion'), call('supports_repository_archives'),
        call('supports_comment_images'), call('supports_public_repositories'), call('has_access_to_all_repositories'),
        call('list_repository_languages', ['owner' => 'acme', 'repositoryName' => 'api']),
        call('supports_repository_clone_url'), call('get_repository_clone_headers'),
        call('generate_push_command', ['owner' => 'acme', 'repositoryName' => 'api', 'directory' => '/tmp/clone', 'branch' => 'main']),
    ]],
    ['name' => 'testCheckRunsStates', 'steps' => [
        $entireConfig,
        step('fixture.script', ['routes' => $checkRoutes]),
        $newEntire(['endpoint' => $core]),
        $entireInit,
        $check('queued', 'Site (Project)', 'Starting...'),
        $check('pending', 'check', 'Building...'),
        $check('in_progress', 'check', 'Building...'),
        $check('success', 'check', 'Done.'),
        $check('failure', 'check', 'Done.'),
        $check('error', 'check', 'Done.'),
        $check('cancelled', 'check', 'Done.'),
        $check('unknown', 'check', 'Done.'),
        step('fixture.requests'),
    ]],
    ['name' => 'testCheckRunsOutputAndDetails', 'steps' => [
        $entireConfig,
        step('fixture.script', ['routes' => $checkRoutes]),
        $newEntire(['endpoint' => $core]),
        $entireInit,
        $check('success', 'check', 'Build succeeded.', "- Site best in project QA\n- Preview: https://x.example.com", 'https://x.example.com'),
        $check('success', 'check', 'Done.', '', 'http://localhost/console'),
        $check('success', 'check', 'Done.', '', 'https://user:secret@example.com'),
        $check('pending', '', ''),
        $check('pending', '0', str_repeat('Long title ', 30)),
        step('fixture.requests'),
    ]],
    ['name' => 'testCreateFileRejectsEscapingPath', 'steps' => [$newEntire(), call('create_file', ['owner' => 'owner', 'repositoryName' => 'repo', 'filepath' => '../escape.txt', 'content' => 'content'])]],
    ['name' => 'testCreateFileRejectsEmptyPath', 'steps' => [$newEntire(), call('create_file', ['owner' => 'owner', 'repositoryName' => 'repo', 'filepath' => './.', 'content' => 'content'])]],
    ['name' => 'testCreateFileRejectsGitMetadataPath', 'steps' => [$newEntire(), call('create_file', ['owner' => 'owner', 'repositoryName' => 'repo', 'filepath' => 'docs/../.Git/config', 'content' => 'content'])]],
];

/*
 * GitHub mirrors, merge bases, shared check suites, deployments, repository
 * creation and the permission sets a token mint falls back through, against
 * scripted core and cell routes.
 */
$problemHeaders = ['Content-Type' => 'application/problem+json'];
$repo = ['owner' => 'test-project', 'repositoryName' => 'app'];
$mint = static fn (array $permissions, int $times = 0): array => ['method' => 'POST', 'path' => $p . '/access-tokens', 'body' => ['token' => 'tok_fixture', 'expires_at' => '2100-01-01T00:00:00Z', 'permissions' => $permissions]] + ($times > 0 ? ['times' => $times] : []);
$refusal = static fn (string $code, int $status): array => ['method' => 'POST', 'path' => $p . '/access-tokens', 'times' => 1, 'status' => $status, 'headers' => $problemHeaders, 'body' => ['code' => $code]];
$all = ['contents' => 'write', 'checks' => 'write', 'deployments' => 'write', 'discussions' => 'write', 'repositories' => 'create'];
$mirror = static fn (string $id, string $fullName): array => [
    'id' => $id,
    'full_name' => $fullName,
    'path' => '/gh/' . strtolower($fullName),
    'entire_clone_url' => 'entire://aws-eu-central-1.entire.io/gh/' . strtolower($fullName),
    'placements' => [['role' => 'processing_primary', 'api_base_url' => '${base}/entire-cell']],
];
$app = [['id' => 'repo_1', 'full_name' => 'et/test-project/app', 'placements' => [['api_base_url' => '${base}/entire-cell', 'role' => 'processing_primary']]]];
$cellRoutes = [
    ['method' => 'GET', 'path' => $c . '/repos/repo_1', 'body' => ['id' => 'grepo_1', 'default_branch' => 'main']],
    ['method' => 'POST', 'path' => $c . '/git/repos/grepo_1/branches-containing', 'body' => ['abc123' => ['refs/heads/feature']]],
];
$withPermissions = static fn (array $permissions, array $routes): array => [
    $entireConfig,
    step('fixture.script', ['routes' => [$mint($permissions), ['method' => 'GET', 'path' => $p . '/repositories', 'body' => ['repositories' => $app, 'next_cursor' => '']], ...$cellRoutes, ...$routes]]),
    $newEntire(['endpoint' => $core]),
    $entireInit,
];
$suiteRuns = [
    ['suite_key' => 'Atlas Production', 'key' => 'Billing', 'status' => 'in_progress', 'conclusion' => null],
    ['suite_key' => 'Atlas Production', 'key' => 'Hello', 'status' => 'completed', 'conclusion' => 'failure'],
    ['suite_key' => 'Atlas Staging', 'key' => 'Billing (staging)', 'status' => 'in_progress', 'conclusion' => null],
    ['suite_key' => 'production', 'key' => 'production/sites/landing', 'status' => 'queued'],
    'x',
];
$checkPath = $c . '/repos/repo_1/commits/abc123/check-runs';
$deploy = static fn (array $args): array => call('update_deployment', ['owner' => 'test-project', 'repositoryName' => 'app', 'SHA' => 'abc123'] + $args);
$createRoutes = static fn (array $creates, array $listings): array => [
    ...array_map(static fn (array $answer): array => ['method' => 'POST', 'path' => '/entire-core/api/v1/repos', 'times' => 1, 'status' => $answer[0], 'headers' => $problemHeaders, 'body' => $answer[1]], $creates),
    ...array_map(static fn (array $repositories): array => ['method' => 'GET', 'path' => $p . '/repositories', 'times' => 1, 'body' => ['repositories' => $repositories, 'next_cursor' => '']], $listings),
];
$pricing = static fn (array $placements): array => [['id' => 'repo_01M5NEW', 'full_name' => 'et/test-project/pricing-site', 'clone_url' => 'https://aws-eu-central-1.entire.io/et/test-project/pricing-site.git', 'placements' => $placements]];
$placed = [['region' => 'aws-eu-central-1', 'role' => 'processing_primary', 'api_base_url' => '${base}/entire-cell']];
$create = static fn (bool $private = true): array => call('create_repository', ['owner' => 'test-project', 'repositoryName' => 'pricing-site', 'private' => $private]);
$minting = static fn (array $routes, array $seed = []): array => [
    $entireConfig,
    step('fixture.script', ['routes' => $routes]),
    step('vcs.new', ['adapter' => 'entire', 'endpoint' => $core, 'issuer' => $issuer] + ($seed === [] ? [] : ['seed' => $seed]), 'a'),
    $entireInit,
];
$cachedWithout = ['entire-ins_fixture' => (string) json_encode(['accessToken' => 'tok_cached', 'expiresAt' => 4102444800, 'permissions' => ['contents' => 'read', 'checks' => 'write']])];
$refused = 'permission_not_accepted';
array_push(
    $list,
    ['name' => 'testGetEventFromAGithubMirror', 'steps' => [
        $newEntire(),
        $events('ref.updated', array_replace_recursive($refPayload('ref.updated', 'refs/heads/main'), ['event' => ['payload' => ['repository' => ['path' => '/gh/octocat/hello-world']]]])),
    ]],
    ['name' => 'testMirrorsOfSeveralGithubOwners', 'steps' => [
        $entireConfig,
        step('fixture.script', ['routes' => [
            $mint($all),
            ['method' => 'GET', 'path' => $p . '/repositories', 'body' => ['repositories' => [$mirror('repo_01M5HELLO', 'Octocat/hello-world'), $mirror('repo_01M5DOCS', 'appwrite/docs'), ['id' => 'repo_x', 'full_name' => '', 'path' => 'gh/Other/docs2']], 'next_cursor' => '']],
        ]]),
        $newEntire(['endpoint' => $core]),
        call('get_repository_url', ['owner' => 'gh/Octocat', 'repositoryName' => 'docs']),
        $entireInit,
        call('get_owner_name', ['installationId' => 'ins_fixture']),
        call('search_repositories', ['owner' => 'gh/Octocat', 'page' => 1, 'per_page' => 10]),
        call('get_repository_clone_url', ['owner' => 'gh/Octocat', 'repositoryName' => 'docs']),
        call('get_repository_url', ['owner' => 'gh/Octocat', 'repositoryName' => 'docs']),
        call('get_repository_url', ['owner' => 'gh/Octocat', 'repositoryName' => 'missing']),
        call('get_commit_url', ['owner' => 'gh/octocat', 'repositoryName' => 'hello-world', 'commitHash' => 'abc']),
        call('get_branch_url', ['owner' => 'gh/octocat', 'repositoryName' => 'hello-world', 'branch' => 'feature/pricing']),
        call('get_file_url', ['owner' => 'gh/octocat', 'repositoryName' => 'hello-world', 'reference' => 'main/docs/guide.md']),
        call('get_organization_url', ['owner' => 'gh/octocat']),
        step('fixture.requests'),
    ]],
    ['name' => 'testGetMergeBase', 'steps' => [
        ...$withPermissions($all, [
            ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/merge-base?commitA=main&commitB=missing', 'status' => 404, 'headers' => $problemHeaders, 'body' => ['code' => 'not_found', 'error' => 'no such commit']],
            ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/merge-base', 'body' => ['mergeBaseSHA' => 'abc1234567abc1234567abc1234567abc1234567', 'resolvedSHAs' => ['abc1234567abc1234567abc1234567abc1234567', $sha]]],
        ]),
        call('get_merge_base', $repo + ['commitA' => 'main', 'commitB' => $sha]),
        call('get_merge_base', $repo + ['commitA' => 'main', 'commitB' => 'missing']),
        call('get_merge_base', ['owner' => 'test-project', 'repositoryName' => 'missing', 'commitA' => 'main', 'commitB' => $sha]),
        step('fixture.requests'),
    ]],
    ['name' => 'testCheckRunsShareTheirSuite', 'steps' => [
        ...$withPermissions($all, [
            ['method' => 'POST', 'path' => $checkPath, 'status' => 201, 'body' => ['ok' => true]],
            ['method' => 'GET', 'path' => $checkPath, 'body' => ['check_runs' => $suiteRuns]],
        ]),
        call('update_check', $repo + ['SHA' => 'abc123', 'state' => 'success', 'name' => 'Billing', 'title' => 'Build succeeded.', 'suite' => 'Atlas Production']),
        call('update_check', $repo + ['SHA' => 'abc123', 'state' => 'success', 'name' => 'Hello', 'title' => 'Build succeeded.', 'suite' => 'Atlas Production']),
        call('update_check', $repo + ['SHA' => 'abc123', 'state' => 'pending', 'name' => 'Billing (staging)', 'title' => 'Building...', 'suite' => 'Atlas Staging', 'detailsUrl' => 'https://x.example.com']),
        call('update_check', $repo + ['SHA' => 'abc123', 'state' => 'queued', 'name' => 'Fresh', 'title' => 'Queued', 'suite' => 'Atlas Fresh']),
        call('update_check', $repo + ['SHA' => 'abc123', 'state' => 'cancelled', 'name' => 'Gone', 'title' => 'Cancelled', 'suite' => 'Atlas Gone']),
        step('fixture.requests'),
    ]],
    ['name' => 'testCheckRunsAreKeyedApartFromTheirNames', 'steps' => [
        ...$withPermissions($all, [
            ['method' => 'POST', 'path' => $checkPath, 'status' => 201, 'body' => ['ok' => true]],
            ['method' => 'GET', 'path' => $checkPath, 'body' => ['check_runs' => $suiteRuns]],
        ]),
        call('update_check', $repo + ['SHA' => 'abc123', 'state' => 'success', 'name' => 'Landing', 'title' => 'Build succeeded.', 'suite' => 'Atlas', 'key' => 'production/sites/landing', 'suiteKey' => 'production']),
        call('update_check', $repo + ['SHA' => 'abc123', 'state' => 'failure', 'name' => 'Landing', 'title' => 'Build failed.', 'suite' => 'Atlas', 'key' => 'staging/sites/landing', 'suiteKey' => 'staging']),
        call('update_check', $repo + ['SHA' => 'abc123', 'state' => 'success', 'name' => '', 'title' => 'Done.', 'key' => 'k1']),
        call('update_check', $repo + ['SHA' => 'abc123', 'state' => 'success', 'name' => 'Solo', 'title' => 'Done.', 'suiteKey' => 'solo-suite']),
        step('fixture.requests'),
    ]],
    ['name' => 'testSharedSuiteIsStatedFromEveryRun', 'steps' => [
        ...$withPermissions($all, [
            ['method' => 'POST', 'path' => $checkPath, 'status' => 201, 'body' => ['ok' => true]],
            ['method' => 'GET', 'path' => $checkPath, 'times' => 1, 'status' => 403, 'headers' => $problemHeaders, 'body' => ['code' => 'forbidden', 'detail' => 'checks:read']],
            ['method' => 'GET', 'path' => $checkPath, 'body' => ['check_runs' => [['suite_key' => 'S', 'key' => 'a', 'status' => 'completed', 'conclusion' => 'skipped'], ['suite_key' => 'S', 'key' => 'b', 'status' => 'completed', 'conclusion' => 'timed_out'], ['suite_key' => 'S', 'key' => 'c', 'status' => 'completed', 'conclusion' => 'odd']]]],
        ]),
        call('update_check', $repo + ['SHA' => 'abc123', 'state' => 'success', 'name' => 'Billing', 'title' => 'Build succeeded.', 'suite' => 'Atlas Production']),
        call('update_check', $repo + ['SHA' => 'abc123', 'state' => 'success', 'name' => 'a', 'title' => 'Done.', 'suite' => 'S']),
        call('update_check', $repo + ['SHA' => 'abc123', 'state' => 'unknown', 'name' => 'd', 'title' => 'Done.', 'suite' => 'S']),
        step('fixture.requests'),
    ]],
    ['name' => 'testUpdateDeployment', 'steps' => [
        ...$withPermissions($all, [
            ['method' => 'POST', 'path' => '/entire-cell/api/v0/repos/repo_1/commits/abc123/deployments', 'times' => 2, 'status' => 201, 'body' => ['ok' => true]],
            ['method' => 'POST', 'path' => '/entire-cell/api/v0/repos/repo_1/commits/abc123/deployments', 'status' => 422, 'headers' => $problemHeaders, 'body' => ['code' => 'invalid', 'title' => 'Unprocessable']],
        ]),
        $deploy(['key' => 'production/landing', 'name' => 'Landing', 'state' => 'success', 'environment' => 'production', 'url' => 'https://landing.appwrite.network', 'logsUrl' => 'https://cloud.appwrite.io/console/logs', 'type' => 'site', 'groupKey' => 'production', 'groupName' => 'Production']),
        $deploy(['key' => 'p/billing', 'name' => 'Billing', 'state' => 'pending', 'logsUrl' => 'http://localhost/console/logs', 'type' => 'function', 'groupKey' => 'p']),
        $deploy(['key' => 'p/landing', 'name' => 'Landing', 'state' => 'inactive', 'url' => 'https://landing.appwrite.network', 'logsUrl' => 'https://cloud.appwrite.io/console/logs']),
        $deploy(['key' => 'p/x', 'name' => 'X', 'state' => 'odd']),
        step('fixture.requests'),
    ]],
    ['name' => 'testUpdateDeploymentNeedsTheDeploymentsPermission', 'steps' => [
        ...$withPermissions(['contents' => 'read', 'checks' => 'write'], []),
        $deploy(['key' => 'p/landing', 'name' => 'Landing', 'state' => 'success']),
        step('fixture.requests'),
    ]],
    ['name' => 'testCreateRepositoryWaitsForItsPlacement', 'steps' => [
        ...$minting([$mint($all), ...$createRoutes([[201, ['repositoryId' => 'repo_01M5NEW', 'name' => 'pricing-site', 'owningProjectId' => '01M4ZC', 'state' => 'provisioning']]], [$pricing([]), $pricing($placed)])]),
        $create(false),
        call('get_repository_url', ['owner' => 'test-project', 'repositoryName' => 'pricing-site']),
        step('fixture.requests'),
    ]],
    ['name' => 'testCreateRepositoryOnTheProjectsHomeCore', 'steps' => [
        ...$minting([
            $mint($all, 1),
            ['method' => 'POST', 'path' => $p . '/access-tokens', 'body' => ['token' => 'home', 'expires_at' => '2100-01-01T00:00:00Z', 'permissions' => ['repositories' => 'create', 'installations' => 'read']]],
            ...$createRoutes([
                [421, ['error' => 'project "test-project" is homed in jurisdiction "eu"; retry against the home core', 'home_core_url' => $core . '/', 'jurisdiction' => 'eu']],
                [201, ['repositoryId' => 'repo_01M5NEW', 'name' => 'pricing-site', 'owningProjectId' => '01M4ZC', 'state' => 'active']],
            ], [$pricing($placed)]),
        ]),
        $create(),
        step('fixture.requests'),
    ]],
    ['name' => 'testCreateRepositoryFollowsOnlyAKnownCore', 'steps' => [
        ...$minting([$mint($all), ...$createRoutes([[421, ['error' => 'retry against the home core', 'home_core_url' => 'https://core.example.com']]], [])]),
        $create(),
        step('fixture.requests'),
    ]],
    ['name' => 'testCreateRepositoryNamesAnExistingRepository', 'steps' => [
        ...$minting([$mint($all), ...$createRoutes([[409, ['type' => 'https://entire.com/errors/name-in-use', 'title' => 'Conflict']], [500, ['title' => 'Internal']]], [])]),
        $create(),
        $create(),
        step('fixture.requests'),
    ]],
    ['name' => 'testCreateRepositoryNeedsTheRepositoriesPermission', 'steps' => [
        ...$minting([$mint(['contents' => 'read', 'checks' => 'write'])], $cachedWithout),
        $create(),
        $create(),
        step('fixture.requests'),
    ]],
    ['name' => 'testCreateRepositoryRefreshesATokenCachedBeforeThePermission', 'steps' => [
        ...$minting([$mint($all), ...$createRoutes([[201, ['repositoryId' => 'repo_01M5NEW', 'name' => 'pricing-site', 'state' => 'provisioning']]], [$pricing($placed)])], $cachedWithout),
        $create(),
        step('fixture.requests'),
    ]],
    ['name' => 'testAccessTokenFallsBackToAcceptedPermissions', 'steps' => [
        ...$minting([$refusal('unsupported_permission', 400), $refusal('repository_required', 400), $refusal($refused, 403), $refusal('coverage_changed', 409), $refusal($refused, 403), $refusal($refused, 403), $mint(['contents' => 'read', 'checks' => 'write', 'odd' => 7, 3 => 'x'])]),
        $deploy(['key' => 'p/landing', 'name' => 'Landing', 'state' => 'success']),
        step('fixture.requests'),
    ]],
    ['name' => 'testAccessTokenRefusalIsNotRetried', 'steps' => [
        ...$minting([$refusal('installation_suspended', 403)]),
        step('fixture.requests'),
    ]],
    ['name' => 'testAccessTokenFailsOnceEverySetIsRefused', 'steps' => [
        ...$minting([['method' => 'POST', 'path' => $p . '/access-tokens', 'status' => 400, 'headers' => $problemHeaders, 'body' => ['code' => 'repository_required', 'detail' => 'name a repository']]]),
        step('fixture.requests'),
    ]],
);
write("{$cases}/EntireTest.json", ['source' => 'packages/vcs/tests/EntireTest.php', 'cases' => $list]);

/*
 * GitTest: the shared checkout write, through Origin's createFile on a
 * repository whose tree carries symbolic links out of the checkout. Each
 * write through a link is refused before anything is created.
 */
$linked = ['owner' => 'test-owner', 'name' => 'app', 'files' => ['README.md' => "# App\n"], 'links' => ['linked' => '../../outside', 'linked.txt' => '../../outside/target.txt']];
$write = static fn (string $path): array => call('create_file', ['owner' => 'test-owner', 'repositoryName' => 'app', 'filepath' => $path, 'content' => 'content', 'message' => 'Write ' . $path]);
$gitSteps = static fn (array $writes): array => [
    step('fixture.config', ['config' => ['origin' => ['ed25519' => $b64url(sodium_crypto_sign_publickey($appKeys)), 'repositories' => [$linked]]]]),
    step('vcs.new', ['adapter' => 'origin', 'endpoint' => '${vcs}/~${ns}/origin', 'web' => '${vcs}/~${ns}/origin/web', 'git' => '${vcs-tls}/~${ns}/origin/git'], 'a'),
    call('initialize_variables', ['installationId' => 'i_fixture', 'privateKey' => $appPem, 'appId' => 'app_fixture']),
    ...$writes,
];
write("{$cases}/GitTest.json", ['source' => 'packages/vcs/tests/GitTest.php', 'cases' => [
    ['name' => 'testWritesInsideTheCheckout', 'steps' => $gitSteps([
        $write('docs/./guide.md'),
        call('get_repository_content', ['owner' => 'test-owner', 'repositoryName' => 'app', 'path' => 'docs/guide.md']),
    ])],
    ['name' => 'testRefusesASymlinkedDirectory', 'steps' => $gitSteps([$write('linked/escape.txt')])],
    ['name' => 'testRefusesASymlinkedParentBeforeCreatingAnythingBeneathIt', 'steps' => $gitSteps([$write('linked/new/escape.txt')])],
    ['name' => 'testRefusesASymlinkedFile', 'steps' => $gitSteps([$write('linked.txt')])],
]]);

/*
 * Entire's API end to end against scripted core and cell routes: the
 * plugin's installation, repositories it covers, refs, trees, contents,
 * commits, trails, discussions and check runs, and Git writes through a
 * clone URL that points at the fixture server's own Git host.
 */
$repositories = [
    ['id' => 'repo_1', 'full_name' => 'et/test-project/app', 'clone_url' => 'https://cell.entire.test/et/test-project/app.git', 'entire_clone_url' => '', 'placements' => [
        ['api_base_url' => 'https://replica.entire.test/', 'role' => 'replica'],
        ['api_base_url' => '${base}/entire-cell/', 'role' => 'processing_primary'],
        'not a placement',
    ]],
    ['id' => 'repo_2', 'full_name' => 'et/test-project/website', 'clone_url' => 'ssh://cell.entire.test/et/test-project/website', 'placements' => []],
];
$problemHeaders = ['Content-Type' => 'application/problem+json'];
$entireApi = [
    ['method' => 'POST', 'path' => $p . '/access-tokens', 'times' => 1, 'status' => 400, 'headers' => $problemHeaders, 'body' => ['code' => 'unsupported_permission', 'title' => 'Bad Request']],
    ['method' => 'POST', 'path' => $p . '/access-tokens', 'body' => ['token' => 'tok_fixture', 'expires_at' => '2100-01-01T00:00:00Z']],
    ['method' => 'GET', 'path' => $p . '/repositories?cursor=c2', 'body' => ['repositories' => [['id' => 'repo_3', 'full_name' => 'gh/other-owner/lib.git', 'entire_clone_url' => 'entire://cell.entire.test/gh/other-owner/lib', 'placements' => [['api_base_url' => '${base}/entire-cell']]], 'skip me'], 'next_cursor' => '']],
    ['method' => 'GET', 'path' => $p . '/repositories', 'body' => ['repositories' => $repositories, 'next_cursor' => 'c2']],
    ['method' => 'GET', 'path' => $c . '/repos/repo_1', 'body' => ['id' => 'grepo_1', 'default_branch' => 'main']],
    ['method' => 'GET', 'path' => $c . '/repos/repo_3', 'status' => 404, 'headers' => $problemHeaders, 'body' => ['code' => 'not_found']],
    ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/branches?pageSize=100&pageToken=p2', 'body' => ['branches' => [['name' => 'refs/heads/dev'], 'refs/heads/raw', 7], 'nextPageToken' => '']],
    ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/branches', 'body' => ['branches' => [['name' => 'refs/heads/main'], ['name' => 'feature']], 'nextPageToken' => 'p2']],
    ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/tags?pageSize=100&pageToken=same', 'body' => ['tags' => [], 'nextPageToken' => 'same']],
    ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/tags', 'body' => ['tags' => [['name' => 'refs/tags/v1.0.0'], ['name' => 'v1.1.0'], ['name' => 'v2.0.0']], 'nextPageToken' => 'same']],
    ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/tree?ref=missing', 'status' => 404, 'body' => ['code' => 'not_found']],
    ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/tree', 'body' => ['entries' => [['path' => 'README.md', 'type' => 'blob', 'size' => 12], ['path' => 'src', 'type' => 'tree'], ['path' => 'src/index.js', 'size' => '20'], ['path' => ''], 'x']]],
    ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/raw?ref=main&path=README.md', 'headers' => ['Content-Type' => 'text/plain'], 'body' => "# App\n"],
    ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/raw?ref=v1&path=docs%2Fguide.md', 'headers' => ['Content-Type' => 'application/octet-stream'], 'body' => "# Guide\n"],
    ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/raw', 'status' => 404, 'body' => ['code' => 'not_found']],
    ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/commits?ref=missing&pageSize=1', 'status' => 404, 'headers' => $problemHeaders, 'body' => ['code' => 'not_found', 'detail' => 'no such ref']],
    ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/commits?ref=empty&pageSize=1', 'body' => ['commits' => []]],
    ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/commits?ref=broken&pageSize=1', 'status' => 502, 'body' => ['code' => 'bad_gateway']],
    ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/commits?ref=anon&pageSize=1', 'body' => ['commits' => [['sha' => '', 'author' => 'nobody']]]],
    ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/commits', 'body' => ['commits' => [['sha' => $sha, 'message' => 'Initial commit', 'author' => ['name' => 'Ada', 'email' => 'ada@example.com']]]]],
    ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/compare?base=bad&head=worse', 'status' => 422, 'headers' => $problemHeaders, 'body' => ['code' => 'invalid_ref', 'title' => 'Unprocessable']],
    ['method' => 'GET', 'path' => $c . '/git/repos/grepo_1/compare', 'body' => ['files' => [['path' => 'a.txt', 'status' => 'added'], ['path' => 'b.txt', 'status' => 'renamed', 'previousPath' => 'old.txt'], 'x']]],
    ['method' => 'GET', 'path' => $c . '/repos/repo_1/trails?status=open&per_page=100&cursor=n2', 'body' => ['items' => [['id' => 'trl_2', 'number' => 2, 'title' => 'Feature', 'status' => 'draft', 'branch' => 'feature', 'head_sha' => 'h2', 'base' => 'main', 'base_sha' => 'b2', 'merge_base_sha' => 'm2']], 'next_cursor' => '']],
    ['method' => 'GET', 'path' => $c . '/repos/repo_1/trails', 'body' => ['items' => [['branch' => 'other', 'number' => 1], 'x'], 'next_cursor' => 'n2']],
    ['method' => 'GET', 'path' => $c . '/repos/repo_1/trails/2', 'body' => ['id' => 'trl_2', 'number' => '2', 'title' => 'Feature', 'status' => 'open', 'branch' => 'feature', 'head_sha' => 'h2', 'base' => 'main', 'base_sha' => 'b2', 'merge_base_sha' => 'm2']],
    ['method' => 'GET', 'path' => $c . '/repos/repo_1/trails/3', 'body' => ['id' => 'trl_3', 'number' => 3, 'status' => 'closed', 'branch' => 'gone', 'head_sha' => 'h3', 'base' => 'main', 'merged_at' => '2026-01-01T00:00:00Z']],
    ['method' => 'GET', 'path' => $c . '/repos/repo_1/trails/4', 'body' => ['id' => 'trl_4', 'number' => 4, 'status' => 'open', 'branch' => 'x']],
    ['method' => 'GET', 'path' => $c . '/repos/repo_1/trails/99', 'status' => 404, 'headers' => $problemHeaders, 'body' => ['code' => 'not_found', 'title' => 'Not Found']],
    ['method' => 'POST', 'path' => $c . '/repos/repo_1/trails', 'times' => 1, 'status' => 201, 'body' => ['trail' => ['id' => 'trl_5', 'number' => 5, 'title' => 'Add feature', 'status' => 'open', 'branch' => 'feature', 'base' => 'main']]],
    ['method' => 'POST', 'path' => $c . '/repos/repo_1/trails', 'times' => 1, 'status' => 201, 'body' => ['id' => 'trl_6', 'number' => 6, 'title' => 'Flat', 'status' => 'open']],
    ['method' => 'POST', 'path' => $c . '/repos/repo_1/trails', 'status' => 409, 'body' => ['code' => 'conflict', 'detail' => 'branch is linked']],
    ['method' => 'POST', 'path' => $c . '/repos/repo_1/trails/2/discussions', 'status' => 201, 'body' => ['discussion' => ['id' => 'dsc_1']]],
    ['method' => 'POST', 'path' => $c . '/repos/repo_1/trails/3/discussions', 'status' => 403, 'body' => ['code' => 'forbidden']],
    ['method' => 'POST', 'path' => $c . '/repos/repo_1/trails/4/discussions', 'status' => 201, 'body' => ['discussion' => []]],
    ['method' => 'POST', 'path' => $c . '/repos/repo_1/trails/5/discussions', 'status' => 503, 'body' => ['title' => 'Unavailable']],
    ['method' => 'GET', 'path' => $c . '/repos/repo_1/trails/2/discussions/dsc_1', 'body' => ['messages' => [['id' => 'msg_1', 'body' => 'Looks good']]]],
    ['method' => 'GET', 'path' => $c . '/repos/repo_1/trails/2/discussions/dsc_empty', 'body' => ['messages' => []]],
    ['method' => 'PATCH', 'path' => $c . '/repos/repo_1/trails/2/discussions/dsc_1/messages/msg_1', 'body' => ['message' => ['id' => 'msg_1']]],
    ['method' => 'POST', 'path' => $c . '/git/repos/grepo_1/branches-containing', 'body' => [$sha => ['refs/heads/main'], 'abc' => []]],
    ['method' => 'POST', 'path' => $c . '/repos/repo_1/commits/' . $sha . '/check-runs', 'status' => 201, 'body' => ['check_runs' => []]],
    ['method' => 'GET', 'path' => $c . '/repos/repo_1/commits/' . $sha . '/check-runs', 'body' => ['check_runs' => [
        ['status' => 'completed', 'conclusion' => 'success', 'key' => 'build', 'name' => 'Build', 'output' => ['title' => 'Built'], 'details_url' => 'https://x.example.com'],
        ['status' => 'in_progress', 'name' => 'lint'],
        ['status' => 'completed', 'conclusion' => 'neutral', 'key' => '', 'name' => 'n'],
        ['status' => 'completed', 'conclusion' => 'skipped', 'key' => 's'],
        ['status' => 'completed', 'conclusion' => 'failure', 'key' => 't'],
        'x',
    ]]],
    ['method' => 'GET', 'path' => $c . '/repos/repo_1/commits/abc/check-runs', 'status' => 403, 'body' => ['code' => 'forbidden', 'detail' => 'checks:read']],
];
$repo = ['owner' => 'test-project', 'repositoryName' => 'app'];
$list = [];
$list[] = ['name' => 'installation, repositories, refs and contents', 'steps' => [
    $entireConfig,
    step('fixture.script', ['routes' => $entireApi]),
    $newEntire(['endpoint' => $core]),
    call('initialize_variables', ['installationId' => 'ins_fixture', 'privateKey' => $appPem]),
    $entireInit,
    call('get_owner_name', ['installationId' => 'ins_fixture']),
    call('search_repositories', ['owner' => 'test-project', 'page' => 1, 'per_page' => 10]),
    call('search_repositories', ['owner' => 'TEST-PROJECT', 'page' => 1, 'per_page' => 10, 'search' => 'WEB']),
    call('search_repositories', ['owner' => '', 'page' => 2, 'per_page' => 1]),
    call('search_repositories', ['owner' => 'nobody', 'page' => 1, 'per_page' => 10]),
    call('get_installation_repository', ['repositoryName' => 'App']),
    call('get_installation_repository', ['repositoryName' => 'missing']),
    call('get_repository_name', ['repositoryId' => 'repo_2']),
    call('get_repository_name', ['repositoryId' => 'repo_missing']),
    call('get_repository', $repo),
    call('get_repository', ['owner' => 'other-owner', 'repositoryName' => 'lib']),
    call('get_repository', ['owner' => 'test-project', 'repositoryName' => 'website']),
    call('get_repository', ['owner' => 'test-project', 'repositoryName' => 'missing']),
    call('list_branches', $repo),
    call('list_branches', ['owner' => 'test-project', 'repositoryName' => 'missing']),
    call('list_tags', $repo),
    call('list_tags', $repo + ['search' => 'v1.*']),
    call('list_tags', ['owner' => 'other-owner', 'repositoryName' => 'lib']),
    call('get_repository_tree', $repo + ['branch' => 'main']),
    call('get_repository_tree', $repo + ['branch' => 'main', 'recursive' => true]),
    call('get_repository_tree', $repo + ['branch' => 'missing']),
    call('get_repository_tree', ['owner' => 'test-project', 'repositoryName' => 'missing', 'branch' => 'main']),
    call('list_repository_contents', $repo),
    call('list_repository_contents', $repo + ['path' => './src/', 'ref' => 'v1']),
    call('list_repository_contents', ['owner' => 'test-project', 'repositoryName' => 'missing']),
    call('get_repository_content', $repo + ['path' => 'README.md']),
    call('get_repository_content', $repo + ['path' => '/docs/guide.md', 'ref' => 'v1']),
    call('get_repository_content', $repo + ['path' => 'missing.md']),
    call('get_repository_content', ['owner' => 'test-project', 'repositoryName' => 'missing', 'path' => 'README.md']),
    call('get_commit', $repo + ['commitHash' => $sha]),
    call('get_commit', $repo + ['commitHash' => 'missing']),
    call('get_commit', $repo + ['commitHash' => 'empty']),
    call('get_commit', $repo + ['commitHash' => 'broken']),
    call('get_commit', $repo + ['commitHash' => 'anon']),
    call('get_commit', ['owner' => 'test-project', 'repositoryName' => 'missing', 'commitHash' => $sha]),
    call('get_latest_commit', $repo + ['branch' => 'main']),
    call('get_latest_commit', $repo + ['branch' => 'missing']),
    call('get_latest_commit', $repo + ['branch' => 'broken']),
    call('compare_files', $repo + ['base' => 'main', 'head' => 'feature']),
    call('compare_files', $repo + ['base' => 'bad', 'head' => 'worse']),
    call('get_repository_clone_url', $repo),
    call('get_repository_clone_url', ['owner' => 'other-owner', 'repositoryName' => 'lib']),
    call('get_repository_clone_url', ['owner' => 'test-project', 'repositoryName' => 'website']),
    call('generate_clone_command', $repo + ['version' => 'main', 'versionType' => 'branch', 'directory' => '/tmp/compat-fs/${ns}/clone', 'rootDirectory' => 'src']),
    call('generate_clone_command', ['owner' => 'test-project', 'repositoryName' => 'website', 'version' => 'main', 'versionType' => 'branch', 'directory' => '/tmp/x', 'rootDirectory' => '']),
    call('generate_push_command', $repo + ['directory' => '/tmp/compat-fs/${ns}/clone', 'branch' => 'main']),
    call('get_user', ['username' => 'someone']),
    call('create_repository', $repo + ['private' => true]),
    call('delete_repository', $repo),
    call('create_webhook', $repo + ['url' => 'https://appwrite.test/hook', 'secret' => 'secret']),
    step('fixture.requests'),
]];
$list[] = ['name' => 'trails, discussions and check runs', 'steps' => [
    $entireConfig,
    step('fixture.script', ['routes' => $entireApi]),
    $newEntire(['endpoint' => $core]),
    $entireInit,
    call('set_repository_endpoint', ['repositoryId' => 'repo_1', 'apiBaseUrl' => $cell . '/']),
    call('set_repository_endpoint', ['repositoryId' => '', 'apiBaseUrl' => $cell]),
    call('get_pull_request_from_branch', $repo + ['branch' => 'feature']),
    call('get_pull_request_from_branch', $repo + ['branch' => 'nothing']),
    call('get_pull_request', $repo + ['pullRequestNumber' => 2]),
    call('get_pull_request', $repo + ['pullRequestNumber' => 3]),
    call('get_pull_request', $repo + ['pullRequestNumber' => 99]),
    call('get_pull_request_files', $repo + ['pullRequestNumber' => 2]),
    call('get_pull_request_files', $repo + ['pullRequestNumber' => 3]),
    call('get_pull_request_files', $repo + ['pullRequestNumber' => 4]),
    call('create_pull_request', $repo + ['title' => 'Add feature', 'head' => 'feature', 'base' => 'main', 'body' => 'Adds it']),
    call('create_pull_request', $repo + ['title' => 'Flat', 'head' => 'flat', 'base' => 'main']),
    call('create_pull_request', $repo + ['title' => 'Again', 'head' => 'feature', 'base' => 'main']),
    call('create_comment', $repo + ['pullRequestNumber' => 2, 'comment' => "## **Appwrite** build\n\n| Site | Status |\n|---|---|"]),
    call('create_comment', $repo + ['pullRequestNumber' => 3, 'comment' => 'Refused']),
    call('create_comment', $repo + ['pullRequestNumber' => 4, 'comment' => "\n#\n" . str_repeat('ü', 90)]),
    call('create_comment', $repo + ['pullRequestNumber' => 5, 'comment' => '###']),
    call('get_comment', $repo + ['commentId' => '2-dsc_1']),
    call('get_comment', $repo + ['commentId' => '2-dsc_empty']),
    call('get_comment', $repo + ['commentId' => 'no-separator-here']),
    call('get_comment', $repo + ['commentId' => 'nodash']),
    call('update_comment', $repo + ['commentId' => '2-dsc_1', 'comment' => 'Looks great']),
    call('update_comment', $repo + ['commentId' => 'nodash', 'comment' => 'x']),
    call('update_comment', $repo + ['commentId' => '2-dsc_empty', 'comment' => 'x']),
    call('update_commit_status', ['repositoryName' => 'app', 'SHA' => $sha, 'owner' => 'test-project', 'state' => 'success', 'description' => 'Built', 'target_url' => 'https://appwrite.test/build', 'context' => 'appwrite']),
    call('update_commit_status', ['repositoryName' => 'app', 'SHA' => 'abc', 'owner' => 'test-project', 'state' => 'pending']),
    call('update_check', $repo + ['SHA' => $sha, 'state' => 'queued', 'name' => 'Site', 'title' => 'Queued']),
    call('get_commit_statuses', $repo + ['commitHash' => $sha]),
    call('get_commit_statuses', $repo + ['commitHash' => 'abc']),
    step('fixture.requests'),
]];
$gitRepository = ['owner' => 'test-owner', 'name' => 'app', 'files' => ['README.md' => "# App\n", 'src/index.js' => "console.log('app');\n"]];
$list[] = ['name' => 'Git writes through the remote', 'steps' => [
    step('fixture.config', ['config' => ['origin' => ['ed25519' => $b64url(sodium_crypto_sign_publickey($appKeys)), 'repositories' => [$gitRepository]]]]),
    step('fixture.script', ['routes' => [
        ['method' => 'POST', 'path' => $p . '/access-tokens', 'body' => ['token' => 'tok_fixture', 'expires_at' => '2100-01-01T00:00:00Z']],
        ['method' => 'GET', 'path' => $p . '/repositories', 'body' => ['repositories' => [['id' => 'repo_1', 'full_name' => 'et/test-project/app', 'entire_clone_url' => 'https://x-access-token:tok_fixture@${vcs-tls-host}/~${ns}/origin/git/test-owner/app.git', 'placements' => [['api_base_url' => '${base}/entire-cell', 'role' => 'processing_primary']]]]]],
        ['method' => 'GET', 'path' => $c . '/repos/repo_1', 'body' => ['id' => 'grepo_1', 'default_branch' => 'main']],
    ]]),
    $newEntire(['endpoint' => $core]),
    $entireInit,
    call('create_file', $repo + ['filepath' => 'notes/todo.md', 'content' => "- ship\n", 'message' => 'Add notes'], 'f'),
    call('create_branch', $repo + ['newBranchName' => 'feature', 'oldBranchName' => 'main']),
    call('create_file', $repo + ['filepath' => 'src/feature.js', 'content' => "export const feature = true;\n", 'branch' => 'feature']),
    call('create_file', $repo + ['filepath' => 'src/new.js', 'content' => "new\n", 'branch' => 'fresh']),
    call('create_tag', $repo + ['tagName' => 'v1.0.0', 'target' => ref('f', '/commitHash'), 'message' => 'First release']),
    call('create_tag', $repo + ['tagName' => 'v1.0.1', 'target' => ref('f', '/commitHash')]),
    call('create_tag', $repo + ['tagName' => 'v1.0.2', 'target' => 'main']),
    call('create_branch', $repo + ['newBranchName' => 'other', 'oldBranchName' => 'missing']),
    call('generate_clone_command', $repo + ['version' => 'feature', 'versionType' => 'branch', 'directory' => '/tmp/compat-fs/${ns}/clone', 'rootDirectory' => 'src'], 'cmd'),
    step('clone.run', ['command' => ref('cmd'), 'directory' => '/tmp/compat-fs/${ns}/clone', 'workspace' => '/tmp/compat-fs/${ns}']),
    step('fixture.requests'),
]];
$list[] = ['name' => 'a plugin the core refuses', 'steps' => [
    $entireConfig,
    step('fixture.script', ['routes' => [
        ['method' => 'POST', 'path' => '/entire-core/api/v1/plugins/appwrite/installations/ins_denied/access-tokens', 'status' => 403, 'headers' => $problemHeaders, 'body' => ['type' => 'about:blank', 'code' => 'installation_suspended', 'title' => 'Forbidden', 'detail' => 'suspended', 'instance' => 'hidden']],
        ['method' => 'POST', 'path' => '/entire-core/api/v1/plugins/appwrite/installations/ins_plain/access-tokens', 'status' => 503, 'headers' => ['Content-Type' => 'text/plain'], 'body' => 'unavailable'],
    ]]),
    $newEntire(['endpoint' => $core . '/']),
    call('initialize_variables', ['installationId' => 'ins_denied', 'privateKey' => $appPem, 'appId' => 'appwrite']),
    call('initialize_variables', ['installationId' => 'ins_plain', 'privateKey' => $appPem, 'appId' => 'appwrite']),
    call('initialize_variables', ['installationId' => 'ins_x', 'privateKey' => 'not a key', 'appId' => 'appwrite']),
    call('initialize_variables', ['installationId' => 'ins_x', 'privateKey' => base64_encode(str_repeat("\x05", 16)), 'appId' => 'appwrite']),
    call('initialize_variables', ['installationId' => 'ins_x', 'privateKey' => "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----", 'appId' => 'appwrite']),
    call('initialize_variables', ['installationId' => 'ins_x', 'privateKey' => $appPem, 'appId' => '0']),
    call('get_owner_name', ['installationId' => 'ins_plain']),
    step('fixture.requests'),
]];
$list[] = ['name' => 'a cached installation token', 'steps' => [
    $entireConfig,
    step('fixture.script', ['routes' => [
        ['method' => 'POST', 'path' => '/entire-core/api/v1/plugins/appwrite/installations/ins_stale/access-tokens', 'body' => ['token' => 'tok_fresh', 'expires_at' => 'not a date']],
        ['method' => 'POST', 'path' => '/entire-core/api/v1/plugins/appwrite/installations/ins_bad/access-tokens', 'body' => ['token' => 'tok_bad']],
    ]]),
    step('vcs.new', ['adapter' => 'entire', 'endpoint' => $core, 'issuer' => $issuer, 'seed' => [
        'entire-ins_fixture' => '{"accessToken":"tok_cached","expiresAt":4102444800}',
        'entire-ins_stale' => '{"accessToken":"tok_stale","expiresAt":1767225600}',
        'entire-ins_bad' => 'not json',
    ]], 'a'),
    $entireInit,
    call('generate_push_command', $repo + ['directory' => '/tmp/clone', 'branch' => 'main']),
    call('initialize_variables', ['installationId' => 'ins_stale', 'privateKey' => base64_encode($appSeed), 'appId' => 'appwrite']),
    call('generate_push_command', $repo + ['directory' => '/tmp/clone', 'branch' => 'main']),
    call('initialize_variables', ['installationId' => 'ins_bad', 'privateKey' => base64_encode(sodium_crypto_sign_secretkey($appKeys)), 'appId' => 'appwrite']),
    call('generate_push_command', $repo + ['directory' => '/tmp/clone', 'branch' => 'main']),
    step('fixture.requests'),
]];
write("{$cases}/Entire.json", ['cases' => $list]);

/*
 * The check a provider with plain commit statuses reports (Adapter's
 * updateCheck()): the title as the description, queued as pending, and a
 * commit status's state for a queue or a cancellation.
 */
$checks = [
    step('vcs.new', ['adapter' => 'gitea'], 'a'),
    call('initialize_variables', ['installationId' => '', 'privateKey' => '', 'accessToken' => 'test-token-gitea']),
    call('set_endpoint', ['endpoint' => '${vcs}/~${ns}/gitea']),
    call('create_organization', ['orgName' => 'checks']),
    call('create_repository', ['owner' => 'checks', 'repositoryName' => 'site', 'private' => false]),
    call('create_file', ['owner' => 'checks', 'repositoryName' => 'site', 'filepath' => 'index.html', 'content' => '<h1>Hi</h1>']),
    call('get_latest_commit', ['owner' => 'checks', 'repositoryName' => 'site', 'branch' => 'main'], 'c'),
];
foreach ([['queued', 'Site', 'Queued'], ['in_progress', 'Site', 'Building'], ['cancelled', 'Function', 'Cancelled'], ['success', 'Docs', 'Built']] as [$state, $name, $title]) {
    $checks[] = call('update_check', ['owner' => 'checks', 'repositoryName' => 'site', 'SHA' => ref('c', '/commitHash'), 'state' => $state, 'name' => $name, 'title' => $title, 'summary' => 'dropped', 'detailsUrl' => 'https://appwrite.test/console']);
}
$checks[] = call('get_commit_statuses', ['owner' => 'checks', 'repositoryName' => 'site', 'commitHash' => ref('c', '/commitHash')]);
$checks[] = call('supports_repository_clone_url');
$checks[] = call('generate_push_command', ['owner' => 'checks', 'repositoryName' => 'site', 'directory' => '/tmp/clone', 'branch' => 'main']);
$checks[] = step('fixture.requests');
write("{$cases}/Checks.json", ['cases' => [['name' => 'Gitea: a check reported as a commit status', 'steps' => $checks]]]);

/*
 * AdapterTest: every adapter verifies provider certificates unless told to
 * accept self-signed ones, and the setting reaches the calls: a provider on
 * the fixture server's self-signed HTTPS port fails by default and answers
 * once self-signed certificates are accepted.
 */
$list = [];
foreach (['bitbucket', 'entire', 'forgejo', 'gitea', 'github', 'gitlab', 'gogs', 'origin'] as $adapter) {
    $list[] = ['name' => "testVerifiesCertificatesByDefault {$adapter}", 'steps' => [
        step('vcs.new', ['adapter' => $adapter, 'cache' => 'none'], 'a'),
        call('is_self_signed'),
    ]];
    $list[] = ['name' => "testAcceptsSelfSignedCertificatesWhenOptedIn {$adapter}", 'steps' => [
        step('vcs.new', ['adapter' => $adapter, 'cache' => 'none'], 'a'),
        call('set_self_signed', ['selfSigned' => true]),
        call('is_self_signed'),
        call('set_self_signed', ['selfSigned' => false]),
        call('is_self_signed'),
    ]];
}
$tlsRoutes = [['method' => 'GET', 'path' => '/api/v1/repos/acme/site', 'body' => ['id' => 7, 'name' => 'site', 'full_name' => 'acme/site', 'private' => false]]];
$list[] = ['name' => 'a provider on a self-signed certificate', 'steps' => [
    step('fixture.script', ['routes' => $tlsRoutes]),
    step('vcs.new', ['adapter' => 'gitea', 'cache' => 'none'], 'a'),
    call('initialize_variables', ['installationId' => '', 'privateKey' => '', 'accessToken' => 'test-token-gitea']),
    call('set_endpoint', ['endpoint' => '${vcs-tls}/~${ns}/gitea']),
    call('get_repository', ['owner' => 'acme', 'repositoryName' => 'site']),
    call('set_self_signed', ['selfSigned' => true]),
    call('get_repository', ['owner' => 'acme', 'repositoryName' => 'site']),
    call('set_self_signed', ['selfSigned' => false]),
    call('get_repository', ['owner' => 'acme', 'repositoryName' => 'site']),
    step('fixture.requests'),
]];
write("{$cases}/AdapterTest.json", ['source' => 'packages/vcs/tests/AdapterTest.php', 'cases' => $list]);

/*
 * AdapterCallTest: a provider answering 500 (an outage) hands its answer
 * back without writing the request's parameters (a webhook secret) to any
 * output.
 */
$outage = [['method' => 'POST', 'path' => '/api/v1/repos/owner/repository/hooks', 'status' => 500, 'headers' => ['Content-Type' => 'application/json'], 'body' => ['message' => 'Internal Server Error']]];
write("{$cases}/AdapterCallTest.json", ['source' => 'packages/vcs/tests/AdapterCallTest.php', 'cases' => [
    ['name' => 'testServerErrorDoesNotWriteRequestParamsToOutput', 'steps' => [
        step('fixture.script', ['routes' => $outage]),
        step('vcs.new', ['adapter' => 'gitea', 'cache' => 'none'], 'a'),
        call('initialize_variables', ['installationId' => '', 'privateKey' => '', 'accessToken' => 'test-token-gitea']),
        call('set_endpoint', ['endpoint' => '${vcs}/~${ns}/gitea']),
        call('create_webhook', ['owner' => 'owner', 'repositoryName' => 'repository', 'url' => 'https://example.com/hook', 'secret' => 'webhook-secret']),
        step('fixture.requests'),
    ]],
]]);
