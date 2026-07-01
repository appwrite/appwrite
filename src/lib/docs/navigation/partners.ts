import type { DocsNavTree } from '../types'

export const DOCS_PARTNERS_GLOBAL_NAV: DocsNavTree = [
  {
    items: [
      { label: 'Overview', href: '/docs/partners', icon: 'home' },
      { label: 'Quick start', href: '/docs/partners/quick-start', icon: 'play' },
      { label: 'Architecture', href: '/docs/partners/architecture', icon: 'platform' },
    ],
  },
  {
    label: 'Integration',
    items: [
      {
        label: 'OAuth connect',
        href: '/docs/partners/oauth-connect',
        icon: 'oauth',
        isParent: true,
        new: true,
      },
      {
        label: 'Org API keys',
        href: '/docs/partners/org-api-keys',
        icon: 'key',
        isParent: true,
        new: true,
      },
    ],
  },
  {
    label: 'Partners APIs',
    items: [
      {
        label: 'Organization',
        href: '/docs/partners/organizations',
        icon: 'building',
        isParent: true,
      },
      {
        label: 'Project',
        href: '/docs/partners/projects',
        icon: 'boxes',
        isParent: true,
      },
      {
        label: 'Domains',
        href: '/docs/partners/domains',
        icon: 'globe',
      },
      {
        label: 'Proxy',
        href: '/docs/partners/proxy',
        icon: 'arrow-start-right',
      },
      {
        label: 'Usage',
        href: '/docs/partners/usage',
        icon: 'bar-chart-2',
      },
      {
        label: 'Apps',
        href: '/docs/partners/apps',
        icon: 'layout-grid',
      },
    ],
  },
  {
    label: 'Guides',
    items: [
      {
        label: 'Provisioning',
        href: '/docs/partners/guides/provisioning',
        icon: 'document-text',
      },
      {
        label: 'Marketplaces',
        href: '/docs/partners/guides/marketplaces',
        icon: 'document-text',
      },
      {
        label: 'Multi-tenancy',
        href: '/docs/partners/guides/multi-tenancy',
        icon: 'document-text',
      },
    ],
  },
]
