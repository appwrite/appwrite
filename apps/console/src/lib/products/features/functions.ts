import type { ProductFeatureContent } from '@/lib/products/features/types'

export const functionsProductFeatures: ProductFeatureContent[] = [
  {
    id: 'git',
    title: 'Deploy from Git with auto-build on push',
    description:
      'Connect a repository and deploy on every push, the same workflow as Sites. Set a production branch that auto-activates successful builds, filter by branch or path with glob patterns, and review preview deployments from pull requests.',
    docsHref: '/docs/products/functions/deploy-from-git',
    docsLabel: 'Deploy from Git docs',
  },
  {
    id: 'builds',
    title: 'Fast builds with built-in dependency cache',
    description:
      "Every deployment runs through Appwrite's build pipeline with logs you can inspect in the Console. Package manager stores for npm, pnpm, yarn, and bun are cached automatically between builds, so repeat installs skip duplicate work. Tune build and runtime CPU and memory separately when compilation needs more headroom than steady execution.",
    docsHref: '/docs/products/functions/deployments',
    docsLabel: 'Deployments docs',
  },
  {
    id: 'http',
    title: 'HTTP endpoints via domains',
    description:
      'Every function gets a generated URL and optional custom domain for sync HTTP APIs and webhooks. Pass user sessions with the x-appwrite-user-jwt header so your function respects Auth permissions inside Server SDKs.',
    docsHref: '/docs/products/functions/domains',
    docsLabel: 'Function domains docs',
  },
  {
    id: 'triggers',
    title: 'Event triggers and scheduled executions',
    description:
      'Run functions asynchronously on platform events or on a cron schedule. Sync HTTP calls and SDK executions with async disabled return responses immediately but cap at 30 seconds. Events, cron jobs, and queued executions run in the background with your configured timeout, up to 15 minutes.',
    docsHref: '/docs/products/functions/execute',
    docsLabel: 'Execute functions docs',
    layout: 'stacked',
  },
  {
    id: 'executions',
    title: 'Executions and observability',
    description:
      'Every run creates an execution you can inspect in the Console. Review status, trigger, method, path, and duration in the executions table, then open details for logs, errors, and headers. Request and response bodies are not stored by default. Use log() and error() for the audit trail you need.',
    docsHref: '/docs/products/functions/executions',
    docsLabel: 'Executions docs',
    layout: 'stacked',
  },
  {
    id: 'runtimes',
    title: '13+ runtimes, your stack',
    description:
      'Code in Node, Bun, Python, Go, Rust, Dart, and more. Pin the version you ship with and deploy without relearning the platform.',
    docsHref: '/docs/products/functions/runtimes',
    docsLabel: 'Runtimes docs',
  },
  {
    id: 'local',
    title: 'Develop and run functions locally',
    description:
      'Use the Appwrite CLI and Docker to run functions on localhost with hot reload. Test with production-style headers, impersonate users, and deploy when you are ready.',
    docsHref: '/docs/products/functions/develop-locally',
    docsLabel: 'Local development docs',
  },
  {
    id: 'templates',
    title: 'Function templates catalog',
    description:
      'Start from the Console Templates tab with pre-built integrations for Stripe payments, OpenAI prompts, search sync, Discord bots, and more. Filter by use case or runtime and skip boilerplate when wiring new backend jobs.',
    docsHref: '/docs/products/functions/templates',
    docsLabel: 'Templates docs',
  },
]
