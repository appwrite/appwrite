#!/usr/bin/env bash
# Prove every ERROR class fails the CI scan on a known-bad snippet (with its
# own rule id) and that the house patterns stay clean. Scratch files live
# under src/Appwrite/Platform/Modules/Databases only for this process (the
# narrowest rule path include) and are removed on exit.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

SEMGREP="${SEMGREP:-semgrep}"
SCRATCH="$ROOT/src/Appwrite/Platform/Modules/Databases/_semgrep_prove"
mkdir -p "$SCRATCH"
trap 'rm -rf "$SCRATCH"' EXIT

fail() {
    echo "prove: $*" >&2
    exit 1
}

scan_error() {
    "$SEMGREP" scan \
        --config .semgrep \
        --metrics=off \
        --quiet \
        --error \
        --severity ERROR \
        --exclude .semgrep \
        "$@"
}

# expect_error <rule-id> <name>: stdin is the PHP snippet.
expect_error() {
    local id="$1" file="$SCRATCH/$2.php" out
    cat >"$file"
    if out="$(scan_error --json "$file")"; then
        fail "expected ERROR $id on $2"
    fi
    grep -q "php\.appwrite\.$id\"" <<<"$out" || fail "expected $id among findings on $2"
    echo "ok: $id fails on $2"
}

# expect_clean <name>: stdin is the PHP snippet.
expect_clean() {
    local file="$SCRATCH/$1.php"
    cat >"$file"
    scan_error "$file" >/dev/null || fail "expected no ERROR findings on $1"
    echo "ok: $1 is clean"
}

expect_error skip-ungated-load bad-skip <<'PHP'
<?php
$transaction = $authorization->skip(fn () => $dbForProject->getDocument('transactions', $transactionId));
PHP

expect_error account-token-secret-scope-gate bad-token <<'PHP'
<?php
class Create
{
    public function action(Document $user, Response $response, ?Key $apiKey): void
    {
        $response->dynamic($token, Response::MODEL_TOKEN);
    }
}
PHP

expect_error related-permissions-helper bad-related <<'PHP'
<?php
class Update extends Action
{
    public function action(Document $relatedDocument): void
    {
        $relatedDocument->setAttribute('$permissions', $permissions);
    }
}
PHP

expect_error route-without-scope bad-route-scope <<'PHP'
<?php
class Create extends Action
{
    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/things')
            ->groups(['api', 'things'])
            ->callback($this->action(...));
    }
}
PHP

expect_error route-without-api-group bad-route-group <<'PHP'
<?php
class Delete extends Action
{
    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/things/:thingId')
            ->groups(['things'])
            ->label('scope', 'things.write')
            ->callback($this->action(...));
    }
}
PHP

expect_error guest-url-without-publicurl bad-guest-url <<'PHP'
<?php
class Get extends Action
{
    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/avatars/preview')
            ->groups(['api', 'avatars'])
            ->label('scope', 'avatars.read')
            ->param('previewUrl', '', new URL(), 'Page to render.')
            ->callback($this->action(...));
    }
}
PHP

expect_error redirect-param-without-validator bad-redirect <<'PHP'
<?php
class Create extends Action
{
    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/things/redirect')
            ->groups(['api'])
            ->label('scope', 'sessions.write')
            ->param('success', '', new URL(), 'Success URL.')
            ->callback($this->action(...));
    }
}
PHP

expect_error unbounded-map-to-outbound bad-map <<'PHP'
<?php
class Get extends Action
{
    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/avatars/fetch')
            ->groups(['api'])
            ->label('scope', 'public')
            ->param('headers', [], new Assoc(), 'Headers to forward.')
            ->callback($this->action(...));
    }
}
PHP

expect_error header-blocklist-filter bad-blocklist <<'PHP'
<?php
class Get extends Action
{
    private const BLOCKED_HEADERS = ['authorization', 'cookie'];
}
PHP

expect_error client-ip-header bad-ip <<'PHP'
<?php
$ip = $request->getHeader('cf-connecting-ip', '');
PHP

expect_error default-secret-placeholder bad-placeholder <<'PHP'
<?php
$key = System::getEnv('_APP_OPENSSL_KEY_V1', 'changeme');
PHP

expect_error unsafe-dynamic-code bad-unserialize <<'PHP'
<?php
$state = \unserialize($request->getCookie('state', ''));
PHP

expect_error shell-exec-in-handler bad-shell <<'PHP'
<?php
\exec('unzip ' . $path);
PHP

expect_error tls-verification-disabled bad-tls <<'PHP'
<?php
\curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
PHP

expect_error superglobal-in-handler bad-superglobal <<'PHP'
<?php
$id = $_GET['id'] ?? '';
PHP

expect_error global-authorization-disable bad-auth-disable <<'PHP'
<?php
$authorization->disable();
$documents = $dbForProject->find('files');
PHP

expect_error show-sensitive-outside-payload bad-sensitive <<'PHP'
<?php
$response->json($response->showSensitive(fn () => $response->output($token, Response::MODEL_TOKEN)));
PHP

expect_error insecure-random bad-random <<'PHP'
<?php
$code = \mt_rand(100000, 999999);
PHP

expect_error xml-external-entities bad-xml <<'PHP'
<?php
$xml = \simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOENT);
PHP

expect_error raw-sql-interpolation bad-sql <<'PHP'
<?php
$pdo->query("SELECT * FROM {$table} WHERE id = '{$id}'");
PHP

expect_error insecure-cookie-flags bad-cookie <<'PHP'
<?php
$response->addCookie('a_session', $secret, $expire, '/', $domain, true, false, null);
PHP

expect_error debug-output-in-handler bad-debug <<'PHP'
<?php
\var_dump($session);
PHP

expect_error request-path-to-filesystem bad-path <<'PHP'
<?php
class Get extends Action
{
    public function action(string $name, Response $response): void
    {
        $response->send(\file_get_contents(APP_STORAGE_CACHE . '/' . $name));
    }
}
PHP

expect_clean ok-house-patterns <<'PHP'
<?php
class Create extends Action
{
    private const ALLOWED_HEADERS = ['accept'];

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/things')
            ->groups(['api', 'things'])
            ->label('scope', 'documents.write')
            ->label('abuse-limit', 60)
            ->param('url', '', new PublicURL(), 'Public page.')
            ->param('success', '', fn ($redirectValidator) => $redirectValidator, 'Redirect.', true, ['redirectValidator'])
            ->param('headers', [], new Assoc(), 'Filtered against ALLOWED_HEADERS.', true)
            ->callback($this->action(...));
    }

    public function action(string $fileId, Request $request, Response $response): void
    {
        $transaction = ($isAPIKey || $isPrivilegedUser)
            ? $authorization->skip(fn () => $dbForProject->getDocument('transactions', $transactionId))
            : $dbForProject->getDocument('transactions', $transactionId);
        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        $ip = $request->getIP();
        $secret = \bin2hex(\random_bytes(32));
        $state = \unserialize($blob, ['allowed_classes' => false]);
        $statement = $pdo->prepare('SELECT * FROM users WHERE email = :email');
        $contents = \file_get_contents(APP_STORAGE_CACHE . '/' . \basename($fileId));
        $response->addCookie('a_session', $secret, $expire, '/', $domain, true, true, null);
        $queueForEvents->setPayload($response->showSensitive(fn () => $response->output($token, Response::MODEL_TOKEN)), sensitive: ['secret']);
        $dsn = $databases[\array_rand($databases)];
    }
}
PHP
