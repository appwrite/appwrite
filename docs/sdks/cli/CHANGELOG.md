# Change Log

## 28.4.0

* Added: `account` passkey commands `list-passkeys`, `create-passkey`, `get-passkey`, `update-passkey`, `delete-passkey` and `update-passkey-verification`
* Added: `account create-passkey-token` and `account update-passkey-token` for passkey sign-in
* Added: `users list-passkeys`, `get-passkey`, `update-passkey` and `delete-passkey` commands
* Added: `project update-passkey-policy` command
* Updated: built on Go SDK v7.10.0

## 28.3.0

* Added: `--interval` on `functions create` and `functions update` to run functions every N minutes
* Added: `init`, `pull` and `push` read and write a function's `interval` in `appwrite.config.json`
* Updated: `push` sends `interval` only when the function's config sets it
* Updated: commands call Go SDK v7.9.0

## 28.2.0

* Added: `waf` commands to list, get and delete rules, and create or update bypass, challenge, deny, rate limit and redirect rules
* Added: `advisor`, `analytics`, `mysql`, `postgresql` and `mongo` commands
* Added: `presences upsert`, `presences update` and `project update-o-auth-2-webflow` commands
* Added: `--state` on `account create-o-auth-2-token`, `--duration` on `account create-email-password-session` and `--current` on `account delete-sessions`
* Added: `--total` on the remaining list commands and `--channel-id` on `messaging create-push` and `update-push`
* Updated: `--resource` on the `oauth2` commands can be repeated to pass several resource URIs
* Updated: commands call Go SDK v7.8.0

## 28.1.0

* Added: `--config-file` flag and `APPWRITE_CONFIG_FILE` to choose the project config file
* Added: `oauth2 introspect` command
* Added: `--prompt` on the Auth0, Discord, GitHub, Kakao, Microsoft, Okta, Salesforce and Zoho OAuth2 commands
* Fixed: `types` reads tables and collections split out with `includes`
* Fixed: help no longer prints backticks around flag value names
* Updated: command examples are shell-safe and use realistic values
* Updated: commands call Go SDK v7.5.0

## 28.0.0

* Breaking: removed `account list-logs` and `users list-logs`
* Breaking: removed `organization create-key`, `get-key`, `list-keys`, `update-key` and `delete-key`
* Added: `documentsdb`, `vectorsdb` and `domains` commands
* Added: `organization` project key commands: `create-ephemeral-project-key`, `get-project-key`, `list-project-keys`, `update-project-key`, `delete-project-key`
* Added: `account create-id-token-session` and email verification and recovery OTP commands
* Added: `messaging create-appwrite-provider` and `messaging update-appwrite-provider`
* Added: `project update-o-auth-2-kakao`, `update-o-auth-2-tik-tok` and `update-password-pwned-policy`
* Added: `--reply-to-email` and `--reply-to-name` on email messages, `--qos` and `--expiry` on topics
* Added: `--native-client-ids` and `--native-enabled` on the Apple and Google OAuth2 providers
* Fixed: `tablesdb get-column` and `databases get-attribute` decode varchar, text, bigint and spatial columns
* Fixed: `run` no longer reloads when a file is saved unchanged, and still reloads while files keep changing
* Fixed: `push functions` gives self-hosted functions a default domain under the Functions domain, not Sites
* Fixed: `types` finds the TypeScript SDK in `devDependencies`
* Updated: console links use the new console routes
* Updated: `run` warnings call the key an ephemeral API key
* Updated: commands call Go SDK v7.4.0

## 27.3.0

* Added: `project update-o-auth-2-cloudflare` configures the Cloudflare OAuth2 provider
* Added: `project update-o-auth-2-resend` configures the Resend OAuth2 provider
* Fixed: `dev` no longer reloads on a touch or chmod, only when file contents change
* Fixed: `dev` now picks up files and directories created or deleted after it started
* Updated: `tablesdb cutover-migration` is now `tablesdb create-cutover`
* Updated: Commands now call Go SDK v7.2.0-rc.5
* Updated: Help text covers variable key rules, `users create-jwt --session-id`, and the OAuth2 provider list
* Updated: Command examples quote string placeholders, so values with spaces or shell metacharacters are safe to copy

## 27.2.1

* Fixed: `init function` failed on templates pinned to a wildcard version tag such as `0.3.*`; the newest matching tag is now resolved
* Fixed: template repository refs and paths are passed to Git literally instead of through a shell

## 27.2.0

* Added: `init function` accepts flags for non-interactive runs: `--name`, `--function-id`, `--runtime`, `--source`, `--env-file`, `--deploy`, and more
* Added: `init function` can connect a GitHub repository as the function source, existing or newly created
* Added: `init function` asks where the function runs and reserves its generated domain up front
* Added: `project update-o-auth-2-hugging-face` configures the Hugging Face OAuth2 provider
* Added: `pull functions` keeps preview domains, Git provider settings, and pending template coordinates in `appwrite.config.json`
* Added: Endpoint mismatch and missing `appwrite.config.json` failures print the exact recovery command
* Fixed: OAuth2 token endpoint errors are read from `error`/`error_description`, so device login polling and refresh failures report correctly
* Fixed: A rejected refresh token names the affected session and suggests `appwrite login`
* Updated: Commands now call Go SDK 7.2.0-rc.3

## 27.1.0

* Added: `list-projects` and `list-organizations` return the same results for OAuth2, cookie, and self-hosted logins
* Added: `list-projects` lists every accessible organization, and `--organization-id` narrows it to one
* Added: Both lists now carry names, member counts, organization, and region instead of bare IDs
* Added: Table view for both lists shows `projects (1 of 224)`, an empty state, and a next-page command
* Added: `appwrite update` prints the release notes URL for the installed version
* Fixed: `--version` now reports when a newer version is available
* Fixed: Enrichment requests no longer overwrite the rendered result of a list command
* Updated: `prefs` is hidden from the table view and kept in `--json` output

## 27.0.0

* Breaking: Removed `account create-jwt`, use `users create-jwt` instead
* Breaking: Removed `project create-key`, use `organization create-key` instead
* Added: `users get-mfa-challenge` retrieves a challenge code to deliver through your own channel
* Added: `tablesdb list-migrations`, `create-migration`, `get-migration`, and `delete-migration`
* Added: `--scopes` on `sites create` and `sites update` limits the auto-generated build key
* Added: `push functions` uploads in parallel when more than one is selected
* Added: `run function` streams `context.log()` and `context.error()` output live
* Updated: Commands now call Go SDK 7.1.0

## 26.1.0

* Added: `project update-mfa-factors-policy` controls which factors can complete an MFA challenge
* Added: `tablesdb cutover-migration` cuts a verified migration over to its dedicated compute
* Added: `--installation-scopes` on `project update-o-auth-2-server`
* Fixed: `--json` and `--raw` keep GraphQL responses whole instead of flattening the envelope away
* Fixed: `push` rejects variable keys the API will not accept before deleting the remote set
* Fixed: `run` reports a function ready without invoking it first
* Updated: `login` defaults to production Cloud rather than whichever endpoint is current
* Updated: `login` and `logout` name the account and endpoint they acted on
* Updated: Help text lists the accepted column types, relationship types, and delete constraints

## 26.0.0

The CLI is now a single Go binary. Day-to-day usage carries over, with no runtime to install and nothing downloaded on first run.

* Breaking: Rewrote the CLI in Go, shipping one binary per platform
* Breaking: Removed the commands the Go SDK has no counterpart for, listed in full under `26.0.0-rc.1`
* Added: Homebrew, scoop, and `curl | bash` installs, alongside an npm package that carries the binary
* Added: A WebAssembly build, so the same CLI runs in a browser
* Added: `login --switch` picks a stored account by `--endpoint` and `--email`
* Fixed: Query flags such as `--filter`, `--sort-asc`, and `--limit` take effect without `--queries`
* Fixed: Large uploads complete instead of failing at a ten second deadline
* Updated: Startup is around 9.5ms, down from around 173ms

## 26.0.0-rc.3

* Added: `appwrite-cli-wasm` package runs the CLI in a browser through WebAssembly
* Added: `login --switch` takes `--endpoint` and `--email` to pick a stored account
* Added: Optional boolean flags accept `--flag value` alongside `--flag=value`
* Fixed: Commands reject stray positional arguments instead of ignoring them
* Fixed: `update` no longer prints an update notice before it updates
* Updated: Endpoint mismatch errors name the endpoint to pass to `login --switch`

## 26.0.0-rc.2

* Added: `graphql query` and `graphql mutation` accept a raw GraphQL document at `--query`
* Added: `init skill` takes `--all`, `--skill`, `--agent`, and `--method` for headless installs
* Added: `--code` on `functions` and `sites create-deployment` accepts a source directory
* Fixed: Requests no longer fail at a ten second deadline, so large uploads complete
* Fixed: `--self-signed` reaches service commands instead of being dropped before the request
* Fixed: A timed out mutation reports an unknown outcome instead of an ordinary failure
* Fixed: `--documents`, `--rows`, `--columns`, `--attributes`, `--indexes`, and `--operations` parse each value as JSON
* Updated: `list-specifications` documents that `--type` defaults to `runtimes`

## 26.0.0-rc.1

* Breaking: Rewrote the CLI in Go, shipping a single binary with no runtime to install
* Breaking: Removed the `vcs`, `migrations`, `notifications`, and `affiliates` command groups
* Breaking: Removed `account` key, push target, OAuth2 session, and `delete` commands
* Breaking: Removed `functions` and `sites` template commands
* Breaking: Removed `tablesdb` migration commands and `presences upsert`, `update`
* Breaking: Removed usage and log commands on `presences`, `project`, `users`, and `teams`
* Added: Homebrew, scoop, and `curl | bash` installs alongside npm
* Added: A per-platform npm package, so installing no longer downloads a binary afterwards
* Added: `update` follows the prerelease channel, so a release candidate moves to the next candidate
* Added: `update` refuses to install an older version unless `--force` is passed
* Updated: Startup is around 9.5ms, down from around 173ms

## 25.1.0

* Added: `list-organizations` and `list-projects` run at the root, alongside `login`
* Added: Hint pointing at `/v1` when an endpoint misses the API path
* Fixed: `list-organizations`, `list-projects`, and `organization get` work on self-hosted installs
* Fixed: `login --endpoint` verifies the endpoint before prompting for credentials
* Fixed: Trailing slashes in an endpoint no longer produce double-slashed request paths
* Fixed: HTML and oversized error pages are summarized instead of printed verbatim
* Fixed: `--report` issue links stay within the length GitHub accepts

## 25.0.0

* Breaking: Removed the `organizations` command group covering billing, plans, invoices, and add-ons
* Added: `organization` commands accept `--organization-id` to target another organization
* Added: `project` commands accept `--project-id` to target another project
* Added: Grouped `--help` screen with follow-up command hints after each action
* Added: `push` and `pull` document `--all` and `--id` in their own help
* Added: `client` reports the configured `organizationId`
* Fixed: `login` warns and signs in to the base Cloud endpoint instead of failing on a regional one
* Fixed: `init` no longer stacks a region prefix onto an already regional endpoint
* Fixed: `notifications` and `oauth2 list-organizations`, `list-projects` call the console, not the linked project
* Fixed: Passwords are masked in full instead of revealing a tail
* Updated: Timestamps, durations, sizes, and large counts render in human-readable form
* Updated: Enabled and disabled lists collapse into wrapped groups instead of wide tables
* Updated: Condensed output notes how many fields `--raw` would add
* Updated: Prompts and errors listing several accounts print one account per line
* Updated: `teams` hints and `register` visibility point at `organization` and `login`
* Updated: Dropped the `deploy` placeholder command, which only printed a "use `push`" warning

## 24.1.0

* Added: `previousKey` on an attribute or column renames it in place on `push`
* Fixed: `client --endpoint` reuses or switches an existing session instead of minting an unauthenticated stub
* Fixed: `client --reset` asks for confirmation before signing out signed-in accounts
* Fixed: Commands that need a session now list signed-in accounts and suggest `login --switch`
* Fixed: Regional cloud endpoints match their canonical host when resolving a session
* Fixed: `push` updates attributes in place instead of recreating them where the API allows it
* Fixed: `push` resizes a `string` attribute in place instead of recreating it
* Fixed: `push` no longer recreates indexes whose attributes were renamed
* Fixed: `push` aborts on a failed attribute update instead of continuing past it
* Fixed: `push` waits for index deletion to finish before recreating indexes
* Fixed: `push` leaves `encrypt` untouched when the config omits it
* Fixed: `logout` signs out the selected account instead of the current session id
* Fixed: Table output wraps long reasons instead of truncating them mid-word
* Updated: Project policy limits for password history, session duration, session count, and users

## 24.0.0

* Added: `login` against Cloud now signs in through your browser
* Updated: `--email`, `--password`, `--mfa`, and `--code` now apply to self-hosted instances only
* Updated: Existing Cloud sessions keep working in read-only mode until you run `login` again

## 23.2.0

* Added: `apps` installation commands: `list-installations`, `get-installation`, `delete-installation`, `create-installation-token`
* Added: `vcs list-namespaces` command for listing provider namespaces of an installation
* Added: `--provider-namespace` option to `vcs create-repository`
* Added: `tablesdb list-operations` command for dedicated database lifecycle operations
* Added: Compact deployment summaries with human-readable sizes in deployment output
* Added: `--filter`, `--where`, `--sort-asc`, `--sort-desc`, `--limit`, `--offset`, `--cursor-after`, and `--cursor-before` options to `vcs list-repositories` and `activities list-events`
* Updated: Command groups now use compact service names: `tablesdb` and `oauth2`. `tables-db` still works as an alias
* Updated: `apps` command group now shows a service description in help output
* Updated: `storage list-files` documents `folder` as a filterable attribute
* Fixed: `push sites` and `pull sites` now carry `fallbackFile` and the VCS fields `installationId`, `providerRepositoryId`, `providerBranch`, `providerSilentMode`, and `providerRootDirectory`
* Fixed: Symlinks in function directories are resolved for `run` and `push`, confined to the project root
* Fixed: Token refresh now uses the logged-in session endpoint, and session credentials are no longer sent to a different endpoint
* Fixed: Site screenshot previews preserve the configured region

## 23.1.0

* Added: `apps` service commands for managing OAuth2 applications, keys, secrets, and installations
* Added: `organization` app installation commands: `list-installations`, `create-installation`, `get-installation`, `update-installation`, `delete-installation`
* Added: `teams` app installation commands: `list-installations`, `create-installation`, `get-installation`, `update-installation`, `delete-installation`
* Added: `--installation-access-token-duration` option to `project update-o-auth2-server`

## 23.0.0

* Breaking: removed `projects` service commands
* Breaking: removed `health` service commands
* Breaking: removed usage commands: `get-usage`, `list-usage`, `get-bucket-usage`, `get-collection-usage`, `get-table-usage`
* Updated: removed `--new-specification` option from `backups create-restoration`
* Added: `account` OAuth2 consent commands: `list-consents`, `get-consent`, `delete-consent` and consent token commands
* Added: `oauth2 authorize-post` and `oauth2 logout-post` commands
* Added: `project update-o-auth-2-appwrite` command
* Added: `--token` option to `functions get-deployment-download` and `sites get-deployment-download`

## 22.6.1

* Fixed: snake_case parameters now map to proper kebab-case flags: `oauth2` commands use `--client-id`, `--redirect-uri`, `--response-type`, `--request-uri`, `--code-challenge`, `--max-age` instead of the malformed `--client-_id` style names

## 22.6.0

* Added: `push functions` and `pull` now work with API key authentication, enabling CI usage without a console session
* Updated: Console-only steps during push, like default domain rules, are skipped with a warning when using an API key
* Updated: Clearer authentication errors that distinguish API key and console session requirements

## 22.5.0

* Updated: `tablesdb create` now takes `--specification` instead of `--dedicated-database-id`
* Updated: Removed `health get-queue-logs` command
* Added: `notifications` command group for console notifications
* Added: `organization get`, `update`, and `delete` commands
* Added: `organization list-memberships`, `create-membership`, `get-membership`, `update-membership`, and `delete-membership` commands
* Added: `tablesdb list-migrations`, `create-migration`, `get-migration`, and `delete-migration` commands for dedicated database migrations
* Added: `oauth2 create-par`, `list-organizations`, and `list-projects` commands
* Added: `--request-_uri` option on `oauth2 authorize`; client ID, redirect URI, and response type are now optional
* Added: `projects list-addons`, `get-addon`, `delete-addon`, `confirm-addon-payment`, and `get-addon-price` commands
* Added: `create-premium-geo-db-addon` command on `projects` and `organizations`
* Added: `account list-logs`, `teams list-logs`, and `users list-logs` commands
* Added: `health get-geo` and `get-geo-premium` commands
* Added: `--new-specification` option on `backups create-restoration`
* Added: `--prompt` and `--max-age` options on `project update-o-auth-2-oidc`
* Fixed: Project config validation now sends the `X-Appwrite-Project` header, fixing 401 errors for guest sessions
* Fixed: Update check now reads the latest version from the Homebrew tap instead of stale local metadata

## 22.4.0

* Updated: Removed pre-release activity log commands `list-logs`, `list-collection-logs`, `list-document-logs`, `list-table-logs`, `list-row-logs`, `list-message-logs`, `list-provider-logs`, `list-subscriber-logs`, and `list-topic-logs`
* Updated: Removed `oauth2 approve` command, meant for the console consent screen only
* Updated: `--scope` on `oauth2 authorize` is now optional and supports the `phone` scope
* Updated: CLI OAuth login now requests the `all` scope instead of `account.admin`
* Updated: Skills install now uses the `appwrite/skills` repository
* Updated: API key scope limits raised from 100 to 200 scopes
* Fixed: Empty error messages now fall back to the response body instead of a bare `Error:`
* Updated: Verbose error output is formatted with colored details and indented stack traces

## 22.3.0

* Added: Regional cloud endpoints are now derived from your configured endpoint in `init` and `push`
* Added: `push` creates a default function proxy rule when an existing function is missing one
* Added: `push` aborts and asks you to re-run when tablesDB deletions change the config
* Fixed: Unauthorized sessions now retry an OAuth token refresh before logging out
* Fixed: Auth security policies `limit`, `sessionsLimit`, and `passwordHistory` now accept null for unlimited
* Updated: `push` applies service, protocol, auth method, and policy updates concurrently
* Updated: Verbose error logs now include `code`, `type`, and `response` details

## 22.2.2

* Fixed: Release binaries now embed `@napi-rs/keyring` native bindings for all targets

## 22.2.1

* Fixed: Compiled binaries no longer crash from `@napi-rs/keyring` native bindings

## 22.2.0

* Added: OAuth refresh tokens now stored in the OS keychain via `@napi-rs/keyring`, falling back to config
* Added: `--type` option to `functions list-specifications` and `sites list-specifications`
* Updated: Bumped `@appwrite.io/console` dependency to `^15.1.1`
* Updated: Cleaner account selection prompt for `logout` with a `(current)` marker
* Fixed: OAuth login now clears the stale legacy session cookie
* Fixed: Browser launch on Windows now uses `rundll32` for OAuth flows

## 22.1.3

* Added: `--resource` option to `oauth2 authorize`, `create-device-authorization`, and `create-token` for RFC 8707 resource indicators
* Added: Press Enter during device login to open the verification URL in your browser
* Updated: Bumped `@appwrite.io/console` dependency to `^15.1.0`

## 22.1.2

* Added: OAuth2 login now requests the `account.admin` scope
* Fixed: `logout` now reports the underlying server error when a session can't be revoked
* Fixed: `logout` and `client --reset` no longer report success when the server session revocation fails

## 22.1.1

* Added: Support for logging into staging Cloud environments (`stage.cloud.appwrite.io`)
* Updated: Compiled binaries now built with `--minify` instead of inline sourcemaps

## 22.1.0

* Fixed: `organization get-invoice-download` and `get-invoice-view` now save the invoice to disk via `--destination`
* Fixed: File download commands now report a clear error when the download request fails
* Added: `oauth2` command group (`authorize`, `create-token`, `create-grant`, `get-grant`, `approve`, `reject`, `revoke`, `logout`, `create-device-authorization`)
* Added: `messaging create-ses-provider` and `update-ses-provider` commands for Amazon SES
* Added: `project update-o-auth-2-server` command to configure the OIDC provider
* Added: `project update-deny-corporate-email-policy` command
* Added: `project update-password-strength-policy` command
* Added: `--dedicated-database-id` option to `tables-db create`
* Updated: Bumped `@appwrite.io/console` dependency to `^15.0.0`

## 22.0.0

* Breaking: Renamed `project update-deny-canonical-email-policy` to `update-deny-aliased-email-policy`
* Breaking: Moved organization API key commands to the new `organization` group (`list-keys`, `create-key`, `get-key`, `update-key`, `delete-key`)
* Breaking: Moved project management to the new `organization` group (`list-projects`, `create-project`, `get-project`, `update-project`, `delete-project`)
* Added: `presences` commands to track presence and usage
* Added: `project get` command

## 21.0.1

* Fixed: Kotlin type generation now emits `Long` for Appwrite integer attributes to match the Android and Kotlin SDK models
* Fixed: Type generation now includes Sites when validating supported config resource keys

## 21.0.0

* Breaking: Renamed `project update-canonical-emails` to `update-deny-canonical-email-policy`
* Breaking: Renamed `project update-disposable-emails` to `update-deny-disposable-email-policy`
* Breaking: Renamed `project update-free-emails` to `update-deny-free-email-policy`
* Breaking: Renamed `proxy update-rule-verification` to `update-rule-status`
* Breaking: Removed `--search` option from `proxy list-rules`
* Breaking: `functions create-variable` and `sites create-variable` now require `--variable-id`
* Added: `databases create-big-int-attribute` and `update-big-int-attribute` commands
* Added: `tablesdb create-big-int-column` and `update-big-int-column` commands
* Added: `--prompt` option on `project update-o-auth-2-google`
* Added: Pagination and filter flags on `vcs list-repository-branches` and `sites list-variables`
* Added: `--on-duplicate` option on `migrations create-appwrite-migration`, `create-csv-import`, and `create-json-import`
* Updated: Extended key-list query filters with `key`, `resourceType`, `resourceId`, and `secret`

## 20.1.0

* Added `--switch` and `--new` flags on `appwrite login` to explicitly manage multiple saved accounts
* Fixed `appwrite login` to verify the current session via the API and clean up stale guest/unauthorized sessions instead of trusting local metadata
* Updated Cloud account login to normalize endpoints to `https://cloud.appwrite.io/v1` and reject regional Cloud endpoints

## 20.0.0

* Breaking: Removed `projects delete`. Use `project delete` instead
* Breaking: Replaced `projects update-mock-numbers` with `project create-mock-phone`, `list-mock-phones`, `get-mock-phone`, `update-mock-phone`, and `delete-mock-phone`
* Breaking: Renamed `projects update-auth-status` to `project update-auth-method` with `--method-id` and `--enabled` flags
* Breaking: Replaced `projects update-o-auth-2` with per-provider `project update-o-auth-2-<provider>` commands
* Breaking: Replaced `projects create-jwt` with `project create-ephemeral-key` for short-lived API keys
* Added `project list-o-auth-2-providers`, `get-o-auth-2-provider`, `list-policies`, `get-policy`, and `list-email-templates` commands
* Added `fusionauth`, `keycloak`, and `kick` to supported OAuth2 providers in `account` session commands
* Added `includes` field in `appwrite.config.json` to split resource arrays into separate JSON files; `init`, `pull`, and `push` resolve resource paths relative to each include

## 19.2.0

* Added `completion install` command with completion scripts for zsh, bash, and fish
* Added automatic shell-completion install step to the `install.sh` standalone installer
* Updated Homebrew install and update flow to detect the installed formula and recommend the official `appwrite/appwrite/appwrite` tap when a different one is in use
* Fixed standalone update check to point at the `appwrite/sdk-for-cli` releases feed instead of the legacy `appwrite/appwrite-cli` repo

## 19.1.0

* Added `--where`, `--sort-asc`, `--sort-desc`, `--limit`, `--offset`, `--cursor-after`, and `--cursor-before` flags on list commands across services
* Added `--select` flag on `databases list-documents`, `tablesdb list-rows`, and other row/document list commands
* Added a hint after query-related errors that points to the new flag-based filters
* Updated `--queries` description to mark it as the legacy raw-JSON option, recommending the new flag-based filters for common cases
* Updated bundled examples to demonstrate `--limit` on list commands

## 19.0.0

* Breaking: Renamed `project update-protocol-status` to `project update-protocol`
* Breaking: Renamed `project update-service-status` to `project update-service`
* Breaking: Removed `projects get-sms-template`, `projects update-sms-template`, and `projects delete-sms-template` commands
* Breaking: Made `--locale` optional on `projects get-email-template`, `projects update-email-template`, and `projects delete-email-template`
* Breaking: Reordered `projects update-email-template` parameters — `--locale` is now optional and placed after `--message`
* Added `--path` prompt during `init site` to choose the local site directory
* Added support for initializing sites into existing non-empty directories without a starter template
* Added structured column renderer for `runtimes` listings
* Added activity-based deployment timeout tracking so healthy long builds in `push function` and `push site` no longer time out prematurely
* Updated `brew install`/`brew upgrade` to use the fully-qualified `appwrite/appwrite/appwrite` formula from the `appwrite/homebrew-appwrite` tap
* Updated `deploymentRetention` prompt to clarify it counts days of non-active deployments
* Updated CLI help and error output to display the correct program name `appwrite`
* Removed `enabled` field from site entries in `appwrite.config.json`
* Fixed command help text to render links inline without markdown brackets

## 18.2.0

* Added source code and entrypoint validation before local function execution
* Added macOS code signature verification for standalone binary installs
* Added `webhooks` to the list of supported resource types
* Updated Open Runtimes version from v4 to v5
* Updated runtime start commands from `sh` to `bash` for improved compatibility
* Updated project init flow with contextual next steps and improved prompts
* Fixed Docker process error handling to report exit codes and signals
* Fixed function container startup to detect early exits before port open
* Fixed stderr output in Docker start to write to stderr instead of stdout

## 18.1.0

* Added site screenshot terminal preview after `push site` deployments
* Added standalone binary self-update support in `update` command
* Added installation method detection for smarter version checks (npm, Homebrew, standalone)
* Updated TypeScript query types to use `QueryableFieldValue` for improved type inference
* Fixed indentation in deployment error handling blocks

## 18.0.0

* Breaking: Moved `keys` commands from `projects` to `project` service. They no longer require `--project-id`, but `create-key` now requires `--key-id`
* Breaking: Moved `update-labels` from `projects` to `project` service
* Breaking: Moved platform commands from `projects` to `project` service and split generic `create-platform`/`update-platform` into platform-specific commands: `create-android-platform`, `create-apple-platform`, `create-linux-platform`, `create-web-platform`, `create-windows-platform` (and corresponding update variants). New methods no longer require `--project-id`, but require `--platform-id`, and `key` parameter was renamed to platform-specific terminology, such as `--bundle-identifier` or `--package-name`
* Added `webhooks` service with full pull and push support for project webhooks
* Added `project` service with `update-canonical-emails`, `update-disposable-emails`, `update-free-emails` commands
* Added `protocols` configuration section for REST, GraphQL, and WebSocket protocol status
* Added `--purge` option to `databases update-collection` and `tables-db update-table` commands
* Added `x` (Twitter/X) as supported OAuth2 provider
* Fixed console URL generation to normalize cloud.appwrite.io subdomains
* Fixed Linuxbrew path detection in update command

## 17.4.0

* Added `--activate` flag for `push function` to control deployment activation
* Added deployment logs with URLs and elapsed time after successful push
* Added explicit deployment activation step after function build completes
* Added `deploymentRetention` field to function initialization config
* Added `--template-repository`, `--template-owner`, `--template-root-directory`, `--template-version` options to `functions create`
* Added structured collection rendering for improved CLI output formatting
* Fixed console URL generation to properly strip `/v1` suffix
* Fixed force mode to always prompt for code pull confirmation
* Fixed sensitive key detection to match suffix-based patterns
* Fixed redaction hint display for JSON and raw output modes
* Fixed SDK client initialization for account, locale, migrations, proxy, and vcs services
* Moved `getSafeDirectoryName` to shared utils for pull commands
* Updated `.gitignore` rule handling to add rules individually

## 17.3.1

* Fixed: proxy CLI using console SDK instead of project SDK

## 17.3.0

* Added: Automatic update notifications with cached version checks
* Added: `create-json-export` and `create-json-import` migration commands
* Added: `--activate` flag for site push to control deployment activation
* Added: Deployment retention prompt during site initialization
* Updated: Improved `.gitignore` handling with hierarchical ignore support
* Updated: Function and site init now use safe directory names
* Updated: Config writer prunes empty resource arrays and deprecated site fields
* Fixed: Pinned `@appwrite.io/console` dependency to `~8.2.0`

## 17.2.1

* Fixed: Removed `bun.lock` from `.gitignore` so lockfile is tracked in version control

## 17.2.0

* Added `--show-secrets` flag to control display of sensitive values in output
* Added automatic redaction of secrets, API keys, tokens, and passwords in CLI output
* Added `init skill` command for installing Appwrite agent skills for AI coding agents
* Added automatic agent skills detection and installation during project initialization
* Added `OPEN_RUNTIMES_ENTRYPOINT` environment variable to function emulation
* Updated `-j` and `-R` output flag descriptions for clarity
* Fixed project init to gracefully handle override decline instead of exiting

## 17.1.0

* Added `organizations` command group with multiple subcommands
* Added `--raw` option for full raw JSON output in version command
* Updated docs for `organizations` commands with new examples

## 17.0.0

* [BREAKING] Changed `$sequence` type from `int` to `string` for rows and documents
* [BREAKING] Removed `--key` required option from `appwrite project update-variable` (now optional)
* [BREAKING] Removed billing/payment commands from `appwrite account`: `list-billing-addresses`, `create-billing-address`, `get-billing-address`, `update-billing-address`, `delete-billing-address`, `get-coupon`, `list-invoices`, `list-payment-methods`, `create-payment-method`, `get-payment-method`, `update-payment-method`, `delete-payment-method`, `update-payment-method-provider`, `update-payment-method-mandate-options`
* [BREAKING] Removed cloud-specific health commands: `get-console-pausing`, `get-queue-billing-project-aggregation`, `get-queue-billing-team-aggregation`, `get-queue-priority-builds`, `get-queue-region-manager`, `get-queue-threats`
* [BREAKING] Removed `appwrite projects update-console-access` command
* Added `--variable-id` required parameter to `appwrite project create-variable`
* Added `--queries` and `--total` optional parameters to `appwrite project list-variables`
* Added new `appwrite users update-impersonator` command
* Added `impersonator` as a filterable attribute in `appwrite users list`
* Updated API version badge to `1.9.0` and compatibility to server version `1.9.x`

## 16.0.0

* Breaking: Moved webhook commands from `projects` to new `webhooks` service.

## 15.1.0

* Added `resolveFileParam` to convert file or directory paths into `File` objects and tar.gz packages

## 15.0.0

* Breaking: Separated `specification` with `buildSpecification` and `runtimeSpecification`; added `deploymentRetention` to both functions and sites
* Added `startCommand` option for sites
* Updated `init sites` prompts to select `buildSpecification` and `runtimeSpecification`
* Improved unauthorized error messages

## 14.0.1

* Fixed `push tables` not passing `encrypt` parameter for varchar, text, mediumtext, and longtext string types
* Fixed NVM installation path not being detected by `update` command
* Updated `tar` dependency to v7.4.3

## 14.0.0

* Breaking: Changed createDeployment signature; activate option now optional with default true; parameter order updated
* Added TTL option for cached query results in listDocuments and listRows
* Added get-console-pausing health status command
* Added project management commands: update-console-access and update-status
* Updated site deployment docs to reflect new activate option
* Updated role length limit for teams to 81 characters
* Added appwriteImportSource and importExtension options to code generator

## 13.6.1

* Fix ESLind warnings in generated files with `appwrite generate` command.

## 13.6.0

* Fix large double values (e.g. `1.7976931348623157e+308`) being expanded into huge integer literals.
* Added `activities` command with `list-events` and `get-event`.
* Added `backups` command with full support for archives, policies, and restorations.
* Added account billing commands (addresses, coupons, invoices).
* Added account API key management commands.
* Added account payment method management commands (including provider and mandate updates).

## 13.5.0

* New: Added CLI commands under projects: `list-schedules`, `create-schedule`, and `get-schedule` for managing project schedules.
* Packaging/build: Updated packaging to support explicit ESM/CJS outputs with exports. main now points to dist/index.cjs, module to dist/index.js, and exports include type and entry-point mappings. This enables better compatibility for both ESM and CommonJS users.

## 13.4.0

* Add `--queries` option to `list-keys` command
* Add `--key-id` option to `create-key` command
* Add support for the new `Backups` service
* Add `encrypt` param support to new string type attributes
* Fix `bignumber.js` bundler conflict by removing direct dependency in favor of transitive dependency from `json-bigint`
* Fix missing region in console failUrl for failed deployments

## 13.3.2

- Fix handle null rows in table parser 
- Export config zod schemas

## 13.3.1

- Fix generated TS imports to auto-detect ESM vs non-ESM

## 13.3.0

- Support type generation for text/varchar/mediumtext/longtext attributes
- Improve CLI session switch and logout UX

## 13.2.1

- Fix site domain construction

## 13.2.0

- Feat: Add dedicated commands for text-based attribute management:
  - `create-varchar-attribute`, `create-text-attribute`, `create-mediumtext-attribute`, `create-longtext-attribute`
  - `update-varchar-attribute`, `update-text-attribute`, `update-mediumtext-attribute`, `update-longtext-attribute`
- Feat: Add specifications support to CLI
- Fix: Include specifications parameter when updating functions
- Chore: Update installation docs and examples for new text attribute/column commands
- Refactor: Simplify `update-collection` command syntax

## 13.1.0

- Mark `appwrite generate` command as stable
- Improve permissions param to be a typesafe callback
- Fix relationship handling in generated code
- Fix `appwrite client` properly hanlding `--key` parameter
- Fix `init site` not working on Windows

## 13.1.0-rc.3

- Allow generation of server side CRUD operations on databases and tables
- Fix npm distribution failing due to missing template files in bundle

## 13.1.0-rc.2

- Update generated `databases` services to automatically initialize a client instance
- Update generator to use handlebars templates

## 13.1.0-rc.1

- Feat: `appwrite generate` command to create a fully typesafe SDK for your Appwrite project
- Chore: improve creation of columns during table creation by passing them directly instead of creating them one by one
- Improved config validation by adding extra rules in zod schema

## 13.0.1

- Fix `project init` command leading to Cannot convert to BigInt error
- Fix filter out unwanted attributes being pulled in the config file

## 13.0.0

- Mark release as stable
- Feat: add pull sync on destruction of remote resources (+ confirmation)
- Fix: refine zod schema to check string size
- Validate using zod schema during push cli command
- Maintain order of keys in local config

## 13.0.0-rc.5

- Fix push all command not working correctly

## 13.0.0-rc.4

- Fix CLI ES module import issues

## 13.0.0-rc.3

- Add `Schema` class for programmatically pushing and pulling appwrite config
- Add client side db generation using `schema.db.generate()` command

## 13.0.0-rc.2

- Fixes a lot of typescript errors throughout the codebase

## 13.0.0-rc.1

- Migrates codebase from JavaScript to TypeScript

## 12.0.1

Fix type generation for `point`, `lineString` and `polygon` columns

## 12.0.0

* Change `create-deployment-template`'s `version` parameter to `type` and `reference`. eg. usage - `create-deployment-template --type tag --reference 1.0.0`
* Remove `bucket-id` parameter from `create-csv-export` command
* Allow enabling or disabling of image `transformations` in a bucket
* Fix type generation for `point`, `lineString` and `polygon` columns

## 11.1.1

* Fix duplicate `enums` during type generation by prefixing them with table name. For example, `enum MyEnum` will now be generated as `enum MyTableMyEnum` to avoid conflicts.

## 11.1.0

* Add `total` parameter to list queries allowing skipping counting rows in a table for improved performance

## 11.0.0

* Rename `create-csv-migration` to `create-csv-import` command to create a CSV import of a collection/table
* Add `create-csv-export` command to create a CSV export of a collection/table
* Add `create-resend-provider` and `update-resend-provider` commands to create and update a Resend Email provider
* Fix syncing of tables deleted locally during `push tables` command
* Fix added push command support for cli spatial types
* Fix attribute changing during push
* Replace pkg with @yao-pkg/pkg in dependencies

## 10.2.3

* Fix `init tables` command not working
* Improve tablesDB resource syncing during `push tables` command

## 10.2.2

* Fix `logout` command showing duplicate sessions
* Fix `logout` command showing a blank email even when logged out
* Add syncing of `tablesDB` resource during `push tables` command

## 10.2.1

* Add transaction support for Databases and TablesDB

## 10.1.0

* Deprecate `createVerification` method in `Account` service
* Add `createEmailVerification` method in `Account` service

## 10.0.1

* Fix CLI Dart model generation issues
* Fix row permissions and security sync
* Fix error when pushing columns with relationships
* Fix resource name from attributes to columns for TablesDB indexes

## 10.0.0

* **Breaking:** Removed Avatars CLI command and all related subcommands; corresponding examples deleted
* **Feat:** Geo defaults now accept coordinate arrays for Databases and Tables DB, with automatic normalization
* **Feat:** Pull command skips deprecated resources by default and shows clearer totals/messages
* **Feat:** Updated CLI descriptions: Databases marked legacy; added tables-db, projects, and project
* Fix TypeScript type generation now quotes invalid property names to produce valid typings
* Update documentation: Removed Avatars CLI examples and updated help text to reflect new geo defaults and terminology

## 8.3.0

* **Feat:** Add support for `appwrite.config.json` file
  * All new projects will be initialized with this configuration file
  * Resolves bundler conflicts (e.g., Vite) that incorrectly interpret `.json` files as library imports
* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Type generation fixes:
  * Fix relationships using the relatedCollection's id instead of name
  * Update auto generated comment to show relative path instead of absolute path

> **Note:** The existing `appwrite.json` file remains fully supported for backward compatibility

## 8.2.2

* Fix object comparison logic when pushing settings
* Type generation fixes:
   * Dart: Fixed import casing to snake_case, removed `extends Document` and hardcoded attributes, removed unnecessary imports
   * Java: Fixed indentation to 4 spaces, updated imports to `java.util.Objects`, fixed enum casing in strict mode as per [Oracle official docs](https://docs.oracle.com/javase/tutorial/java/javaOO/enum.html)
   * Javascript: Updated optional values formatting from `|null` to `| null`
   * Kotlin: Fixed indentation to 4 spaces per [Kotlinlang official docs](https://kotlinlang.org/docs/coding-conventions.html#indentation)
   * PHP: Fixed indentation to 4 spaces per [PHP Fig official docs](https://www.php-fig.org/psr/psr-2/)
   * Swift: Fixed indentation to 4 spaces, improved `decodeIfPresent` usage for optionals, added missing `public` to `init` method
   * Typescript: Fixed indentation to 4 spaces per [Typescript coding guidelines](https://github.com/microsoft/TypeScript/wiki/Coding-guidelines)

## 8.2.1

* Added `--with-variables` option to the Sites command for adding/updating environment variables  
* Fixed Functions environment variables not being pushed with `--with-variables`  
* Removed `awaitPools` when wiping old variables  

> **Note:** Storing environment variables in the `vars` attribute of `appwrite.json` is now deprecated due to security risks. Variables are now synced directly from the `.env` file in the root directory of the function’s or site’s folder.

## 8.2.0

* Add `encrypt` attribute support
* Add improved warnings on attribute recreation and deletion
* Fix `null` parsing error when using create attribute command
* Type generation fixes and improvements:
  * Add `--strict` / `-s` flag to `appwrite types` command to generate types in strict mode. This automatically converts the casing of attributes to match the language's naming conventions
  * Add automatic package import to `dart` language which uses package detection to import the correct package
  * Add `Document` class extension to generated types in `dart` and `js` language to support internal attributes like `$id` and `$collectionId` etc.
  * Add proper enum support to `js` language
  * Fix indentation in `java`, `kotlin` and `swift` to use 2 spaces instead of 4 for consistency across all languages
  * Fix doc comments to use correct syntax in various languages (for eg. `///` instead of `/*`)
  * Update enums in `dart` to use lowerCamelCase in `strict` mode as per [constant_identifier_names](https://dart.dev/tools/diagnostics/constant_identifier_names?utm_source=dartdev&utm_medium=redir&utm_id=diagcode&utm_content=constant_identifier_names)

## 8.1.1

* Fix circular dependency issue due to usage of `success` method in `utils.js` file from `parser.js` file
* Type generation fixes:
  * Add ability to generate types directly to a specific file by passing a file path to `appwrite types output_path`, instead of just a directory
  * Fix non-required attributes to not be null if default value is provided
  * Fix `Models` import error
  * Improve formatting and add auto-generated comments

## 8.1.0

* Add multi-region support to `init` command
* Update `init` command to clear previous configuration in `appwrite.json`
* Update localConfig to store multi-region endpoint
* Fix throw error when creating unknown attribute instead of timing out
* Fix equal comparison of large numbers and BigNumber instances using proper equality checks
* Fix duplication of reasons when comparing localConfig with remoteConfig
* Fix `firstOrNull()` to `firstOrNull` in types generation for dart
* Refactor to use `isCloud()` method consistently

## 8.0.2

* Add Type generation fixes:
  * Properly handle enum attributes in dart, java and kotlin
  * Fix initialisation of null attributes in dart's fromMap method
  * Fix relationships and enums in swift

## 8.0.1

* Add `resourceId` and `resourceType` attributes to `createRedirectRule`
* Add `providerReference` to vcs command for getting repository contents
* Add warning comment to `bulk updateDocuments` method
* Fix type generation for enums in Typescript and PHP language

## 8.0.0

* Add `types` command to generate language specific typings for collections. Currently supports - `php`, `swift`, `dart`, `js`, `ts`, `kotlin` and `java`
* Update bulk operation docs to include experiment feature warnings
* Remove assistant service and commands

## 7.0.0

* Add `sites` command
* Add `tokens` command
* Add `devKeys` support to `projects` command
* Add `init site`, `pull site` and `push site` commands
* Add bulk operation methods like `createDocuments`, `deleteDocuments` etc.
* Add new upsert methods: `upsertDocument` and `upsertDocuments`
* Update GET requests to not include content-type header

## 6.2.3

* Fix hot swapping error in `python-ml` function

## 6.2.2

* Fix GitHub builds by adding `qemu-system` package
* Fix attribute creation timed out

## 6.2.1

* Add `listOrganizations` method to `organizations` service and fix init project command

## 6.2.0

* Add specifications support to CLI
* Update package version
* Fix: Missed specifications param when updating a function
