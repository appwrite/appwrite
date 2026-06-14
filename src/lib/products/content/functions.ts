import type { ProductPageContent } from '@/lib/products/types'

export const functionsProductContent: ProductPageContent = {
  id: 'functions',
  metaDescription:
    'Deploy serverless Functions with isolated runtimes, schedules, and event triggers. Build backends without managing servers.',
  hero: {
    title: 'Serverless Functions for every backend job',
    description:
      'Run API endpoints, webhooks, cron jobs, and event handlers in secure isolated runtimes that scale with demand.',
    stats: [
      { value: '13+', label: 'Language runtimes' },
      { value: 'CLI', label: 'Local-first development' },
      { value: 'Cron', label: 'Schedule triggers' },
      { value: 'Events', label: 'Platform event hooks' },
      { value: 'Git', label: 'Repository deploys' },
    ],
  },
  faq: [
    {
      question: 'Which languages do Functions support?',
      answer:
        'Appwrite supports 13+ runtimes including Node.js, Bun, Python, Go, Dart, PHP, Ruby, Rust, and Deno. Each runtime has multiple version tags so you can pin the environment that matches production.',
      links: [{ label: 'Runtimes', href: '/docs/products/functions/runtimes' }],
    },
    {
      question: 'What is the difference between sync and async execution?',
      answer:
        'Sync executions run over HTTP domains or the SDK with async set to false. Appwrite waits for your function and returns the response, with a 30 second hard limit. Async executions are queued for events, cron schedules, and SDK calls with async set to true. They run in the background and use your function timeout, up to 15 minutes.',
      links: [{ label: 'Execution modes', href: '/docs/products/functions/execute#execution-modes' }],
    },
    {
      question: 'Can Functions respond to HTTP requests?',
      answer:
        'Yes. Every function gets a generated domain and you can add custom domains on Appwrite Cloud. Pass x-appwrite-user-jwt to authenticate users and respect Auth permissions inside your function.',
      links: [
        { label: 'Function domains', href: '/docs/products/functions/domains' },
        { label: 'Execute functions', href: '/docs/products/functions/execute' },
      ],
    },
    {
      question: 'How do I debug function executions?',
      answer:
        'Open the Executions tab in the Console to review status, trigger, method, path, and duration for each run. Execution details include logs, errors, and headers. Request and response bodies are not logged by default for privacy. Use log() and error() in your handler for the output you want to retain.',
      links: [{ label: 'Executions', href: '/docs/products/functions/executions' }],
    },
    {
      question: 'How do I deploy from Git?',
      answer:
        'Connect a repository in the Console, set a production branch and root directory, then deploy on every push. Branch and path filters use glob patterns, matching the Sites Git workflow.',
      links: [{ label: 'Deploy from Git', href: '/docs/products/functions/deploy-from-git' }],
    },
    {
      question: 'How does the build cache work?',
      answer:
        'Appwrite caches your package manager store between deployments, keyed automatically per function. pnpm, bun, npm, and yarn installs are faster on the next build with no extra configuration. If a cache restore fails, the build continues normally.',
      links: [{ label: 'Deployments', href: '/docs/products/functions/deployments' }],
    },
    {
      question: 'Can I develop Functions locally?',
      answer:
        'Yes. The Appwrite CLI runs your function in Docker on localhost with hot reload, the same runtime image as production, and optional user impersonation for Auth-aware testing.',
      links: [{ label: 'Develop locally', href: '/docs/products/functions/develop-locally' }],
    },
    {
      question: 'Can Functions access other Appwrite services?',
      answer:
        'Yes. Functions receive a dynamic API key and run with project context. Configure scopes in Settings, then call Databases, Storage, Messaging, Auth, and other APIs from server SDKs inside your handler.',
      links: [{ label: 'Develop functions', href: '/docs/products/functions/develop' }],
    },
  ],
  cta: {
    title: 'Start building with Functions',
    description: 'Deploy your first function from a template or your own codebase in minutes.',
  },
}
