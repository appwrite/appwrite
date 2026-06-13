import {
  CalendarClock,
  Code2,
  Globe,
  Server,
  Timer,
  Webhook,
  Zap,
} from 'lucide-react'
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
      { value: '15', label: 'Language runtimes' },
      { value: 'Cron', label: 'Schedule triggers' },
      { value: 'Events', label: 'Platform event hooks' },
      { value: 'Git', label: 'Repository deploys' },
    ],
  },
  capabilities: {
    title: 'Compute that reacts to your product',
    description:
      'Functions fit between your client apps and Appwrite services for custom logic, integrations, and automation.',
    items: [
      {
        title: 'HTTP endpoints',
        description:
          'Expose REST-style endpoints with custom domains and automatic TLS on Cloud.',
        icon: Globe,
      },
      {
        title: 'Event triggers',
        description:
          'Respond to database, storage, auth, and messaging events without polling.',
        icon: Webhook,
      },
      {
        title: 'Scheduled jobs',
        description:
          'Run cron-style workloads for reports, cleanup, and recurring business tasks.',
        icon: CalendarClock,
      },
      {
        title: 'Isolated runtimes',
        description:
          'Execute code in sandboxed environments with configurable memory and CPU limits.',
        icon: Server,
      },
      {
        title: 'Environment variables',
        description:
          'Store secrets and configuration per function with console-managed variables.',
        icon: Code2,
      },
      {
        title: 'Templates',
        description:
          'Start from curated templates for common integrations and AI workflows.',
        icon: Zap,
      },
    ],
  },
  visual: {
    title: 'Deploy once, run everywhere',
    description:
      'Manage deployments, logs, and domains from the console while Functions scale automatically with traffic.',
  },
  uniqueSections: [
    {
      type: 'feature-grid',
      title: 'Triggers and runtimes',
      description:
        'Connect Functions to the events and languages your stack already uses.',
      items: [
        {
          title: 'Database events',
          description: 'Run logic when rows are created, updated, or deleted.',
          icon: Webhook,
        },
        {
          title: 'Storage events',
          description: 'Process uploads, generate previews, or scan new files.',
          icon: Server,
        },
        {
          title: 'HTTP and timers',
          description: 'Build APIs and scheduled jobs in the same deployment model.',
          icon: Timer,
        },
        {
          title: 'Node, Python, and more',
          description: 'Choose runtimes that match your team and dependency needs.',
          icon: Code2,
        },
      ],
      columns: 2,
    },
    {
      type: 'steps',
      title: 'Deploy workflow',
      description:
        'Ship Functions from the CLI, Git, or the console with the same execution environment.',
      items: [
        {
          title: 'Connect your code',
          description:
            'Link a Git repository or upload a deployment from the CLI or console.',
        },
        {
          title: 'Configure triggers',
          description:
            'Set HTTP routes, cron schedules, or event subscriptions for the function.',
        },
        {
          title: 'Monitor executions',
          description:
            'Inspect logs, metrics, and failures from the project console.',
        },
      ],
      muted: true,
    },
  ],
  integrations: {
    title: 'Works with the Appwrite platform',
    description:
      'Functions orchestrate data and messaging across the rest of your Appwrite stack.',
    items: [
      {
        productId: 'databases',
        title: 'Data workflows',
        description: 'Validate, enrich, or sync rows when database events fire.',
      },
      {
        productId: 'storage',
        title: 'Media processing',
        description: 'Transform uploads and generate derivatives after files arrive.',
      },
      {
        productId: 'messaging',
        title: 'Notifications',
        description: 'Send email, SMS, or push messages from event-driven handlers.',
      },
      {
        productId: 'auth',
        title: 'Secure APIs',
        description: 'Verify JWTs and enforce access before executing custom logic.',
      },
    ],
  },
  faq: [
    {
      question: 'Which languages do Functions support?',
      answer:
        'Appwrite supports Node.js, Python, Dart, PHP, Ruby, and other runtimes. See the docs for the full list and version matrix.',
    },
    {
      question: 'Can Functions respond to HTTP requests?',
      answer:
        'Yes. Deploy Functions as HTTP endpoints with optional custom domains on Appwrite Cloud.',
    },
    {
      question: 'How do I deploy from Git?',
      answer:
        'Connect a repository in the console, configure build settings, and deploy on push like Sites.',
    },
    {
      question: 'Are Functions included in self-hosted Appwrite?',
      answer:
        'Yes. Self-hosted projects can deploy and execute Functions with the same APIs as Cloud.',
    },
  ],
  cta: {
    title: 'Start building with Functions',
    description: 'Deploy your first function from a template or your own codebase in minutes.',
  },
}
