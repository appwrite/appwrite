import type { DocsSectionNavConfig } from './sections-types'

export const navPart2_0 =   {
    prefix: 'products/auth',
    parent: {
      href: '/docs',
      label: 'Auth',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/products/auth',
          },
          {
            label: 'Quick start',
            href: '/docs/products/auth/quick-start',
          },
        ],
      },
      {
        label: 'Concepts',
        items: [
          {
            label: 'Accounts',
            href: '/docs/products/auth/accounts',
          },
          {
            label: 'Users',
            href: '/docs/products/auth/users',
          },
          {
            label: 'Teams',
            href: '/docs/products/auth/teams',
          },
          {
            label: 'Impersonation',
            href: '/docs/products/auth/impersonation',
          },
          {
            label: 'Preferences',
            href: '/docs/products/auth/preferences',
          },
          {
            label: 'Labels',
            href: '/docs/products/auth/labels',
          },
          {
            label: 'Security',
            href: '/docs/products/auth/security',
          },
          {
            label: 'Email policies',
            href: '/docs/products/auth/email-policies',
          },
          {
            label: 'Message templates',
            href: '/docs/products/auth/message-templates',
          },
          {
            label: 'Tokens',
            href: '/docs/products/auth/tokens',
          },
          {
            label: 'Identities',
            href: '/docs/products/auth/identities',
          },
          {
            label: 'Presences',
            href: '/docs/products/auth/presences',
          },
          {
            label: 'OAuth2 server',
            href: '/docs/products/auth/oauth-server',
          },
        ],
      },
      {
        label: 'Guides',
        items: [
          {
            label: 'Email and password login',
            href: '/docs/products/auth/email-password',
          },
          {
            label: 'Phone (SMS) login',
            href: '/docs/products/auth/phone-sms',
          },
          {
            label: 'Magic URL login',
            href: '/docs/products/auth/magic-url',
          },
          {
            label: 'Email OTP login',
            href: '/docs/products/auth/email-otp',
          },
          {
            label: 'OAuth2 login',
            href: '/docs/products/auth/oauth2',
          },
          {
            label: 'Anonymous login',
            href: '/docs/products/auth/anonymous',
          },
          {
            label: 'JWT login',
            href: '/docs/products/auth/jwt',
          },
          {
            label: 'SSR login',
            href: '/docs/products/auth/server-side-rendering',
          },
          {
            label: 'React library',
            href: '/docs/products/auth/react',
          },
          {
            label: 'Custom token login',
            href: '/docs/products/auth/custom-token',
          },
          {
            label: 'Multi-factor authentication',
            href: '/docs/products/auth/mfa',
          },
          {
            label: 'Auth status check',
            href: '/docs/products/auth/checking-auth-status',
          },
          {
            label: 'User verification',
            href: '/docs/products/auth/verify-user',
          },
          {
            label: 'Team invites',
            href: '/docs/products/auth/team-invites',
          },
          {
            label: 'Multi-tenancy',
            href: '/docs/products/auth/multi-tenancy',
          },
        ],
      },
      {
        label: 'References',
        items: [
          {
            label: 'Account API',
            href: '/docs/references/cloud/client-web/account',
          },
          {
            label: 'Users API',
            href: '/docs/references/cloud/server-nodejs/users',
          },
          {
            label: 'Teams API',
            href: '/docs/references/cloud/client-web/teams',
          },
        ],
      },
    ],
  } satisfies DocsSectionNavConfig

export const navPart2_1 =   {
    prefix: 'products/auth/oauth-server',
    parent: {
      href: '/docs/products/auth',
      label: 'OAuth2 server',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/products/auth/oauth-server',
          },
          {
            label: 'Quick start',
            href: '/docs/products/auth/oauth-server/quick-start',
          },
        ],
      },
      {
        label: 'Concepts',
        items: [
          {
            label: 'Clients',
            href: '/docs/products/auth/oauth-server/clients',
          },
          {
            label: 'Authorization',
            href: '/docs/products/auth/oauth-server/authorization',
          },
          {
            label: 'Tokens',
            href: '/docs/products/auth/oauth-server/tokens',
          },
          {
            label: 'Scopes',
            href: '/docs/products/auth/oauth-server/scopes',
          },
          {
            label: 'Installations',
            href: '/docs/products/auth/oauth-server/installations',
          },
          {
            label: 'Device flow',
            href: '/docs/products/auth/oauth-server/device-flow',
          },
        ],
      },
      {
        label: 'Guides',
        items: [
          {
            label: 'Sign in with your product',
            href: '/docs/products/auth/oauth-server/sign-in-with-your-product/step-1',
          },
          {
            label: 'Custom scopes',
            href: '/docs/products/auth/oauth-server/custom-scopes/step-1',
          },
        ],
      },
    ],
  } satisfies DocsSectionNavConfig

export const navPart2_2 =   {
    prefix: 'products/auth/oauth-server/sign-in-with-your-product',
    parent: {
      href: '/docs/products/auth/oauth-server',
      label: 'Sign in with your product',
    },
    navigation: [
      {
        label: 'Steps',
        items: [
          {
            label: 'Introduction',
            href: '/docs/products/auth/oauth-server/sign-in-with-your-product/step-1',
          },
          {
            label: 'Enable the OAuth2 server',
            href: '/docs/products/auth/oauth-server/sign-in-with-your-product/step-2',
          },
          {
            label: 'Create the apps',
            href: '/docs/products/auth/oauth-server/sign-in-with-your-product/step-3',
          },
          {
            label: 'Add the sign-in button',
            href: '/docs/products/auth/oauth-server/sign-in-with-your-product/step-4',
          },
          {
            label: 'Build the consent screen',
            href: '/docs/products/auth/oauth-server/sign-in-with-your-product/step-5',
          },
          {
            label: 'Exchange the code for tokens',
            href: '/docs/products/auth/oauth-server/sign-in-with-your-product/step-6',
          },
          {
            label: 'Run the flow',
            href: '/docs/products/auth/oauth-server/sign-in-with-your-product/step-7',
          },
        ],
      },
    ],
  } satisfies DocsSectionNavConfig

export const navPart2_3 =   {
    prefix: 'products/auth/oauth-server/custom-scopes',
    parent: {
      href: '/docs/products/auth/oauth-server',
      label: 'Custom scopes',
    },
    navigation: [
      {
        label: 'Steps',
        items: [
          {
            label: 'Introduction',
            href: '/docs/products/auth/oauth-server/custom-scopes/step-1',
          },
          {
            label: 'Define the scopes',
            href: '/docs/products/auth/oauth-server/custom-scopes/step-2',
          },
          {
            label: 'Request the scopes',
            href: '/docs/products/auth/oauth-server/custom-scopes/step-3',
          },
          {
            label: 'Validate access tokens',
            href: '/docs/products/auth/oauth-server/custom-scopes/step-4',
          },
          {
            label: 'Protect the API route',
            href: '/docs/products/auth/oauth-server/custom-scopes/step-5',
          },
          {
            label: 'Call the API from Vantage',
            href: '/docs/products/auth/oauth-server/custom-scopes/step-6',
          },
          {
            label: 'Run the flow',
            href: '/docs/products/auth/oauth-server/custom-scopes/step-7',
          },
        ],
      },
    ],
  } satisfies DocsSectionNavConfig

export const navPart2_4 =   {
    prefix: 'products/avatars',
    parent: {
      href: '/docs',
      label: 'Avatars',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/products/avatars',
          },
          {
            label: 'Quick start',
            href: '/docs/products/avatars/quick-start',
          },
        ],
      },
      {
        label: 'Concepts',
        items: [
          {
            label: 'User initials',
            href: '/docs/products/avatars/initials',
          },
          {
            label: 'QR codes',
            href: '/docs/products/avatars/qr-codes',
          },
          {
            label: 'Country flags',
            href: '/docs/products/avatars/flags',
          },
          {
            label: 'Browser icons',
            href: '/docs/products/avatars/browsers',
          },
          {
            label: 'Payment methods',
            href: '/docs/products/avatars/payment-methods',
          },
          {
            label: 'Favicons',
            href: '/docs/products/avatars/favicons',
          },
          {
            label: 'Screenshots',
            href: '/docs/products/avatars/screenshots',
          },
          {
            label: 'Image proxy',
            href: '/docs/products/avatars/image-manipulation',
          },
        ],
      },
      {
        label: 'References',
        items: [
          {
            label: 'Avatars API',
            href: '/docs/references/cloud/client-web/avatars',
          },
        ],
      },
    ],
  } satisfies DocsSectionNavConfig

export const navPart2_5 =   {
    prefix: 'products/databases',
    parent: {
      href: '/docs',
      label: 'Databases',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/products/databases',
          },
        ],
      },
      {
        label: 'Appwrite databases',
        items: [
          {
            label: 'TablesDB',
            href: '/docs/products/databases/tablesdb',
          },
          {
            label: 'DocumentsDB',
            href: '/docs/products/databases/documentsdb',
          },
          {
            label: 'VectorsDB',
            href: '/docs/products/databases/vectorsdb',
          },
        ],
      },
      {
        label: 'Native databases',
        items: [
          {
            label: 'PostgreSQL',
            href: '/docs/products/databases/postgresql',
          },
          {
            label: 'MySQL',
            href: '/docs/products/databases/mysql',
          },
        ],
      },
    ],
  } satisfies DocsSectionNavConfig
