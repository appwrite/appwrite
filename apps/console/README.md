# Appwrite Console

The web app behind [appwrite.io](https://appwrite.io) and the console bundled with self-hosted Appwrite. Cloud and self-hosted both run this code in production. The image is `appwrite/new`, pinned for self-hosted installs in `docker-compose.yml`.

TanStack Start, Tailwind CSS, and shadcn/ui. The runtime is Bun 1.4.

## What it serves

On Cloud this process is the marketing site, docs, blog, changelog, and the signed-in console. The self-hosted image serves the console. `VITE_CONSOLE_PROFILE` picks the split. `cloud` is the default. `self-hosted` turns off billing, marketing, and the other Cloud-only screens. The flags live in `src/lib/console-profiles.ts`.

Inside a project the console covers Auth, databases, storage, functions, sites, and messaging. Public pages are written in `src/content`.

## Stack

- TanStack Start, Router, and Query
- React 19
- Tailwind CSS 4 and shadcn/ui
- `@appwrite.io/console` for the Console SDK and `Models` types
- `@appwrite.io/specs` for OpenAPI
- Bun 1.4
- Vite

## Run locally

Install [Bun](https://bun.sh/) 1.4 or later.

```bash
git clone https://github.com/appwrite/appwrite.git
cd appwrite/apps/console
bun install
cp .env.example .env
bun run dev
```

Open `http://localhost:3000`.

`.env.example` lists every variable, with comments. Leave `VITE_APPWRITE_ENDPOINT` at `https://cloud.appwrite.io/v1` to use Cloud. Point it at your API when the server is local. Set `VITE_CONSOLE_PROFILE=self-hosted` to match a self-hosted install.

The endpoint, profile, Stripe key, and Sentry DSN are read when the server starts and written into the page. A restart picks up a change. Company name, sales URL, privacy email, and `CDN_ORIGIN` are compiled into the build. See `src/lib/runtime-config.ts` and the Dockerfile.

## Scripts

Run with `bun run <command>`. `package.json` has the full list. Generators live in `scripts/`. `generate:sitemap` runs `scripts/generate-sitemap.ts`.

| Command | What it does |
| --- | --- |
| `dev` | Dev server on port 3000 |
| `build` | Production build used in CI |
| `build:node` | Image build. Sets `FOR_SITES=true`, skips sourcemaps, and prerenders marketing pages |
| `start` | Production server, `server.ts` on port 3000 |
| `serve` | Preview a production build |
| `check` | TypeScript |
| `lint` | ESLint, connect-snippet width, hidden Unicode, removed blog links, and native dialogs |
| `format` / `format:check` | Prettier |
| `test:unit` | `bun test tests/unit` |
| `test` / `e2e` | Playwright smoke suite |
| `test:ui` / `e2e:ui` | Playwright UI |
| `install-browsers` | Chromium for Playwright |
| `e2e:databases` | MySQL, Postgres, TablesDB, DocumentsDB, and VectorsDB projects |
| `generate:routes` | TanStack Router route tree |
| `generate:specs` | API reference version metadata. Pass `--copy` after a build to fill `dist/specs/` |

## API specs

OpenAPI files come from the pinned `@appwrite.io/specs` package, under `node_modules/@appwrite.io/specs/specs/`. The explorer loads them in `src/lib/api-explorer/load-spec.ts`. Reference pages load them from `src/server/api-reference/`.

Method signatures and `Models` types come from `@appwrite.io/console`.

`bun run generate:specs` writes version metadata. `bun run generate:specs --copy` copies specs and examples into `dist/specs/` for production. Keep both steps. Specs stay in the package.

## Layout

```
src/
  components/     React components. global/ is shared, pages/ follows routes
  content/        Docs, blog, changelog, legal, integrations
  hooks/
  lib/            SDK client, React Query hooks, profiles, CLI shell
  routes/         TanStack Router files
  server/         SSR helpers and middleware
  styles.css
public/           Static files that stay on the app origin
scripts/          Build and content generators
server.ts         Production Bun server
e2e/              Playwright
tests/unit/       Bun tests
```

Screen conventions, SDK usage, and route prefetching are in [AGENTS.md](./AGENTS.md). Call Appwrite through `src/lib/appwrite/sdk.ts` and type responses with `Models` from `@appwrite.io/console`. Prefetch data in the route loader.

## In-browser CLI

The console terminal runs the Appwrite CLI compiled to WebAssembly with `GOOS=js GOARCH=wasm` and `-tags browser`. The `appwrite-cli-wasm` package ships `appwrite.wasm` and the matching `wasm_exec.js`. `src/lib/cli-shell/wasm/asset-urls.ts` loads both from that package. The two files have to come from the same toolchain.

Bump the dependency and `CLI_WASM_VERSION` in `src/lib/cli-shell/wasm/constants.ts` together.

```sh
bun add appwrite-cli-wasm@<version>
```

`VITE_APPWRITE_CLI_WASM_URL` and `VITE_APPWRITE_CLI_WASM_EXEC_URL` point both files at a local build. Set both.

`bun test tests/unit/cli-wasm-runtime.test.ts` runs the runtime against the real artifact and skips the wasm cases when the files are missing.

## Tests

`bun run test:unit` runs the Bun unit tests.

Playwright covers the website and the console. Put credentials in `.env`.

```bash
E2E_TEST_EMAIL=you@example.com
E2E_TEST_PASSWORD=your-password
```

`E2E_ORG_ID` and `E2E_PROJECT_ID` pin the organization and project the console tests open.

```bash
bun run install-browsers
bun run test
bun run test:ui
```

`bun run test` is the read-only smoke suite. Database write suites are `bun run e2e:databases` and the per-engine `e2e:*` scripts in `package.json`. Usage and self-hosted organization suites need a local Appwrite backend.

## Lint and format

```bash
bun run lint
bun run lint:content -- --fix
bun run format
bun run format:check
```

## Production

Pushing an `apps/console/<version>` tag publishes two `appwrite/new` tags. Release steps are in [apps/README.md](../README.md).

| Tag | Where it runs |
| --- | --- |
| `<version>` | Cloud. Hashed assets load from `https://cdn.appwrite.io` |
| `<version>-self-hosted` | Self-hosted. Assets load from the installation |

`docker-compose.yml` pins the `appwrite-console` service to the self-hosted tag and sets `VITE_CONSOLE_PROFILE=self-hosted`. How assets are published is in [docs/static-assets.md](./docs/static-assets.md).

## Performance monitor

In development the browser console exposes `window.performanceMonitor`. It stays idle until you call it.

```javascript
window.performanceMonitor.monitorIntervals()
window.performanceMonitor.monitorReactQueryQueries()
window.performanceMonitor.monitorFrameRate()
window.performanceMonitor.generateReport()
```

## License

BSD 3-Clause, same as the rest of this repository. See [LICENSE](../../LICENSE).

## Support

Open an issue or pull request on this repository. Product docs are at [appwrite.io/docs](https://appwrite.io/docs).
