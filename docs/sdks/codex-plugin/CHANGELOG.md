# Change Log

## 0.4.0

* Added: Appwrite Zero project setup flow (`npx appwrite-cli@latest zero`) across all ten SDK skills, the `appwrite-cli` skill, and the `appwrite-deploy-site` and `appwrite-deploy-function` skills
* Added: Appwrite Review code review skills (`appwrite-review-status`, `appwrite-review-loop`, `appwrite-review-learn`, `appwrite-review-setup`, `appwrite-review-threads`)
* Added: `push table --prune` safety guidance and `init project` closest-region selection in the `appwrite-cli` skill
* Fixed: server SDK skills forward the user agent through a per-request admin client instead of the session client

## 0.3.0

* Updated: Rewrote the CLI skill as guidance rather than a command catalog, deferring flags to `appwrite <command> --help`
* Added: `Query` relationship, aggregation, grouping, join and builder examples in every SDK skill
* Fixed: deploy skills are named `appwrite-deploy-function` and `appwrite-deploy-site`, matching their directories
* Fixed: plugin homepage points at the current Codex docs page

## 0.2.0

* Breaking: Replaced the `appwrite-docs` MCP server with the hosted server at `https://mcp.appwrite.io/` in `.mcp.json` and `config.toml`
* Updated: Descriptions reference the hosted Appwrite MCP server
* Updated: SDK skills content refreshed

## 0.1.1

* Fixed: Marketplace and plugin manifest paths now resolve relative to the plugin directory

## 0.1.0

* Added: Initial release of the Appwrite plugin for Codex
