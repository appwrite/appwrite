import type { ProductFeatureContent } from '@/lib/products/features/types'

export const sitesProductFeatures: ProductFeatureContent[] = [
  {
    id: 'git-previews',
    title: 'Deploy from Git with preview URLs',
    description:
      'Connect a Git repository and ship on every push. Commits to your production branch build and auto-activate on your primary domain; other branches get preview links for org members to review before merge.',
    docsHref: '/docs/products/sites/deploy-from-git',
    docsLabel: 'Deploy from Git docs',
  },
  {
    id: 'deploy-methods',
    title: 'Deploy the way your team works',
    description:
      'Use Git for automatic builds on push, the Appwrite CLI in CI, or a manual tarball upload from the Console. Every path runs through the same build pipeline, logs, domains, and rollbacks.',
    docsHref: '/docs/products/sites/deployments',
    docsLabel: 'Deployments docs',
    layout: 'stacked',
    centered: true,
    hideVisual: true,
    wideCompanion: true,
  },
  {
    id: 'rendering',
    title: 'Static and SSR hosting',
    description:
      'Host static sites, SPAs, and PWAs alongside server-rendered apps. Choose the rendering mode that fits your framework, from Vite and Astro to Next.js 16, Nuxt, SvelteKit, and TanStack Start.',
    docsHref: '/docs/products/sites/rendering',
    docsLabel: 'Rendering docs',
  },
  {
    id: 'builds',
    title: 'Build and deploy faster',
    description:
      'Spend less time waiting on builds and more time shipping updates. Cached dependencies speed up repeat deploys, path filters help monorepos skip unnecessary rebuilds, and deployment retention automatically removes old inactive deployments to save storage. Tune build and runtime CPU and memory when compilation or SSR needs more headroom.',
    docsHref: '/docs/products/sites/deploy-from-git',
    docsLabel: 'Deploy from Git docs',
  },
  {
    id: 'rollbacks',
    title: 'Instant rollbacks',
    description:
      'Switch the active deployment with zero downtime and no rebuild. Pick any previous ready deployment from Overview in the Console and promote it in one click when you need to recover fast.',
    docsHref: '/docs/products/sites/instant-rollbacks',
    docsLabel: 'Instant rollbacks docs',
  },
  {
    id: 'network',
    title: 'Delivered on Appwrite Network',
    description:
      'Every site runs on Appwrite Network. Auth, Databases, and Storage stay in your project region while pages and assets reach users from the edge.',
    docsHref: '/docs/products/network',
    docsLabel: 'Appwrite Network docs',
    layout: 'stacked',
    centered: true,
    hideVisual: true,
    wideCompanion: true,
    hideDocsLink: true,
    flushBottom: true,
  },
  {
    id: 'domain-rules',
    title: 'Connect hostnames to your site',
    description:
      'Point production at your live deployment, give staging branches their own domain, or configure redirects. Every site also gets a generated .appwrite.network URL for instant sharing.',
    docsHref: '/docs/products/sites/domains',
    docsLabel: 'Domains docs',
  },
  {
    id: 'domains',
    title: 'Buy domains and manage DNS',
    description:
      'Purchase domains in Appwrite and manage records with Appwrite DNS from the Console. TLS is issued automatically when you connect a hostname.',
    docsHref: '/docs/products/network/dns',
    docsLabel: 'Appwrite DNS docs',
    layout: 'stacked',
    centered: true,
    hideVisual: true,
    wideCompanion: true,
    brandLight: 'teal',
  },
  {
    id: 'observability',
    title: 'Logs, traffic, and usage insights',
    description:
      'Track requests, bandwidth, builds, and compute over time with breakdowns by path, asset type, or region. Inspect individual requests with status, headers, and SSR console output in the same view.',
    docsHref: '/docs/products/sites/logs',
    docsLabel: 'Logs docs',
    layout: 'stacked',
  },
  {
    id: 'templates',
    title: 'Framework quick-starts and templates',
    description:
      'Start from official quick-starts or pick a template in the create wizard. Filter by framework and use case, connect GitHub, and deploy with build settings already tuned for Appwrite.',
    docsHref: '/docs/products/sites/templates',
    docsLabel: 'Templates docs',
    layout: 'stacked',
    dottedBackground: true,
  },
]
