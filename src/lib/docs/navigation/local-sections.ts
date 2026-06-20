import type { DocsSectionNavConfig } from './sections'

/**
 * Docs section navigation maintained in vibes (not imported from the website repo).
 * Survives `bun run import:docs` without being overwritten.
 */
export const DOCS_LOCAL_SECTION_NAVS: DocsSectionNavConfig[] = [
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
        label: 'Journeys',
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
