# Change Log

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
