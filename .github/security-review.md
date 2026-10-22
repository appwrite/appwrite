Appwrite is an open-source backend platform: PHP 8.3 on Swoole with the Utopia framework, serving auth, databases, storage, functions, sites, messaging, and realtime APIs. One instance serves many projects, each with its own users, API keys, and data, so the defects that matter most let a user, API key, or project act beyond what it was granted:

- Authorization: missing or wrong scope, role, or permission checks; document, row, and file permissions bypassed; `Authorization::skip()` around reads or writes driven by request input; console routes reachable with project credentials.
- Project isolation: queries, caches, files, queues, or realtime channels not keyed by project; identifiers taken from the request instead of the authenticated context.
- Identity: sessions, JWTs, API keys, OAuth2, MFA, password recovery, magic URLs, and OTP; token entropy, expiry, revocation, and constant-time comparison.
- Outbound requests: user-supplied URLs, hosts, or DNS names reaching HTTP clients, webhooks, migrations, VCS, messaging providers, or avatars without excluding internal networks.
- Injection: SQL, NoSQL, shell, template, header, path traversal, and deserialization of untrusted data, including query parsing and GraphQL.
- Isolation of user code: function and site runtimes and builds reaching platform secrets, other projects, or the host.
- Abuse: rate limits and resource limits that a client can skip or race.
- Secrets: credentials, keys, or personal data written to logs, errors, responses, or events.
