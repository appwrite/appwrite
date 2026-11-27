# Change Log

## 0.4.0

* Added: Appwrite Zero project setup flow (`npx appwrite-cli@latest zero`) across all ten SDK skills, the `appwrite-cli` skill, and the `deploy-site` and `deploy-function` commands
* Added: Appwrite Review code review skills (`appwrite-review-status`, `appwrite-review-loop`, `appwrite-review-learn`, `appwrite-review-setup`, `appwrite-review-threads`) and `rules/review-threads.mdc`
* Added: `push table --prune` safety guidance and `init project` closest-region selection in the `appwrite-cli` skill
* Fixed: server SDK skills forward the user agent through a per-request admin client instead of the session client

## 0.3.0

* Updated: Rewrote the CLI skill as guidance rather than a command catalog, deferring flags to `appwrite <command> --help`
* Added: `Query` relationship, aggregation, grouping, join and builder examples in every SDK skill

## 0.2.0

* Breaking: Replaced local `appwrite-api` and `appwrite-docs` MCP servers with the hosted server
* Added: Hosted Appwrite MCP server at `https://mcp.appwrite.io/`, authenticated via OAuth
* Added: README guidance for MCP sign-in, reauthentication, and connection troubleshooting
* Updated: CLI skill covers `includes` multi-file config, `settings`, and `webhooks`
* Updated: CLI skill documents `--where`, `--sort-asc`, `--sort-desc`, `--select` query flags
* Updated: CLI skill moves function and site variables to `.env` with `--with-variables`
* Updated: CLI skill adds `buildSpecification`, `runtimeSpecification`, `deploymentRetention` fields
* Updated: CLI skill adds `project` service, Homebrew tap install, and `login --switch`
* Updated: SDK skills use TablesDB row and table terminology for permissions
* Updated: LICENSE changed from MIT to the Appwrite BSD-style license

## 0.1.0

* Added: Initial release of the Appwrite plugin for Cursor
