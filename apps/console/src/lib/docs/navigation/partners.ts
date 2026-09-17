import type { DocsNavTree } from '../types'

export const DOCS_PARTNERS_GLOBAL_NAV: DocsNavTree = [
  {
    items: [
      { label: 'Overview', href: '/docs/partners', icon: 'home' },
      {
        label: 'Quick start',
        href: '/docs/partners/quick-start',
        icon: 'play',
      },
      {
        label: 'Architecture',
        href: '/docs/partners/architecture',
        icon: 'platform',
      },
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
        label: 'Partners keys',
        href: '/docs/partners/org-api-keys',
        icon: 'key',
        new: true,
      },
    ],
  },
  {
    label: 'Guides',
    items: [
      {
        label: "Manage a customer's project",
        href: '/docs/partners/guides/oauth-connect',
        icon: 'book-open',
        new: true,
      },
      {
        label: 'Give every customer a backend',
        href: '/docs/partners/guides/white-label',
        icon: 'book-open',
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
        href: '/docs/partners/project',
        icon: 'boxes',
        isParent: true,
      },
      {
        label: 'Proxy',
        href: '/docs/partners/proxy',
        icon: 'arrow-start-right',
      },
      {
        label: 'Apps',
        href: '/docs/partners/apps',
        icon: 'layout-grid',
      },
    ],
  },
]
