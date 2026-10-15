const fs = require('fs');
const nodePath = require('path');
const baseline = require('../../.semgrep/baseline.js');

const marker = '<!-- semgrep-rules-comment -->';
// GitHub issue comments cap at 65536. Leave headroom for the footer and marker.
const COMMENT_LIMIT = 60000;
const SNIPPET_LIMIT = 160;
const footer = '_Posted by `Checks / Rules`. Re-runs update this comment in place. Rule details and baseline: `.semgrep/README.md`._';

module.exports = async ({ github, context, core }) => {
    const findings = readFindings('semgrep.json', core);
    // GITHUB_SHA is the commit actions/checkout scanned, so line anchors match the findings.
    const serverUrl = context.serverUrl || 'https://github.com';
    const blobBase = `${serverUrl}/${context.repo.owner}/${context.repo.repo}/blob/${context.sha}`;
    const runUrl = context.runId ? `${serverUrl}/${context.repo.owner}/${context.repo.repo}/actions/runs/${context.runId}` : null;
    // The step runs under always(), so a failed install, fixture, proof, or scan step lands here
    // without JSON. Replace the sticky comment so an earlier all-clear does not stand for this commit.
    const body = findings === null ? buildIncompleteComment({ runUrl }) : buildComment(findings, { blobBase });

    const pullRequest = context.payload.pull_request;
    if (!pullRequest || pullRequest.head.repo.full_name !== `${context.repo.owner}/${context.repo.repo}`) {
        return;
    }

    try {
        await upsertComment(github, context, pullRequest.number, body);
    } catch (error) {
        core.warning(`Could not post security rules comment: ${error.message}`);
    }
};

function readFindings(path, core, root = process.cwd(), entries = baseline.load()) {
    if (!fs.existsSync(path)) {
        core?.warning(`Semgrep JSON not found at ${path}`);
        return null;
    }
    let data;
    try {
        data = JSON.parse(fs.readFileSync(path, 'utf8'));
    } catch (error) {
        core?.warning(`Semgrep JSON at ${path} is unreadable: ${error.message}`);
        return null;
    }

    const sources = new Map();
    const source = (file) => {
        if (!sources.has(file)) {
            try {
                sources.set(file, fs.readFileSync(nodePath.resolve(root, file), 'utf8').split('\n'));
            } catch {
                sources.set(file, null);
            }
        }
        return sources.get(file);
    };

    const results = data.results || [];
    const known = new Set(baseline.partition(results, entries, root).known);
    return results.map((result) => {
        const id = String(result.check_id || '').replace(/^semgrep\./, '');
        const finding = {
            severity: String(result.extra?.severity || '').toUpperCase(),
            id,
            rule: id.split('.').pop(),
            path: result.path,
            line: result.start?.line,
            endLine: result.end?.line,
            message: String(result.extra?.message || '').replace(/\s+/g, ' ').trim(),
            baselined: known.has(result),
        };
        const lines = source(result.path);
        const found = lines ? inspect(lines, result) : {};
        return { ...finding, ...found, ...explain(finding, found) };
    }).sort((a, b) => {
        if (a.severity !== b.severity) {
            return a.severity === 'ERROR' ? -1 : 1;
        }
        if (a.rule !== b.rule) {
            return a.rule.localeCompare(b.rule);
        }
        return `${a.path}:${String(a.line).padStart(6, '0')}`.localeCompare(`${b.path}:${String(b.line).padStart(6, '0')}`);
    });
}

function inspect(lines, result) {
    const start = result.start?.line || 1;
    const end = result.end?.line || start;
    const range = lines.slice(start - 1, end).join('\n');
    return {
        scope: scopeOf(range),
        snippet: slice(lines, result.start, result.end),
        lastLine: (lines[end - 1] || '').trim(),
        nearby: lines.slice(Math.max(0, start - 15), end + 15).join('\n'),
        route: findRoute(lines, start, end),
        symbol: findSymbol(lines, start) || findResource(lines, start),
    };
}

function slice(lines, start, end) {
    if (!start || !end) {
        return '';
    }
    if (start.line === end.line) {
        return (lines[start.line - 1] || '').slice(start.col - 1, end.col - 1).trim();
    }
    const body = lines.slice(start.line - 1, end.line);
    body[0] = body[0].slice(start.col - 1);
    body[body.length - 1] = body[body.length - 1].slice(0, end.col - 1);
    return body.join('\n').trim();
}

function findRoute(lines, start, end) {
    const range = lines.slice(start - 1, end).join('\n');
    if (/->setHttpPath\(|^\s*\\?(?:Utopia\\Http\\)?Http::\w+\(/m.test(range)) {
        return parseRoute(range);
    }

    const file = lines.join('\n');
    if ((file.match(/->setHttpPath\(/g) || []).length === 1) {
        return parseRoute(file);
    }

    // Controllers: routes start with `Http::verb(` at column 0 and their chain and
    // closure are indented, so any other column-0 line means we left the route.
    for (let index = start - 1; index >= 0; index--) {
        const line = lines[index];
        if (/^Http::\w+\(/.test(line)) {
            return parseRoute(lines.slice(index, end).join('\n'));
        }
        if (line !== '' && /^\S/.test(line) && !line.startsWith('//') && !line.startsWith('->')) {
            return null;
        }
    }
    return null;
}

function parseRoute(chain) {
    const hook = chain.match(/Http::(init|shutdown|error|options|wildcard)\(\)/);
    const verb = chain.match(/HTTP_REQUEST_METHOD_([A-Z]+)/) || chain.match(/Http::(get|post|put|patch|delete)\(/);
    const path = chain.match(/->setHttpPath\(\s*['"]([^'"]+)['"]/) || chain.match(/Http::(?:get|post|put|patch|delete)\(\s*['"]([^'"]+)['"]/);
    const groups = chain.match(/->groups\(\s*\[([^\]]*)\]/);

    if (!path && !hook) {
        return null;
    }
    return {
        hook: hook && !path ? `Http::${hook[1]}()` : null,
        method: verb ? verb[1].toUpperCase() : null,
        path: path ? path[1] : null,
        scope: scopeOf(chain),
        groups: listOf(groups),
        abuseLimit: /->label\(\s*['"]abuse-limit['"]/.test(chain),
    };
}

function listOf(match) {
    return match ? match[1].split(',').map((item) => item.replace(/['"\[\]\s]/g, '')).filter(Boolean) : [];
}

function scopeOf(text) {
    return listOf(text.match(/->label\(\s*['"]scope['"]\s*,\s*(\[[^\]]*\]|['"][^'"]*['"])/));
}

function guestScope(found) {
    const scope = found.route?.scope.length ? found.route.scope : found.scope || [];
    return code(scope.join(', ') || 'unknown');
}

function findSymbol(lines, start) {
    let method = null;
    let className = null;
    for (let index = start - 1; index >= 0; index--) {
        const line = lines[index];
        if (method === null) {
            const fn = line.match(/function\s+(\w+)\s*\(/);
            if (fn) {
                method = fn[1];
            } else if (/^\s{0,4}\}/.test(line) && index !== start - 1) {
                method = '';
            }
        }
        const cls = line.match(/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|trait|enum)\s+(\w+)/);
        if (cls) {
            className = cls[1];
            break;
        }
    }
    if (className && method) {
        return `${className}::${method}()`;
    }
    if (className) {
        return className;
    }
    return method ? `${method}()` : null;
}

// app/init registers resources as `$container->set('name', ...)` at column 0.
function findResource(lines, start) {
    for (let index = start - 1; index >= 0; index--) {
        const line = lines[index];
        const resource = line.match(/^\$\w+->set\(\s*['"]([^'"]+)['"]/);
        if (resource) {
            return `resource ${resource[1]}`;
        }
        if (index !== start - 1 && /^\S/.test(line) && !line.startsWith('//')) {
            return null;
        }
    }
    return null;
}

function code(text) {
    let value = String(text).replace(/\s+/g, ' ').trim();
    if (value.length > SNIPPET_LIMIT) {
        value = `${value.slice(0, SNIPPET_LIMIT - 1)}…`;
    }
    const fence = value.includes('`') ? '``' : '`';
    const pad = fence === '``' ? ' ' : '';
    return `${fence}${pad}${value}${pad}${fence}`;
}

function routeName(route) {
    if (!route) {
        return null;
    }
    if (route.hook) {
        return route.hook;
    }
    return route.method ? `${route.method} ${route.path}` : route.path;
}

function where(found) {
    const parts = [];
    const name = routeName(found.route);
    if (name) {
        parts.push(found.route.hook ? `${code(name)} hook` : code(name));
        if (found.route.hook && found.route.groups.length) {
            parts.push(`groups ${found.route.groups.map(code).join(', ')}`);
        } else if (!found.route.hook && found.route.scope.length) {
            parts.push(`scope ${found.route.scope.map(code).join(', ')}`);
        }
    }
    if (found.symbol && !(name && found.symbol.endsWith('::__construct()'))) {
        parts.push(code(found.symbol));
    }
    return parts.join(' · ');
}

// The bullet line already names the route or symbol; issue text refers back to it.
function routeOrPlace(found) {
    if (found.route?.hook) {
        return 'this hook';
    }
    if (found.route) {
        return 'this route';
    }
    if (found.symbol?.startsWith('resource ')) {
        return 'this resource';
    }
    return found.symbol ? code(found.symbol) : 'this file';
}

function capital(text) {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function paramOf(found) {
    const match = (found.lastLine || '').match(/->param\(\s*['"]([^'"]+)['"]\s*,[^,]*,\s*(new\s+[\\\w]+)/);
    return match ? { name: match[1], validator: match[2].replace(/^new\s+/, '').split('\\').pop() } : null;
}

function first(text, pattern) {
    const match = String(text || '').match(pattern);
    return match ? match[1] : null;
}

function hashEqualsFor(snippet) {
    const value = snippet.replace(/\s+/g, ' ').trim();
    const strcmp = value.match(/^\\?strcmp\((.+)\)$/);
    if (strcmp) {
        return `\\hash_equals(${strcmp[1]})`;
    }
    for (const operator of [' !== ', ' === ', ' != ', ' == ']) {
        const at = value.indexOf(operator);
        if (at > 0) {
            const left = value.slice(0, at);
            const right = value.slice(at + operator.length);
            const negate = operator.includes('!') ? '!' : '';
            return `${negate}\\hash_equals((string) ${left}, (string) ${right})`;
        }
    }
    return null;
}

const explainers = {
    'skip-ungated-load': (f) => {
        const collection = first(f.snippet, /(?:getDocument|find|findOne)\(\s*['"]([^'"]+)['"]/) || 'this collection';
        return {
            issue: `${capital(routeOrPlace(f))} loads ${code(collection)} inside ${code('skip()')} for every caller, so document permissions on ${code(collection)} are not applied.`,
            fix: `Gate the skip: ${code(`($isAPIKey || $isPrivilegedUser) ? $authorization->skip(fn () => …) : $dbForProject->getDocument('${collection}', …)`)}, or load without skip so document ACL applies. If ${code(collection)} is schema / platform metadata, add it to the allowlist in ${code('.semgrep/skip-ungated-load.yml')}.`,
        };
    },
    'global-authorization-disable': (f) => ({
        issue: `${code(f.snippet)} in ${routeOrPlace(f)} turns document permissions off for the rest of the request, not just one read.`,
        fix: `Remove it and wrap only the read that needs it in ${code('$authorization->skip(fn () => …)')} behind the API-key / privileged gate.`,
    }),
    'route-without-scope': (f) => ({
        issue: `${capital(routeOrPlace(f))} declares no ${code("->label('scope', …)")}, so the api hook enforces no scope for it.`,
        fix: `Add ${code("->label('scope', '<resource>.read')")} or ${code("'<resource>.write'")} matching the module, or ${code("'public'")} if the route is meant to be open.`,
    }),
    'route-without-api-group': (f) => ({
        issue: `${capital(routeOrPlace(f))} has scope ${code(f.route?.scope.join(', ') || '?')} but groups ${code(`[${f.route?.groups.join(', ') || ''}]`)} without ${code('api')}, so the scope label is never checked.`,
        fix: `Add ${code("'api'")} to ${code('->groups([...])')} (house order: ${code("['api', '<service>']")}).`,
    }),
    'account-token-secret-scope-gate': (f) => {
        const model = first(f.snippet, /(MODEL_\w+)/) || 'the credential model';
        return {
            issue: `${capital(routeOrPlace(f))} returns ${code(model)} to callers with an API key and keeps ${code('secret')} even when the key lacks ${code('users.write')}.`,
            fix: `Before the response add ${code("if ($apiKey !== null && !\\in_array('users.write', $apiKey->getScopes())) { $token->setAttribute('secret', ''); }")}.`,
        };
    },
    'show-sensitive-outside-payload': (f) => ({
        issue: `${code('showSensitive()')} is used outside an event payload in ${routeOrPlace(f)}, so the response includes fields models hide from non-privileged callers.`,
        fix: `Return ${code('$response->dynamic($document, Response::MODEL_…)')} without it. Use ${code('showSensitive()')} only inside ${code("$queueForEvents->setPayload(…, sensitive: ['secret'])")}.`,
    }),
    'related-permissions-helper': (f) => ({
        issue: `${code(f.snippet)} reads or writes a related document's ${code('$permissions')} directly in ${routeOrPlace(f)}.`,
        fix: `Route the check through ${code('$this->validateRelatedPermissions(...)')} so nested writes get the same permission rules as top-level ones.`,
    }),
    'guest-url-without-publicurl': (f) => {
        const param = paramOf(f);
        return {
            issue: `${capital(routeOrPlace(f))} is reachable with guest scope ${guestScope(f)} and accepts ${code(param?.name || 'a URL param')} validated by bare ${code(`${param?.validator || 'URL'}()`)}, which allows internal and non-public targets.`,
            fix: `Validate ${code(param?.name || 'it')} with ${code('new PublicURL()')} (or ${code('PublicDomain')}; for redirects inject ${code('redirectValidator')}).`,
        };
    },
    'redirect-param-without-validator': (f) => {
        const param = paramOf(f);
        return {
            issue: `${capital(routeOrPlace(f))} accepts redirect target ${code(param?.name || 'param')} validated by bare ${code(`${param?.validator || 'URL'}()`)}, so redirects are not limited to the project's registered platforms.`,
            fix: `Use ${code(`->param('${param?.name || 'success'}', '', fn ($redirectValidator) => $redirectValidator, '…', true, ['redirectValidator'])`)}.`,
        };
    },
    'unbounded-map-to-outbound': (f) => {
        const param = paramOf(f);
        const name = param?.name || 'map';
        return {
            issue: `${capital(routeOrPlace(f))} is reachable with guest scope ${guestScope(f)} and accepts ${code(name)} as an unbounded ${code('Assoc')} map with no ${code('ALLOWED_*')} list in the class.`,
            fix: `Declare ${code(`private const ALLOWED_${name.replace(/[A-Z]/g, (c) => `_${c}`).toUpperCase()} = [...]`)} and drop keys that are not in it (${code('\\in_array(\\strtolower($key), self::ALLOWED_HEADERS, true)')}), as in ${code('Avatars/Http/Screenshots/Get.php')}.`,
        };
    },
    'header-blocklist-filter': (f) => ({
        issue: `${code(first(f.snippet, /(?:const\s+)?\$?(\w+)\s*=/) || f.snippet)} in ${routeOrPlace(f)} filters headers / hosts by known-bad names; any new name passes.`,
        fix: `Replace it with an allowlist (${code('ALLOWED_HEADERS')} or ${code('FUNCTION_ALLOWLIST_HEADERS_*')}) and keep only listed names.`,
    }),
    'client-ip-header': (f) => {
        const header = first(f.snippet, /['"]([^'"]+)['"]/) || 'a forwarded header';
        const hostLike = /host|proto|port/i.test(header);
        return {
            issue: `${capital(routeOrPlace(f))} reads ${code(header)} directly; any client can set it.`,
            fix: hostLike
                ? `Use ${code('$request->getHostname()')} / ${code('$request->getProtocol()')}, which honour ${code('_APP_TRUSTED_HEADERS')}.`
                : `Use ${code('$request->getIP()')}, which honours ${code('_APP_TRUSTED_HEADERS')}.`,
        };
    },
    'default-secret-placeholder': (f) => ({
        issue: `Placeholder secret ${code(f.snippet)} in ${routeOrPlace(f)}.`,
        fix: `Remove the literal; read the secret with ${code("System::getEnv('_APP_…', '')")} and fail or disable the feature when it is empty. Keep examples in ${code('app/config/variables.php')}.`,
    }),
    'insecure-random': (f) => ({
        issue: `${code(f.snippet)} in ${routeOrPlace(f)} is predictable.`,
        fix: `Use ${code('\\random_int($min, $max)')}, ${code('\\bin2hex(\\random_bytes(32))')}, or ${code('ID::unique()')}.`,
    }),
    'insecure-cookie-flags': (f) => ({
        issue: `${code(f.snippet)} in ${routeOrPlace(f)} sets a cookie scripts can read, or skips the Response.`,
        fix: `Use ${code('$response->addCookie(...)')} with httponly ${code('true')} (7th argument), as the session routes do.`,
    }),
    'tls-verification-disabled': (f) => ({
        issue: `${code(f.snippet)} in ${routeOrPlace(f)} turns off TLS certificate checks.`,
        fix: `Remove it (defaults verify). For a private CA set ${code('CURLOPT_CAINFO')} / ${code("'cafile'")} instead.`,
    }),
    'outbound-follow-redirects': (f) => ({
        issue: `${code(f.snippet)} in ${routeOrPlace(f)} lets the outbound request follow redirects to hosts that were never validated.`,
        fix: `Set ${code('CURLOPT_FOLLOWLOCATION')} to ${code('false')} when the endpoint is fixed, or cap redirects and re-validate each ${code('Location')} with ${code('PublicURL')}.`,
    }),
    'unsafe-dynamic-code': (f) => ({
        issue: `${code(f.snippet)} in ${routeOrPlace(f)} builds code, variables, or objects from data.`,
        fix: `Use ${code('json_decode()')} and map fields explicitly; ${code("unserialize($x, ['allowed_classes' => false])")} and ${code('parse_str($query, $out)')} if they are unavoidable.`,
    }),
    'shell-exec-in-handler': (f) => ({
        issue: `${code(f.snippet)} runs a local process from ${routeOrPlace(f)}.`,
        fix: 'Move the work to the executor or a worker; if it is operator-only, make it a CLI task.',
    }),
    'superglobal-in-handler': (f) => ({
        issue: `${code(f.snippet)} in ${routeOrPlace(f)} reads process-wide state under Swoole and skips route validators.`,
        fix: `Use the injected ${code('$request')} (${code('getParam')} / ${code('getHeader')} / ${code('getCookie')}) or ${code('System::getEnv()')}.`,
    }),
    'debug-output-in-handler': (f) => ({
        issue: `${code(f.snippet)} in ${routeOrPlace(f)} writes to worker stdout instead of the Response or logger.`,
        fix: `Remove it; use ${code('$response->addHeader()')} for headers and ${code('Span::add()')} / ${code('Console::log()')} with non-secret values for diagnostics.`,
    }),
    'raw-sql-interpolation': (f) => ({
        issue: `${code(f.snippet)} in ${routeOrPlace(f)} builds SQL by string concatenation or interpolation.`,
        fix: `Use utopia-php/database queries, or ${code('prepare()')} with placeholders and ${code('bindValue()')}.`,
    }),
    'xml-external-entities': (f) => ({
        issue: `${code(f.snippet)} in ${routeOrPlace(f)} lets libxml load entities or DTDs.`,
        fix: `Drop the flag and parse with default options (optionally ${code('LIBXML_NONET')}).`,
    }),
    'request-path-to-filesystem': (f) => ({
        issue: `Request-derived ${code(f.snippet)} reaches a filesystem / include call in ${routeOrPlace(f)}.`,
        fix: `Reduce it with ${code('\\basename()')} / a validated ID, or resolve with ${code('realpath()')} and require ${code('str_starts_with($real, $base)')}.`,
    }),
    'guest-write-without-abuse-limit': (f) => {
        const route = f.route;
        const inApi = route?.groups.includes('api');
        const scope = route?.scope.join(', ') || '?';
        if (route && !inApi) {
            return {
                issue: `Guests can call this ${route.method || ''} route (scope ${code(scope)}), and it sits outside the ${code('api')} group, so no abuse limit can apply.`,
                fix: `If it only forwards to a limited route, no change is needed beyond confirming that in review. Otherwise add it to ${code("->groups(['api', …])")} and set ${code("->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)")}.`,
            };
        }
        return {
            issue: `Guests can call this ${route?.method || ''} route (scope ${code(scope)}), and it has no ${code('abuse-limit')} label, so the shared api hook does not rate-limit it.`,
            fix: `Add ${code("->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)")} and ${code("->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')")} to this route (the house default), or narrow the scope.`,
        };
    },
    'weak-secret-env-default': (f) => {
        const name = first(f.snippet, /getenv\(\s*['"]([^'"]+)['"]/i) || 'the variable';
        const fallback = first(f.snippet, /getEnv\(\s*['"][^'"]+['"]\s*,\s*(['"][^'"]*['"])/) || first(f.snippet, /\?:\s*(['"][^'"]*['"])/) || 'a literal';
        return {
            issue: `${code(name)} falls back to ${code(fallback)} when unset, so every install that does not set it shares that value.`,
            fix: `Read ${code(`System::getEnv('${name}', '')`)} and fail or disable the dependent connection when it is empty; keep the example value in ${code('.env')} / ${code('app/config/variables.php')}.`,
        };
    },
    'secret-compare-timing': (f) => {
        const replacement = hashEqualsFor(f.snippet);
        return {
            issue: `${code(f.snippet)} in ${routeOrPlace(f)} compares a secret / code / signature with a plain operator.`,
            fix: replacement
                ? `Use ${code(replacement)}.`
                : `Use ${code('\\hash_equals($known, $provided)')}.`,
        };
    },
    'permissive-write-permission': (f) => {
        const action = first(f.snippet, /Permission::(\w+)\(/) || 'write';
        const role = first(f.snippet, /(Role::\w+\(\))/) || 'Role::any()';
        const collection = first(f.nearby, /(?:createDocument|updateDocument|upsertDocument)\(\s*['"]([^'"]+)['"]/);
        const target = collection ? `documents in ${code(collection)}` : 'these documents';
        return {
            issue: `${code(f.snippet)} in ${routeOrPlace(f)} lets anyone with ${code(role)}, including unauthenticated callers, ${action} ${target}.`,
            fix: `Grant ${code(action)} only to the owning role (${code('Role::user($userId)')}, ${code('Role::team($teamId)')}), or drop it and perform the change from a server route; keep ${code('Permission::read(Role::any())')} only if public reads are intended.`,
        };
    },
};

function explain(finding, found) {
    const explainer = explainers[finding.rule];
    if (!explainer) {
        return {};
    }
    try {
        return explainer({ ...finding, ...found });
    } catch {
        return {};
    }
}

function buildComment(findings, options = {}) {
    const known = findings.filter((item) => item.baselined);
    const current = findings.filter((item) => !item.baselined);
    const errors = current.filter((item) => item.severity === 'ERROR');
    const warnings = current.filter((item) => item.severity === 'WARNING');

    if (current.length === 0) {
        return [
            marker,
            '## Security rules',
            '',
            'No new WARNING or ERROR findings from security rules.',
            '',
            ...summarizeKnown(known),
            footer,
            '',
        ].join('\n');
    }

    const shownErrors = errors.slice();
    const shownWarnings = warnings.slice();
    let omitted = 0;

    const render = () => assembleComment(shownErrors, shownWarnings, errors.length, warnings.length, omitted, known, options);

    while (shownErrors.length + shownWarnings.length > 0 && render().length > COMMENT_LIMIT) {
        if (shownWarnings.length > 0) {
            shownWarnings.pop();
        } else {
            shownErrors.pop();
        }
        omitted = (errors.length - shownErrors.length) + (warnings.length - shownWarnings.length);
    }

    return render();
}

function buildIncompleteComment(options = {}) {
    const log = options.runUrl ? `[\`Checks / Rules\` log](${options.runUrl})` : '`Checks / Rules` log';
    return [
        marker,
        '## Security rules',
        '',
        `**Scan did not complete.** \`semgrep.json\` is missing or unreadable for this commit, so its findings are unknown. Check the ${log}: Semgrep install, rule fixtures, the ERROR proofs, or the scan itself failed.`,
        '',
        footer,
        '',
    ].join('\n');
}

function summarizeKnown(known) {
    if (known.length === 0) {
        return [];
    }
    const counts = new Map();
    for (const row of known) {
        counts.set(row.id, (counts.get(row.id) || 0) + 1);
    }
    return [
        '<details>',
        `<summary>${known.length} existing finding${known.length === 1 ? '' : 's'} tracked in <code>.semgrep/baseline.json</code></summary>`,
        '',
        ...[...counts].sort((a, b) => a[0].localeCompare(b[0])).map(([id, total]) => `- \`${id}\` (${total})`),
        '',
        '</details>',
        '',
    ];
}

function assembleComment(errors, warnings, errorTotal, warningTotal, omitted, known, options) {
    const lines = [
        marker,
        '## Security rules',
        '',
    ];

    if (errorTotal > 0) {
        lines.push(`**ERROR** (${errorTotal} new) — this check fails until these are resolved.`, '');
        lines.push(...listFindings(errors, options));
        lines.push('');
    }

    if (warningTotal > 0) {
        lines.push(`**WARNING** (${warningTotal} new) — review signal only; does not fail the job.`, '');
        lines.push(...listFindings(warnings, options));
        lines.push('');
    }

    if (omitted > 0) {
        lines.push(`_${omitted} more finding${omitted === 1 ? '' : 's'} omitted to stay under the GitHub comment size limit._`);
        lines.push('');
    }

    lines.push(...summarizeKnown(known));
    lines.push(footer);
    lines.push('');
    return lines.join('\n');
}

function location(row, options) {
    const label = row.line ? `${row.path}:${row.line}` : row.path;
    if (!options.blobBase || !row.path) {
        return `\`${label}\``;
    }
    let anchor = '';
    if (row.line) {
        anchor = row.endLine && row.endLine > row.line ? `#L${row.line}-L${row.endLine}` : `#L${row.line}`;
    }
    const path = row.path.split('/').map(encodeURIComponent).join('/');
    return `[\`${label}\`](${options.blobBase}/${path}${anchor})`;
}

function listFindings(rows, options) {
    const lines = [];
    const groups = new Map();
    for (const row of rows) {
        if (!groups.has(row.id)) {
            groups.set(row.id, []);
        }
        groups.get(row.id).push(row);
    }

    for (const [id, items] of groups) {
        lines.push(`#### \`${id}\` (${items.length})`, '');
        if (items[0].message) {
            lines.push(`> ${items[0].message}`, '');
        }
        for (const row of items) {
            const context = where(row);
            lines.push(`- ${location(row, options)}${context ? ` — ${context}` : ''}`);
            if (row.issue) {
                lines.push(`  - **What's wrong:** ${row.issue}`);
            }
            if (row.fix) {
                lines.push(`  - **How to fix:** ${row.fix}`);
            }
        }
        lines.push('');
    }

    if (lines[lines.length - 1] === '') {
        lines.pop();
    }
    return lines;
}

async function upsertComment(github, context, issueNumber, body) {
    const comments = await github.paginate(github.rest.issues.listComments, {
        owner: context.repo.owner,
        repo: context.repo.repo,
        issue_number: issueNumber,
        per_page: 100,
    });

    const existing = comments.find((comment) => comment.body?.includes(marker));

    if (existing) {
        await github.rest.issues.updateComment({
            owner: context.repo.owner,
            repo: context.repo.repo,
            comment_id: existing.id,
            body,
        });
        return;
    }

    await github.rest.issues.createComment({
        owner: context.repo.owner,
        repo: context.repo.repo,
        issue_number: issueNumber,
        body,
    });
}

module.exports.readFindings = readFindings;
module.exports.buildComment = buildComment;
module.exports.buildIncompleteComment = buildIncompleteComment;
