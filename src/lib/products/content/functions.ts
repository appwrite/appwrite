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
      question: 'Can Functions access other Appwrite services?',
      answer:
        'Yes. Functions run with project context and can call Databases, Storage, Messaging, and other APIs using server SDKs.',
    },
  ],
  cta: {
    title: 'Start building with Functions',
    description: 'Deploy your first function from a template or your own codebase in minutes.',
  },
}
