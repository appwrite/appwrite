# Change Log

## 0.4.0

* Added: Appwrite Zero project setup flow (`npx appwrite-cli@latest zero`) across all ten SDK skills, the `cli` skill, and the `deploy-site` and `deploy-function` commands
* Added: Appwrite Review code review skills (`review-status`, `review-loop`, `review-learn`, `review-setup`, `review-threads`)
* Added: `push table --prune` safety guidance and `init project` closest-region selection in the `cli` skill
* Fixed: server SDK skills forward the user agent through a per-request admin client instead of the session client

## 0.3.1

* Fixed: the Rust skill shows aggregation, grouping, join and query builder examples like the other SDK skills
* Fixed: the Rust skill no longer pins `appwrite = "0.3.0"`, which predates those query APIs

## 0.3.0

* Updated: Rewrote the CLI skill as guidance rather than a command catalog, deferring flags to `appwrite <command> --help`
* Added: `Query` relationship, aggregation, grouping, join and builder examples in every SDK skill
* Fixed: plugin and marketplace homepages point at the current Claude Code docs page

## 0.2.0

* Breaking: Replaced local `appwrite-api` and `appwrite-docs` MCP servers with the hosted server at `https://mcp.appwrite.io/`
* Removed: `userConfig` from the plugin manifest, no longer needed with the OAuth-authenticated hosted MCP server
* Updated: Descriptions reference the hosted Appwrite MCP server
* Updated: SDK skills content refreshed
* Updated: LICENSE changed from MIT to the Appwrite BSD-style license

## 0.1.0

* Added: Initial release of the Appwrite plugin for Claude Code
