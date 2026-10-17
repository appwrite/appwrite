## Getting started

Sign in to Appwrite Cloud:

```sh
appwrite login
```

The CLI opens your browser and prints a one-time code to confirm there:

```
To sign in, confirm the code below in your browser:

  Code: <CODE>
  URL:  <VERIFICATION_URL>

Signed in as you@example.com on https://cloud.appwrite.io/v1
```

If the browser does not open, visit the URL yourself. Then create a project, or link the current directory to an existing one:

```sh
appwrite init project
```

This writes `appwrite.config.json`, which records the project and every resource the CLI manages for it. Commit it to version control. To skip the prompts, pass `--organization-id`, `--project-id` and `--project-name`. Use `appwrite list-organizations` and `appwrite list-projects` to look up IDs.

Add resources, then push them:

```sh
appwrite init function
appwrite push function
```

## Self-hosted instances

Point `login` at your instance's API endpoint. Self-hosted instances sign in with email and password:

```sh
appwrite login --endpoint https://appwrite.example.com/v1
```

The CLI prompts for `Enter your email` and `Enter your password`, or takes them from `--email` and `--password`. If the account has multi-factor authentication, it then asks for a factor and a code. Pass them up front with `--mfa` (`email`, `phone`, `totp` or `recoveryCode`) and `--code`:

```sh
appwrite login --endpoint https://appwrite.example.com/v1 --email you@example.com --password "$PASSWORD" --mfa totp --code 123456
```

Requests to an instance with a self-signed certificate are rejected by default. If you trust the instance, allow its certificate for the active account:

```sh
appwrite client --self-signed true
```

## Managing accounts

The CLI can keep several signed-in accounts, on Cloud and on self-hosted instances, and uses one at a time.

```sh
# Show the active account
appwrite whoami

# List every stored session and which one is active
appwrite sessions

# Sign in to another account without signing out of the current one
appwrite login --new

# Switch the active account, picking from a list
appwrite login --switch

# Switch without a prompt
appwrite login --switch --endpoint https://appwrite.example.com/v1 --email you@example.com

# Sign out of the active account
appwrite logout
```

`logout` revokes the session on the server before removing it locally.

## Working with your project

`init` creates a resource in `appwrite.config.json`, `pull` copies resources from your project into it, and `push` applies it to your project.

| Resource | `init` | `pull` | `push` |
| --- | --- | --- | --- |
| `function` | ✓ | ✓ | ✓ |
| `site` | ✓ | ✓ | ✓ |
| `table` | ✓ | ✓ | ✓ |
| `bucket` | ✓ | ✓ | ✓ |
| `team` | ✓ | ✓ | ✓ |
| `topic` | ✓ | ✓ | ✓ |
| `webhook` | | ✓ | ✓ |
| `settings` | | ✓ | ✓ |
| `collection` (legacy) | ✓ | ✓ | ✓ |

Resource names also accept the plural, so `appwrite push functions` and `appwrite push function` are the same command.

### Functions

```sh
appwrite init function
```

The CLI asks for a name, ID and runtime, copies the starter code for that runtime from [appwrite/templates](https://github.com/appwrite/templates) into `functions/<name>`, and adds the function to `appwrite.config.json`. Fetching the template needs `git`. Pass `--name`, `--function-id` and `--runtime` to skip the prompts, `--source github` to connect a GitHub repository instead of deploying local code, and `--deploy` to create and deploy the function straight away.

```sh
appwrite push function
```

`push function` asks which functions to deploy, creates or updates them, uploads the code and streams the build logs. Pass `--function-id <ID>` for one function or `--all` for every function, `--with-variables` to replace the function's variables with those in its `.env` file, `--no-logs` to skip the build logs, and `--async` to return without waiting for the deployment.

### Sites

```sh
appwrite init site
appwrite push site
```

`init site` asks for a framework and can download its starter template. `push site` builds and deploys like `push function`; in a terminal it also prints a preview of the deployed page.

### Everything at once

```sh
appwrite pull all
appwrite push all
```

`push all` pushes settings, buckets, teams, webhooks, topics, functions, sites and tables, in that order. It skips legacy collections.

## Local development

Run a function locally in the same runtime container Appwrite uses. This needs [Docker](https://docs.docker.com/engine/install/).

```sh
appwrite run function
```

The function is served at `http://localhost:<port>/` and restarts when you edit its code. Useful flags:

| Flag | Description |
| --- | --- |
| `--function-id` | Function to run, instead of choosing from a list |
| `--port` | Local port |
| `--user-id` | ID of a user to impersonate |
| `--with-variables` | Load the function's variables from your project |
| `--no-reload` | Don't restart when files change |

## Generating code

Generate a type-safe TypeScript SDK for the databases and tables in `appwrite.config.json`:

```sh
appwrite generate
```

Files are written to `generated/` by default. Change it with `--output`.

Generate model types for your tables and collections:

```sh
appwrite types ./types
```

The language is detected from your project. Set it with `--language`: `ts`, `js`, `php`, `kotlin`, `swift`, `java`, `dart` or `cs`. Pass `--strict` to convert field names to the language's naming conventions.

## AI agent skills

Install Appwrite [skills](https://github.com/appwrite/skills) for AI coding agents into `.agents` or `.claude`:

```sh
appwrite init skill
```

Without a terminal, pass the selection as flags:

```sh
appwrite init skill --all --agent .claude --method copy
```

`--skill` installs a single skill and can be repeated. `init project` also installs skills that match your project.

## Calling the API

Every Appwrite API is available as `appwrite <service> <command> [flags]`:

```sh
appwrite users list
appwrite users create --user-id "unique()" --email hello@appwrite.io --password very_strong_password
appwrite functions create-execution --function-id <FUNCTION_ID> --body '{"hello":"world"}'
```

Pass JSON arguments as a single-quoted string with double-quoted keys and values. Repeat a flag to pass an array:

```sh
appwrite tablesdb create-row \
    --database-id <DATABASE_ID> \
    --table-id <TABLE_ID> \
    --row-id "unique()" \
    --data '{"name": "Walter White"}' \
    --permissions 'read("any")' \
    --permissions 'read("team:abc")'
```

List commands accept `--filter` (for example `--filter 'email=hello@appwrite.io'`), `--sort-asc`, `--sort-desc`, `--limit` and `--offset`.

Results are printed as a table. Use `--json` for filtered JSON or `--raw` for the full response. Secrets in the output are redacted unless you pass `--show-secrets`.

```sh
appwrite users list --json
```

To discover services, commands and their flags:

```sh
appwrite --help
appwrite users --help
appwrite users list --help
```

To inspect or reset the CLI's configuration:

```sh
# Show the endpoint, project and masked credentials in use
appwrite client --debug

# Sign out of every session and clear the configuration
appwrite client --reset
```

## CI/CD

In CI, authenticate with an [API key](https://appwrite.io/docs/advanced/security/api-keys) instead of signing in. Give the key the scopes for the resources you push.

```yaml
name: Deploy

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    env:
      APPWRITE_NO_UPDATE_CHECK: 1
    steps:
      - uses: actions/checkout@v4

      - name: Install the Appwrite CLI
        run: npm install -g appwrite-cli

      - name: Configure the Appwrite CLI
        run: appwrite client --endpoint https://cloud.appwrite.io/v1 --key ${{ secrets.APPWRITE_API_KEY }}

      - name: Deploy
        run: |
          appwrite push tables --all --force
          appwrite push functions --all --force
          appwrite push sites --all --force
```

Without an interactive terminal, the CLI never waits for input: a prompt fails with the flag that answers it. `--all` selects every resource of that type and `--force` accepts confirmations, including activating new deployments.

An API key works for project resources, not for console-only commands. `push all` and `push settings` need a signed-in account, so push resources one type at a time.

These environment variables override `appwrite.config.json`, so one job can deploy to several projects:

| Variable | Overrides |
| --- | --- |
| `APPWRITE_ENDPOINT` | Project endpoint. Must match the endpoint configured with `appwrite client` |
| `APPWRITE_PROJECT_ID` | Project ID |
| `APPWRITE_ORGANIZATION_ID` | Organization ID |
| `APPWRITE_CONFIG_FILE` | Config file, like `--config-file` |
| `APPWRITE_NO_UPDATE_CHECK` | Set to any value to skip the daily update check |

## Updating

```sh
appwrite update
```

The CLI updates itself the way it was installed: through npm, Homebrew or by replacing the standalone binary. `appwrite update --manual` prints the commands instead of running them.
