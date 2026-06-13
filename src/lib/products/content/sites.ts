import {
  GitBranch,
  Globe,
  Layers,
  Monitor,
  Rocket,
  Server,
} from 'lucide-react'
import type { ProductPageContent } from '@/lib/products/types'

export const sitesProductContent: ProductPageContent = {
  id: 'sites',
  metaDescription:
    'Deploy static, SSR, and CSR web apps with Appwrite Sites. Git-based deploys, previews, custom domains, and Appwrite backends.',
  hero: {
    title: 'Deploy web apps from Git in minutes',
    description:
      'Ship static, SSR, and CSR frontends with automatic builds, preview URLs, and Appwrite services connected behind the scenes.',
    stats: [
      { value: '14', label: 'Framework presets' },
      { value: 'Git', label: 'Push to deploy' },
      { value: 'Preview', label: 'Deployment URLs' },
      { value: '3', label: 'Rendering modes' },
    ],
  },
  capabilities: {
    title: 'Frontend hosting on the same platform',
    description:
      'Sites keeps your UI and backend in one project so you ship full-stack products without juggling separate hosts.',
    items: [
      {
        title: 'Git-based deploys',
        description:
          'Connect GitHub or GitLab and deploy on every push to your production branch.',
        icon: GitBranch,
      },
      {
        title: 'Framework support',
        description:
          'Deploy React, Next.js, Nuxt, SvelteKit, Astro, and other popular frameworks.',
        icon: Layers,
      },
      {
        title: 'Instant previews',
        description:
          'Review pull requests on unique preview URLs before promoting to production.',
        icon: Monitor,
      },
      {
        title: 'Custom domains',
        description:
          'Attach your domain with automatic TLS certificates on Appwrite Cloud.',
        icon: Globe,
      },
      {
        title: 'Environment variables',
        description:
          'Configure build and runtime variables per site without committing secrets.',
        icon: Server,
      },
      {
        title: 'Rollback ready',
        description:
          'Promote previous deployments when you need to recover quickly.',
        icon: Rocket,
      },
    ],
  },
  visual: {
    title: 'From commit to production',
    description:
      'Watch builds progress, open previews, and promote deployments without leaving the Appwrite console.',
  },
  uniqueSections: [
    {
      type: 'compare',
      title: 'Rendering modes for every frontend',
      description:
        'Deploy the architecture your framework expects, whether that is static export, server rendering, or client-only apps.',
      items: [
        {
          title: 'Static sites',
          description: 'Fast global delivery for marketing pages and SPAs exported as static assets.',
          icon: Globe,
          bullets: [
            'Ideal for docs, landing pages, and SPAs',
            'Simple build and deploy pipeline',
            'Low operational overhead',
          ],
        },
        {
          title: 'Server-side rendering',
          description: 'Run SSR frameworks with server compute tied to each deployment.',
          icon: Server,
          bullets: [
            'Supports Next.js, Nuxt, and similar frameworks',
            'Dynamic routes and server data fetching',
            'Connected to Appwrite backends in the same project',
          ],
        },
        {
          title: 'Client-side rendering',
          description: 'Ship interactive apps that talk to Appwrite APIs from the browser.',
          icon: Monitor,
          bullets: [
            'Works with React, Vue, and other CSR stacks',
            'Use Auth, Databases, and Storage from the client SDK',
            'Preview URLs for every deployment',
          ],
        },
      ],
    },
    {
      type: 'steps',
      title: 'Deploy workflow',
      description:
        'Connect a repository once, then treat Git pushes as your release pipeline.',
      items: [
        {
          title: 'Connect Git',
          description:
            'Link a repository and choose the production branch and root directory.',
        },
        {
          title: 'Configure build',
          description:
            'Set install and build commands, output directory, and environment variables.',
        },
        {
          title: 'Ship and preview',
          description:
            'Get production and preview URLs with Appwrite services available in the same project.',
        },
      ],
      muted: true,
    },
  ],
  integrations: {
    title: 'Works with the Appwrite platform',
    description:
      'Sites is the frontend layer for the rest of your Appwrite backend services.',
    items: [
      {
        productId: 'auth',
        title: 'Signed-in experiences',
        description: 'Use Auth sessions in SSR and CSR apps deployed with Sites.',
      },
      {
        productId: 'databases',
        title: 'Full-stack data',
        description: 'Read and write app data from your deployed frontend in the same project.',
      },
      {
        productId: 'functions',
        title: 'Custom APIs',
        description: 'Call Functions from your site for logic that should not run in the browser.',
      },
      {
        productId: 'storage',
        title: 'Media delivery',
        description: 'Serve user uploads and static assets from Storage-backed URLs.',
      },
    ],
  },
  faq: [
    {
      question: 'Which frameworks does Sites support?',
      answer:
        'Sites supports popular frameworks including React, Next.js, Nuxt, SvelteKit, Astro, and more. See the docs for build settings per framework.',
    },
    {
      question: 'Can I use custom domains?',
      answer:
        'Yes. Attach custom domains on Appwrite Cloud with automatic TLS for production deployments.',
    },
    {
      question: 'Do preview deployments get their own URLs?',
      answer:
        'Yes. Each deployment can expose a preview URL so you can review changes before promoting to production.',
    },
    {
      question: 'Does Sites replace a separate hosting provider?',
      answer:
        'For many teams, Sites replaces a separate frontend host because it lives in the same project as Appwrite backend services.',
    },
  ],
  cta: {
    title: 'Start building with Sites',
    description: 'Connect a repository and deploy your first frontend alongside your Appwrite backend.',
  },
}
