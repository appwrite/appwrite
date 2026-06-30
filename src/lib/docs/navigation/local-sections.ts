import type { DocsSectionNavConfig } from './sections'

/**
 * Docs section navigation maintained in vibes (not imported from the website repo).
 * Survives `bun run import:docs` without being overwritten.
 */
export const DOCS_LOCAL_SECTION_NAVS: DocsSectionNavConfig[] = [
  {
    prefix: 'partners/oauth-connect',
    parent: {
      href: '/docs/partners',
      label: 'OAuth connect',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/partners/oauth-connect',
          },
          {
            label: 'Setup',
            href: '/docs/partners/oauth-connect/setup',
          },
          {
            label: 'Scopes',
            href: '/docs/partners/oauth-connect/scopes',
          },
        ],
      },
    ],
  },
  {
    prefix: 'partners/org-api-keys',
    parent: {
      href: '/docs/partners',
      label: 'Org API keys',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/partners/org-api-keys',
          },
          {
            label: 'Scopes',
            href: '/docs/partners/org-api-keys/scopes',
          },
        ],
      },
    ],
  },
  {
    prefix: 'partners/organizations',
    parent: {
      href: '/docs/partners',
      label: 'Organization',
    },
    navigation: [
      {
        label: 'Partners API',
        items: [
          {
            label: 'Overview',
            href: '/docs/partners/organizations',
          },
          {
            label: 'Manage organizations',
            href: '/docs/partners/organizations/manage',
          },
          {
            label: 'Members and roles',
            href: '/docs/partners/organizations/members',
          },
        ],
      },
    ],
  },
  {
    prefix: 'partners/projects',
    parent: {
      href: '/docs/partners',
      label: 'Project',
    },
    navigation: [
      {
        label: 'Partners API',
        items: [
          {
            label: 'Overview',
            href: '/docs/partners/projects',
          },
          {
            label: 'Create projects',
            href: '/docs/partners/projects/create',
          },
          {
            label: 'Manage resources',
            href: '/docs/partners/projects/resources',
          },
        ],
      },
    ],
  },
  {
    prefix: 'products/domains',
    parent: {
      href: '/docs',
      label: 'Domains',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/products/domains',
          },
          {
            label: 'Quick start',
            href: '/docs/products/domains/quick-start',
          },
        ],
      },
      {
        label: 'Concepts',
        items: [
          {
            label: 'Registration',
            href: '/docs/products/domains/registration',
          },
          {
            label: 'Renewal',
            href: '/docs/products/domains/renewal',
          },
          {
            label: 'DNS records',
            href: '/docs/products/domains/dns',
          },
          {
            label: 'DNS presets',
            href: '/docs/products/domains/presets',
          },
          {
            label: 'Pricing',
            href: '/docs/products/domains/pricing',
          },
        ],
      },
      {
        label: 'Guides',
        items: [
          {
            label: 'Register a domain',
            href: '/docs/products/domains/register',
          },
          {
            label: 'Transfer a domain',
            href: '/docs/products/domains/transfer',
          },
          {
            label: 'Add external domain',
            href: '/docs/products/domains/external',
          },
          {
            label: 'Manage DNS records',
            href: '/docs/products/domains/manage-dns',
          },
          {
            label: 'Connect to products',
            href: '/docs/products/domains/connect',
          },
          {
            label: 'Change organization',
            href: '/docs/products/domains/change-organization',
          },
          {
            label: 'Delete a domain',
            href: '/docs/products/domains/delete',
          },
        ],
      },
    ],
  },
]
