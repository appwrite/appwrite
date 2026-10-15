import type { ProductFeatureContent } from '@/lib/products/features/types'

export const authProductFeatures: ProductFeatureContent[] = [
  {
    id: 'oauth',
    title: 'OAuth 2 and social login',
    description:
      'Enable 30+ social providers from the Console Social providers tab. Users sign up in one click with GitHub, Google, Apple, and the identity providers your audience already uses.',
    docsHref: '/docs/products/auth/oauth2',
    docsLabel: 'OAuth2 docs',
  },
  {
    id: 'passwordless',
    title: 'Passwordless login',
    description:
      'Turn on Magic URL, Email OTP, and Phone SMS from Auth settings. Ship secure sign-in without storing or resetting passwords.',
    docsHref: '/docs/products/auth/magic-url',
    docsLabel: 'Magic URL docs',
  },
  {
    id: 'teams',
    title: 'Multi-tenancy with teams and roles',
    description:
      'Model each customer or workspace as a team with memberships, invites, and roles. Scope databases, storage, and other resources to the right tenant from the Console Users and Teams tabs, without building custom RBAC.',
    docsHref: '/docs/products/auth/multi-tenancy',
    docsLabel: 'Multi-tenancy docs',
  },
  {
    id: 'ssr',
    title: 'SSR authentication',
    description:
      'Verify sessions from Next.js, Nuxt, SvelteKit, and other server-rendered apps. Issue session cookies from your backend with dedicated guides and tutorials.',
    docsHref: '/docs/products/auth/server-side-rendering',
    docsLabel: 'SSR docs',
  },
  {
    id: 'security',
    title: 'Security policies you control',
    description:
      'Fine-tune Auth from the Console Policies and Settings tabs. Set session length and limits, password strength and history, email signup rules, membership privacy, and which auth methods are enabled for your project.',
    docsHref: '/docs/products/auth/security',
    docsLabel: 'Security docs',
    layout: 'stacked',
  },
  {
    id: 'mfa',
    title: 'Multi-factor authentication',
    description:
      'Add TOTP authenticator apps and recovery codes for sensitive accounts. Require MFA when users update credentials or access protected actions.',
    docsHref: '/docs/products/auth/mfa',
    docsLabel: 'MFA docs',
  },
  {
    id: 'presences',
    title: 'Presence for team collaboration',
    description:
      'Show who is online, on the same page, or typing in team chat. Presences sync status and metadata over Realtime so you can add collaboration cues to shared docs, dashboards, and support tools without building sockets.',
    docsHref: '/docs/products/auth/presences',
    docsLabel: 'Presences docs',
  },
]
