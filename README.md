# Appwrite Console v4 - Work in progress

A modern, full-featured web console for managing Appwrite projects, organizations, and resources. Built with TanStack Start, Tailwind CSS, and ShadCN UI.

## Overview

The Appwrite Console provides a comprehensive interface for managing all aspects of your Appwrite infrastructure, including projects, authentication, databases, storage, functions, messaging, sites, and more. It offers an intuitive user experience with real-time updates, advanced search capabilities, and powerful management tools.

## Features

### Core Management

- **Project Management** - Create, configure, and manage Appwrite projects
- **Organization Management** - Handle teams, billing, and organization settings
- **Account Management** - User profiles, authentication, and security settings

### Service Management

- **Authentication** - User management, sessions, OAuth providers, and security policies
- **Databases** - Document and table-based database management, queries, indexes, and relationships
- **Storage** - File uploads, bucket management, previews, and CDN configuration
- **Functions** - Serverless function deployment, execution monitoring, and environment variables
- **Messaging** - Push notifications, SMS, email delivery, and provider configuration
- **Sites** - Deploy and host web applications at the edge

### Developer Experience

- **Command Center** - Quick navigation and search across all resources
- **AI Chat** - Integrated AI assistant for help and guidance
- **Performance Monitoring** - Built-in tools for debugging performance issues
- **Real-time Updates** - Live synchronization with Appwrite backend
- **Dark Mode** - Multiple theme options including light, dark, and custom themes

## Tech Stack

- **Framework**: [TanStack Start](https://tanstack.com/start) - Full-stack React framework
- **Routing**: [TanStack Router](https://tanstack.com/router) - Type-safe routing
- **State Management**: [TanStack Query](https://tanstack.com/query) - Server state management
- **Styling**: [Tailwind CSS](https://tailwindcss.com/) - Utility-first CSS framework
- **UI Components**: [ShadCN UI](https://ui.shadcn.com/) - High-quality React components
- **SDK**: [Appwrite Console SDK](https://github.com/appwrite/console) - Official Appwrite Console SDK
- **Runtime**: [Bun](https://bun.sh/) - Fast JavaScript runtime

## Prerequisites

- [Bun](https://bun.sh/) (v1.4 or later)
- Node.js 18+ (if not using Bun)
- An Appwrite instance or Appwrite Cloud account

## Getting Started

### Installation

1. Clone the repository:

```bash
git clone <repository-url>
cd vibes
```

2. Install dependencies:

```bash
bun install
```

3. Set up environment variables:

```bash
cp .env.example .env
```

Edit `.env` and configure the following variables:

```env
# Required
VITE_APPWRITE_ENDPOINT=https://cloud.appwrite.io/v1

# Optional
VITE_STRIPE_PUBLISHABLE_KEY=pk_test_... # For billing features
VITE_COMPANY_NAME=Appwrite
VITE_CONTACT_SALES_URL=https://appwrite.io/contact
VITE_LEGAL_EMAIL=legal@appwrite.io
```

### Development

Start the development server:

```bash
bun run dev
```

The application will be available at `http://localhost:3000`.

### Building for Production

Build the application:

```bash
bun run build
```

For Node.js deployment:

```bash
bun run build:node
```

Preview the production build:

```bash
bun run serve
```

## Scripts

Run with `bun run <command>`. Scripts live in `scripts/`; each task maps to a file by replacing `:` with `-` (`generate:sitemap` → `generate-sitemap.ts`). Helpers without a task live in `scripts/lib/`.

**Namespaces:** `import:` pulls content from the sibling [`website`](https://github.com/appwrite/website) repo (`../website`); `generate:` writes derived artifacts. Vibes-native content in `src/content/docs-local/` and `src/content/blog-local/` is never overwritten by imports.

| Command                               | Description                                                                                                                       |
| ------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `build` / `build:node`                | Production build (CI vs Docker / Sites)                                                                                           |
| `check`                               | TypeScript check                                                                                                                  |
| `clean`                               | Remove build artifacts                                                                                                            |
| `dev`                                 | Dev server on port 3000                                                                                                           |
| `e2e` / `e2e:ui` / `test` / `test:ui` | Playwright e2e smoke tests                                                                                                        |
| `format` / `format:check`             | Prettier                                                                                                                          |
| `install-browsers`                    | Install Chromium for Playwright                                                                                                   |
| `lint`                                | ESLint, connect-snippet width, and hidden Unicode in `src/content`                                                                |
| `lint:content`                        | Fail if blog/docs/changelog/integrations contain NBSP, zero-width, or BOM characters (`--fix` to rewrite)                         |
| `serve`                               | Preview production build                                                                                                          |
| `start`                               | Production Bun server                                                                                                             |
| `import:blog`                         | Import blog from website                                                                                                          |
| `import:docs`                         | Import docs from website, then `generate:docs`                                                                                    |
| `import:integrations`                 | Import integrations catalog                                                                                                       |
| `generate:blog-local-images`          | Blog-local cover images (optional slug)                                                                                           |
| `generate:content-images`             | Convert content images to AVIF                                                                                                    |
| `generate:docs`                       | Docs manifest, nav, LLM exports, sitemap                                                                                          |
| `generate:docs-exports`               | Curated `llms.txt` hub, `docs/llms.txt`, section indexes (`docs.md`, `blog.md`, …), `/.well-known` discovery, and `llms-full.txt` |
| `generate:docs-nav`                   | Docs section navigation only                                                                                                      |
| `generate:github-stars`               | GitHub star count JSON                                                                                                            |
| `generate:public-icon-manifest`       | Public icon picker manifest                                                                                                       |
| `generate:routes`                     | TanStack Router types                                                                                                             |
| `generate:sitemap`                    | Sitemap files                                                                                                                     |
| `generate:specs`                      | API reference versions; `--copy` after build for `dist/specs/`                                                                    |

## Project Structure

```
src/
├── components/          # React components
│   ├── global/         # Global components (layout, auth, shared)
│   └── pages/          # Page-specific components (route-aligned)
├── hooks/              # Custom React hooks
├── lib/                # Utilities and configurations
│   ├── appwrite/       # Appwrite SDK setup
│   └── react-query/    # React Query hooks and utilities
├── routes/             # TanStack Router route files
├── server/             # Server-side utilities
└── styles.css          # Global styles

public/                 # Static assets
scripts/                # Build, content, and asset scripts
```

## Development Guidelines

This project follows strict development guidelines to ensure consistency and maintainability. Key principles include:

- **SDK Usage**: Always use `src/lib/appwrite/sdk.ts` for Appwrite operations
- **Type Safety**: Always use `Models.*` types from `@appwrite.io/console`
- **Route Prefetching**: Critical data must be prefetched at route level
- **Component Organization**: Route-aligned structure with clear naming conventions
- **React Query Patterns**: Use `queryOptions` pattern for prefetched data

For detailed development guidelines, see [AGENTS.md](./AGENTS.md).

## Performance Monitoring

The app includes a built-in performance monitoring utility for debugging CPU usage and performance issues. The monitor is available in development mode but runs silently by default.

### Usage

In the browser console (development mode only):

```javascript
// Monitor all active setInterval calls
window.performanceMonitor.monitorIntervals()

// Monitor React Query activity (auto-detects queryClient)
window.performanceMonitor.monitorReactQueryQueries()

// Monitor frame rate
window.performanceMonitor.monitorFrameRate()

// Generate a full performance report
window.performanceMonitor.generateReport()
```

### When to Use

Use the performance monitor when:

- CPU usage is unexpectedly high
- The app feels sluggish
- You need to identify what's causing performance issues
- Debugging specific performance problems

The monitor tracks intervals, React Query queries, frame rate, and memory usage without impacting performance when not actively monitoring.

## Appwrite CLI terminal

The console's in-browser terminal runs the real Appwrite CLI, compiled to
WebAssembly (`GOOS=js GOARCH=wasm go build -tags browser`). It arrives through
the `appwrite-cli-wasm` dependency — nothing to fetch by hand — and the build
emits both halves of it as fingerprinted assets.

Two files, always from the same build: `appwrite.wasm`, and Go's `wasm_exec.js`
runtime glue, which only works with a module produced by the toolchain that
generated it. `src/lib/cli-shell/wasm/asset-urls.ts` resolves both from the
package for that reason.

To upgrade the CLI, bump the dependency and the display version beside it:

```sh
bun add appwrite-cli-wasm@<version>
# then update CLI_WASM_VERSION in src/lib/cli-shell/wasm/constants.ts
```

To try a local build instead, point `VITE_APPWRITE_CLI_WASM_URL` and
`VITE_APPWRITE_CLI_WASM_EXEC_URL` at it — both, or the pair will not match.

`bun test tests/unit/cli-wasm-runtime.test.ts` exercises the runtime against the
real artifact and skips the wasm cases when it is absent.

## Testing

This project uses [Playwright](https://playwright.dev/) for end-to-end smoke tests
(read-only page checks for the website and console). Set credentials in `.env`:

```bash
E2E_TEST_EMAIL=you@example.com
E2E_TEST_PASSWORD=your-password
# Optional: pin which org/project console tests open
# E2E_ORG_ID=
# E2E_PROJECT_ID=
```

```bash
# Install Chromium once
bun run install-browsers

# Run the suite (builds the app, signs in, visits pages)
bun run test

# Interactive UI mode
bun run test:ui
```

## Linting & Formatting

This project uses ESLint and Prettier for code quality. `bun run lint` also checks connect-snippet line width and hidden Unicode (NBSP, zero-width, BOM) in `src/content`.

```bash
# Lint code
bun run lint

# Rewrite hidden Unicode in content to normal spaces
bun run lint:content -- --fix

# Format code
bun run format

# Check formatting
bun run format:check
```

## Contributing

1. Follow the development guidelines in [AGENTS.md](./AGENTS.md)
2. Ensure all tests pass
3. Run linting and formatting before committing
4. Write clear commit messages
5. Create descriptive pull requests

## Environment Variables

| Variable                      | Required | Default                        | Description                                                      |
| ----------------------------- | -------- | ------------------------------ | ---------------------------------------------------------------- |
| `VITE_APPWRITE_ENDPOINT`      | Yes      | `https://cloud.appwrite.io/v1` | Appwrite API endpoint                                            |
| `VITE_CONSOLE_PROFILE`        | No       | `cloud`                        | `cloud` or `self-hosted` – controls which features are available |
| `VITE_CONSOLE_PRE_LAUNCH`     | No       | off (unset)                    | Pre-launch lock (`/init` only). Set `true` to enable             |
| `VITE_STRIPE_PUBLISHABLE_KEY` | No       | -                              | Stripe publishable key for billing                               |
| `VITE_COMPANY_NAME`           | No       | `Appwrite`                     | Company name for branding                                        |
| `VITE_CONTACT_SALES_URL`      | No       | -                              | Contact sales page URL                                           |
| `VITE_LEGAL_EMAIL`            | No       | `legal@appwrite.io`            | Legal contact email                                              |

## License

[Add your license here]

## Support

For issues, questions, or contributions, please open an issue on GitHub or refer to the [Appwrite documentation](https://appwrite.io/docs).
