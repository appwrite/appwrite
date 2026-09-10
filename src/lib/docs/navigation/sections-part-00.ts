import type { DocsSectionNavConfig } from './sections-types'

export const navPart0_0 =   {
    prefix: 'advanced/billing',
    parent: {
      href: '/docs',
      label: 'Billing',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/advanced/billing',
          },
          {
            label: 'Manage billing',
            href: '/docs/advanced/billing/payments',
          },
        ],
      },
      {
        label: 'Plans',
        items: [
          {
            label: 'Free',
            href: '/docs/advanced/billing/free',
          },
          {
            label: 'Pro',
            href: '/docs/advanced/billing/pro',
          },
          {
            label: 'Enterprise',
            href: '/docs/advanced/billing/enterprise',
          },
          {
            label: 'Open source',
            href: '/docs/advanced/billing/oss',
          },
        ],
      },
      {
        label: 'Add ons',
        items: [
          {
            label: 'Compute',
            href: '/docs/advanced/billing/compute',
          },
          {
            label: 'Phone OTP',
            href: '/docs/advanced/billing/phone-otp',
          },
          {
            label: 'Image Transformations',
            href: '/docs/advanced/billing/image-transformations',
          },
          {
            label: 'Database Reads and Writes',
            href: '/docs/advanced/billing/database-reads-and-writes',
          },
          {
            label: 'Text Embeddings',
            href: '/docs/advanced/billing/embeddings',
          },
        ],
      },
      {
        label: 'SLAs',
        items: [
          {
            label: 'Support SLA',
            href: '/docs/advanced/billing/support-sla',
          },
          {
            label: 'Uptime SLA',
            href: '/docs/advanced/billing/uptime-sla',
          },
        ],
      },
      {
        label: 'Policies',
        items: [
          {
            label: 'Fair use',
            href: '/docs/advanced/billing/fair-use-policy',
          },
          {
            label: 'Abuse',
            href: '/docs/advanced/billing/abuse',
          },
          {
            label: 'Refund',
            href: '/docs/advanced/billing/refund-policy',
          },
        ],
      },
    ],
  } satisfies DocsSectionNavConfig

export const navPart0_1 =   {
    prefix: 'advanced/migrations',
    parent: {
      href: '/docs',
      label: 'Migrations',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/advanced/migrations',
          },
        ],
      },
      {
        label: 'Guides',
        items: [
          {
            label: 'From Firebase',
            href: '/docs/advanced/migrations/firebase',
          },
          {
            label: 'From Supabase',
            href: '/docs/advanced/migrations/supabase',
          },
          {
            label: 'From Nhost',
            href: '/docs/advanced/migrations/nhost',
          },
          {
            label: 'From Cloud',
            href: '/docs/advanced/migrations/cloud',
          },
          {
            label: 'From self-hosted',
            href: '/docs/advanced/migrations/self-hosted',
          },
        ],
      },
    ],
  } satisfies DocsSectionNavConfig

export const navPart0_2 =   {
    prefix: 'advanced/security',
    parent: {
      href: '/docs',
      label: 'Security',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/advanced/security',
          },
        ],
      },
      {
        label: 'Compliances',
        items: [
          {
            label: 'GDPR',
            href: '/docs/advanced/security/gdpr',
          },
          {
            label: 'PCI',
            href: '/docs/advanced/security/pci',
          },
          {
            label: 'SOC 2',
            href: '/docs/advanced/security/soc2',
          },
          {
            label: 'HIPAA',
            href: '/docs/advanced/security/hipaa',
          },
          {
            label: 'CCPA',
            href: '/docs/advanced/security/ccpa',
          },
        ],
      },
      {
        label: 'Measures',
        items: [
          {
            label: 'Authentication',
            href: '/docs/advanced/security/authentication',
          },
          {
            label: 'Encryption',
            href: '/docs/advanced/security/encryption',
          },
          {
            label: 'Multi-Factor authentication',
            href: '/docs/advanced/security/mfa',
          },
          {
            label: 'HTTPS',
            href: '/docs/advanced/security/https',
          },
          {
            label: 'TLS',
            href: '/docs/advanced/security/tls',
          },
          {
            label: 'Backups',
            href: '/docs/advanced/security/backups',
          },
          {
            label: 'Penetration tests',
            href: '/docs/advanced/security/penetration-tests',
          },
          {
            label: 'Audit logs',
            href: '/docs/advanced/security/audit-logs',
          },
          {
            label: 'Abuse protection',
            href: '/docs/advanced/security/abuse-protection',
          },
        ],
      },
      {
        label: 'Access control',
        items: [
          {
            label: 'Permissions',
            href: '/docs/advanced/security/permissions',
          },
          {
            label: 'Roles',
            href: '/docs/advanced/security/roles',
          },
          {
            label: 'Rate limits',
            href: '/docs/advanced/security/rate-limits',
          },
          {
            label: 'Dev keys',
            href: '/docs/advanced/security/dev-keys',
          },
        ],
      },
    ],
  } satisfies DocsSectionNavConfig

export const navPart0_3 =   {
    prefix: 'advanced/self-hosting',
    parent: {
      href: '/docs',
      label: 'Self-hosting',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/advanced/self-hosting',
          },
          {
            label: 'Installation',
            href: '/docs/advanced/self-hosting/installation',
          },
        ],
      },
      {
        label: 'Platform deployment',
        items: [
          {
            label: 'AWS',
            href: '/docs/advanced/self-hosting/platforms/aws',
          },
          {
            label: 'DigitalOcean',
            href: '/docs/advanced/self-hosting/platforms/digitalocean',
          },
          {
            label: 'Google Cloud',
            href: '/docs/advanced/self-hosting/platforms/google-cloud',
          },
          {
            label: 'Azure',
            href: '/docs/advanced/self-hosting/platforms/azure',
          },
          {
            label: 'Coolify',
            href: '/docs/advanced/self-hosting/platforms/coolify',
          },
          {
            label: 'Dokploy',
            href: '/docs/advanced/self-hosting/platforms/dokploy',
          },
        ],
      },
      {
        label: 'Configuration',
        items: [
          {
            label: 'Databases',
            href: '/docs/advanced/self-hosting/configuration/databases',
          },
          {
            label: 'Environment variables',
            href: '/docs/advanced/self-hosting/configuration/environment-variables',
          },
          {
            label: 'Topologies',
            href: '/docs/advanced/self-hosting/configuration/topologies',
          },
          {
            label: 'Email delivery',
            href: '/docs/advanced/self-hosting/configuration/email',
          },
          {
            label: 'SMS delivery',
            href: '/docs/advanced/self-hosting/configuration/sms',
          },
          {
            label: 'Functions',
            href: '/docs/advanced/self-hosting/configuration/functions',
          },
          {
            label: 'Sites',
            href: '/docs/advanced/self-hosting/configuration/sites',
          },
          {
            label: 'Storage',
            href: '/docs/advanced/self-hosting/configuration/storage',
          },
          {
            label: 'TLS certificates',
            href: '/docs/advanced/self-hosting/configuration/tls-certificates',
          },
          {
            label: 'Version control',
            href: '/docs/advanced/self-hosting/configuration/version-control',
          },
        ],
      },
      {
        label: 'Tooling',
        items: [
          {
            label: 'MCP server',
            href: '/docs/advanced/self-hosting/mcp',
          },
        ],
      },
      {
        label: 'Production',
        items: [
          {
            label: 'Preparation',
            href: '/docs/advanced/self-hosting/production',
          },
          {
            label: 'Security',
            href: '/docs/advanced/self-hosting/production/security',
          },
          {
            label: 'Scaling',
            href: '/docs/advanced/self-hosting/production/scaling',
          },
          {
            label: 'Rate limits',
            href: '/docs/advanced/self-hosting/production/rate-limits',
          },
          {
            label: 'Email delivery',
            href: '/docs/advanced/self-hosting/production/emails',
          },
          {
            label: 'Error monitoring',
            href: '/docs/advanced/self-hosting/production/errors',
          },
          {
            label: 'Backups',
            href: '/docs/advanced/self-hosting/production/backups',
          },
          {
            label: 'Updates and migrations',
            href: '/docs/advanced/self-hosting/production/updates',
          },
          {
            label: 'Debugging',
            href: '/docs/advanced/self-hosting/production/debugging',
          },
        ],
      },
    ],
  } satisfies DocsSectionNavConfig

export const navPart0_4 =   {
    prefix: 'apis',
    parent: {
      href: '/docs',
      label: 'APIs',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/apis',
          },
        ],
      },
      {
        label: 'Protocols',
        items: [
          {
            label: 'REST',
            href: '/docs/apis/rest',
          },
          {
            label: 'GraphQL',
            href: '/docs/apis/graphql',
          },
          {
            label: 'Realtime',
            href: '/docs/apis/realtime',
          },
        ],
      },
      {
        label: 'Concepts',
        items: [
          {
            label: 'Events',
            href: '/docs/apis/events',
          },
          {
            label: 'Webhooks',
            href: '/docs/apis/webhooks',
          },
          {
            label: 'Response codes',
            href: '/docs/apis/response-codes',
          },
        ],
      },
      {
        label: 'Policies',
        items: [
          {
            label: 'Release policy',
            href: '/docs/apis/release-policy',
          },
        ],
      },
    ],
  } satisfies DocsSectionNavConfig
