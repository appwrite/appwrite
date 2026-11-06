import type { AlternativeContent } from '@/lib/alternatives/types'

export const auth0AlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'Plans',
      rows: [
        { label: 'Free monthly active users', appwrite: '75,000', competitor: '25,000' },
        {
          label: 'First paid plan',
          appwrite: { value: 'From $25/mo', note: 'Includes 200,000 monthly active users' },
          competitor: { value: '$35/mo', note: 'B2C Essentials, 500 monthly active users' },
        },
        {
          label: 'Cost at 10,000 monthly active users',
          appwrite: { value: '$25/mo', note: 'Pro, or $0 on Free' },
          competitor: { value: 'About $700/mo', note: 'B2C Essentials list price' },
        },
        {
          label: 'Additional users',
          appwrite: '$3 per 1,000',
          competitor: { value: 'About $70 per 1,000', note: 'B2C Essentials, custom quote past 50,000' },
        },
        {
          label: 'Open source and self-hostable',
          appwrite: { value: true, note: 'Keep identity on your own servers' },
          competitor: { value: false, note: 'Private Cloud is operated by Okta' },
        },
      ],
    },
    {
      title: 'Sign-in',
      rows: [
        { label: 'Email and password', appwrite: true, competitor: true },
        { label: 'Social sign-in', appwrite: { value: true, note: '40+ OAuth providers' }, competitor: true },
        { label: 'Magic URL, email OTP, and SMS', appwrite: true, competitor: true },
        {
          label: 'Passkeys',
          appwrite: { value: 'partial', note: 'Through custom token login' },
          competitor: true,
        },
        {
          label: 'Enterprise SAML connections',
          appwrite: { value: 'partial', note: 'OIDC and providers like Okta, no native SAML' },
          competitor: true,
        },
      ],
    },
    {
      title: 'Security',
      rows: [
        { label: 'Multi-factor authentication', appwrite: 'TOTP, email, SMS, recovery codes', competitor: true },
        {
          label: 'Breached password detection',
          appwrite: { value: true, note: 'Have I Been Pwned, on by default on Cloud' },
          competitor: true,
        },
        {
          label: 'Password history, dictionary, and personal data checks',
          appwrite: true,
          competitor: true,
        },
        {
          label: 'Export password hashes',
          appwrite: { value: true, note: 'From the Users API with an API key' },
          competitor: { value: 'partial', note: 'Support ticket, paid plans only' },
        },
      ],
    },
    {
      title: 'Beyond identity',
      rows: [
        {
          label: 'Teams and roles for multi-tenancy',
          appwrite: { value: true, note: 'Unlimited teams on Pro' },
          competitor: { value: true, note: 'Organizations, capped by plan' },
        },
        {
          label: 'Permissions across data, files, and functions',
          appwrite: true,
          competitor: { value: false, note: 'Identity only' },
        },
        { label: 'Presences (who is online)', appwrite: true, competitor: false },
        {
          label: 'Database, storage, functions, and hosting',
          appwrite: true,
          competitor: false,
        },
      ],
    },
  ],
  fairPlay: {
    title: 'When Auth0 might still fit',
    description:
      'Auth0 is a mature identity platform. It may justify the higher price if your requirements look like this.',
    points: [
      'You sell to enterprises that require SAML connections, SCIM provisioning, and self-service SSO setup.',
      'You need adaptive, risk-based MFA and advanced attack protection backed by an SLA.',
      'You want a hosted Universal Login page and Actions to customize every step of the flow.',
      'Fine-grained authorization across many services is a core requirement.',
    ],
  },
  related: [
    {
      kind: 'blog',
      title: 'Appwrite vs Auth0: Which is better for a B2C app?',
      description: 'What happens to your bill at 10K, 100K, and 1M users.',
      href: '/blog/post/appwrite-vs-auth0-b2c',
    },
    {
      kind: 'blog',
      title: 'Rethinking password security: say goodbye to plaintext passwords',
      description: 'Secure defaults that stop the most common auth leaks.',
      href: '/blog/post/goodbye-plaintext-passwords',
    },
    {
      kind: 'blog',
      title: 'How password hashing algorithms keep your data safe',
      description: 'Argon2, bcrypt, scrypt, and how imports are upgraded.',
      href: '/blog/post/password-hashing-algorithms',
    },
    {
      kind: 'product',
      title: 'Appwrite Auth',
      description: 'Email, OAuth, SMS, MFA, teams, and sessions.',
      href: '/products/auth',
    },
    {
      kind: 'docs',
      title: 'Auth security',
      description: 'Password policies, session limits, and breach checks.',
      href: '/docs/products/auth/security',
    },
    {
      kind: 'docs',
      title: 'Appwrite as an OAuth provider',
      description: 'Let other apps sign in with your product.',
      href: '/docs/products/auth/oauth-server',
    },
  ],
  faq: [
    {
      question: 'Is Appwrite better than Auth0?',
      answer:
        'For most consumer and SaaS apps, yes. Appwrite Auth covers the sign-in methods and security policies users expect, includes 3x more free monthly active users, costs a fraction of Auth0 as you grow, and is open source. It also comes with a full backend, so the same users and teams secure your data, files, and functions.',
    },
    {
      question: 'Is Appwrite cheaper than Auth0?',
      answer:
        'Yes, by a wide margin. At 10,000 monthly active users, Auth0 B2C Essentials lists at about $700/mo, while Appwrite Pro is $25/mo and includes 200,000 users. Past that, Appwrite charges $3 per 1,000 users, compared with about $70 per 1,000 on Auth0.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'What is the best open-source alternative to Auth0?',
      answer:
        'Appwrite is the best open-source alternative to Auth0. Appwrite Auth is fully open source, self-hosts with Docker so user data stays on your servers, and includes email, OAuth, magic URL, OTP, SMS, MFA, teams, and password policies, with the same APIs on Appwrite Cloud and your own servers.',
      links: [{ label: 'Appwrite Auth', href: '/products/auth' }],
    },
    {
      question: 'Is Appwrite Auth a good Auth0 alternative?',
      answer:
        'Yes, especially for consumer and SaaS apps. Appwrite Auth covers email and password, 40+ OAuth providers, magic URLs, email OTP, phone SMS, anonymous sessions, MFA, and teams. The Free plan includes 75,000 monthly active users and Pro includes 200,000, then $3 per 1,000.',
    },
    {
      question: 'What does Appwrite Auth cost at 300,000 monthly active users?',
      answer:
        'Pro starts at $25/mo and includes 200,000 monthly active users. The next 100,000 users cost $3 per 1,000, or $300 on top of the plan. There are no plan jumps or sales calls as you grow.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'Can I migrate users from Auth0 without forcing password resets?',
      answer:
        'Yes. Request a password hash export from Auth0, then import users through the Appwrite Users API with their bcrypt hashes. Appwrite upgrades each hash to Argon2 on the user\'s first sign-in. You can also add Auth0 as an OAuth provider in Appwrite for a phased cutover.',
      links: [{ label: 'Manage users', href: '/docs/products/auth/users' }],
    },
    {
      question: 'Does Appwrite support enterprise SSO?',
      answer:
        'Appwrite supports OpenID Connect and providers such as Okta, Auth0, Keycloak, Authentik, and Microsoft. Native SAML connections are not available today, so if SAML is a hard requirement for your customers, Auth0 is a strong choice.',
      links: [{ label: 'OAuth2', href: '/docs/products/auth/oauth2' }],
    },
    {
      question: 'Can I self-host Appwrite Auth?',
      answer:
        'Yes. Auth is part of every self-hosted Appwrite install, with the same APIs, SDKs, providers, and security policies as Appwrite Cloud. Switching between them only changes the endpoint.',
      links: [{ label: 'Self-hosting', href: '/docs/advanced/self-hosting' }],
    },
    {
      question: 'Can Appwrite be the identity provider for my own apps?',
      answer:
        'Yes. Appwrite can act as an OAuth 2.1 and OpenID Connect provider with PKCE and rotating refresh tokens, so other apps can offer sign in with your product.',
      links: [{ label: 'OAuth server', href: '/docs/products/auth/oauth-server' }],
    },
  ],
  sources: [
    { label: 'Auth0 pricing', href: 'https://auth0.com/pricing' },
    { label: 'Auth0 plan update', href: 'https://auth0.com/blog/auth0-plans-got-an-upgrade/' },
    {
      label: 'Auth0 deployment options',
      href: 'https://auth0.com/docs/deploy-monitor/deployment-options',
    },
    {
      label: 'Auth0 password hash export',
      href: 'https://auth0.com/docs/manage-users/user-migration/export-password-hashes-and-mfa-secrets',
    },
  ],
}
