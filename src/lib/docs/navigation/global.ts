import type { DocsNavTree } from '../types'

export const DOCS_GLOBAL_NAV: DocsNavTree = [
  {
    items: [
      { label: 'Home', href: '/docs', icon: 'home' },
      { label: 'Quick start', href: '/docs/quick-starts', icon: 'play' },
      { label: 'Tutorials', href: '/docs/tutorials', icon: 'book-open' },
      { label: 'SDKs', href: '/docs/sdks', icon: 'cog' },
      {
        label: 'Blog',
        href: '/blog',
        icon: 'document-text',
        openInNewTab: true,
      },
      {
        label: 'Changelog',
        href: '/changelog',
        icon: 'clock',
        openInNewTab: true,
      },
      {
        label: 'Integrations',
        href: '/integrations',
        icon: 'puzzle',
        openInNewTab: true,
      },
      {
        label: 'API references',
        href: '/docs/references',
        icon: 'code',
        isParent: true,
      },
    ],
  },
  {
    label: 'Products',
    items: [
      {
        label: 'Auth',
        href: '/docs/products/auth',
        icon: 'user-group',
        isParent: true,
      },
      {
        label: 'Databases',
        href: '/docs/products/databases',
        icon: 'database',
        isParent: true,
      },
      {
        label: 'Storage',
        href: '/docs/products/storage',
        icon: 'folder',
        isParent: true,
      },
      {
        label: 'Functions',
        href: '/docs/products/functions',
        icon: 'zap',
        isParent: true,
      },
      {
        label: 'Messaging',
        href: '/docs/products/messaging',
        icon: 'send',
        isParent: true,
      },
      {
        label: 'Sites',
        href: '/docs/products/sites',
        icon: 'globe',
        isParent: true,
      },
      {
        label: 'Domains',
        href: '/docs/products/domains',
        icon: 'link',
        isParent: true,
      },
      {
        label: 'Firewall',
        href: '/docs/products/firewall',
        icon: 'shield',
        isParent: true,
      },
      {
        label: 'Agent',
        href: '/docs/products/agent',
        icon: 'bot',
        isParent: true,
      },
    ],
  },
  {
    label: 'Utilities',
    collapsible: true,
    initiallyCollapsed: true,
    items: [
      {
        label: 'Avatars',
        href: '/docs/products/avatars',
        icon: 'user-circle',
        isParent: true,
      },
    ],
  },
  {
    label: 'APIS',
    items: [
      { label: 'Overview', href: '/docs/apis', icon: 'layout-grid' },
      {
        label: 'Realtime',
        href: '/docs/apis/realtime',
        icon: 'radio',
        isParent: true,
      },
      { label: 'REST', href: '/docs/apis/rest', icon: 'rest' },
      { label: 'GraphQL', href: '/docs/apis/graphql', icon: 'graphql' },
    ],
  },
  {
    label: 'Tooling',
    items: [
      {
        label: 'AI',
        href: '/docs/tooling/ai',
        icon: 'sparkles',
        isParent: true,
      },
      {
        label: 'CLI',
        href: '/docs/tooling/command-line/installation',
        icon: 'terminal',
        isParent: true,
      },
      {
        label: 'Terraform',
        href: '/docs/tooling/terraform',
        icon: 'terraform',
        isParent: true,
      },
      {
        label: 'Command Center',
        href: '/docs/tooling/command-center',
        icon: 'command',
      },
      { label: 'The Appwriter', href: '/docs/tooling/appwriter', icon: 'text' },
    ],
  },
  {
    label: 'Advanced',
    items: [
      {
        label: 'Platform',
        href: '/docs/advanced/platform',
        icon: 'platform',
        isParent: true,
      },
      {
        label: 'Network',
        href: '/docs/products/network',
        icon: 'share',
        isParent: true,
      },
      {
        label: 'Migrations',
        href: '/docs/advanced/migrations',
        icon: 'refresh',
        isParent: true,
      },
      {
        label: 'Security',
        href: '/docs/advanced/security',
        icon: 'shield',
        isParent: true,
      },
      {
        label: 'Self-hosting',
        href: '/docs/advanced/self-hosting',
        icon: 'server',
        isParent: true,
      },
    ],
  },
]
