import type { AlternativeContent } from '@/lib/alternatives/types'

export const clerkAlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'Plans',
      rows: [
        {
          label: 'Free users',
          appwrite: { value: '75,000', note: 'Monthly active users' },
          competitor: { value: '50,000', note: 'Monthly retained users per app' },
        },
        {
          label: 'First paid plan',
          appwrite: { value: 'From $25/mo', note: 'Includes 200,000 monthly active users' },
          competitor: { value: '$25/mo', note: 'Pro, $20/mo billed yearly, 50,000 users included' },
        },
        {
          label: 'Additional users',
          appwrite: '$3 per 1,000',
          competitor: { value: '$20 per 1,000', note: 'Then $18 per 1,000 past 100,000' },
        },
        {
          label: 'Open source and self-hostable',
          appwrite: { value: true, note: 'Keep identity on your own servers' },
          competitor: { value: false, note: 'Cloud only' },
        },
      ],
    },
    {
      title: 'Sign-in',
      rows: [
        { label: 'Email and password', appwrite: true, competitor: true },
        {
          label: 'Social sign-in',
          appwrite: { value: true, note: '40+ OAuth providers on every plan' },
          competitor: { value: true, note: 'Up to 3 providers on the free plan' },
        },
        { label: 'Magic URL and email OTP', appwrite: true, competitor: true },
        {
          label: 'Phone and SMS sign-in',
          appwrite: true,
          competitor: { value: 'partial', note: 'Paid plans only' },
        },
        {
          label: 'Passkeys',
          appwrite: { value: 'partial', note: 'Through custom token login' },
          competitor: { value: 'partial', note: 'Paid plans only' },
        },
        {
          label: 'Prebuilt sign-in components',
          appwrite: { value: 'partial', note: 'SDKs and starter templates' },
          competitor: { value: true, note: 'A Clerk strength' },
        },
      ],
    },
    {
      title: 'Security',
      rows: [
        {
          label: 'Multi-factor authentication',
          appwrite: { value: true, note: 'TOTP, email, SMS, and recovery codes on every plan' },
          competitor: { value: 'partial', note: 'Paid plans only' },
        },
        {
          label: 'Password dictionary, history, and personal data checks',
          appwrite: true,
          competitor: { value: 'partial', note: 'Custom requirements on paid plans' },
        },
        {
          label: 'Session limits and lengths',
          appwrite: { value: true, note: 'Configurable on every plan' },
          competitor: { value: 'partial', note: 'Fixed to 7 days on the free plan' },
        },
        {
          label: 'Custom email templates',
          appwrite: { value: true, note: 'With your own SMTP server' },
          competitor: { value: 'partial', note: 'Paid plans only' },
        },
      ],
    },
    {
      title: 'Beyond sign-in',
      rows: [
        {
          label: 'Teams and organizations',
          appwrite: { value: true, note: 'Unlimited teams and members on Pro' },
          competitor: { value: 'partial', note: '20 members per organization without the $100/mo add-on' },
        },
        {
          label: 'Users stored next to your data',
          appwrite: { value: true, note: 'Permissions reference users and teams directly' },
          competitor: { value: false, note: 'Sync users to your database with webhooks' },
        },
        {
          label: 'Database, storage, functions, and hosting',
          appwrite: true,
          competitor: false,
        },
      ],
    },
  ],
  fairPlay: {
    title: 'When Clerk might still fit',
    description:
      'Clerk is a polished sign-in product. It may still suit you if these describe your project.',
    points: [
      'You want drop-in React components for sign-in, profiles, and organization switching with almost no UI work.',
      'You sell to enterprises and need SAML connections and SCIM directory sync today.',
      'Your backend already lives elsewhere and you only need identity in front of it.',
      'You plan to charge for subscriptions with Clerk Billing on top of Stripe.',
    ],
  },
  related: [
    {
      kind: 'blog',
      title: 'Appwrite Auth explained: every auth method, compared',
      description: 'Email, OAuth, magic URLs, OTP, and MFA, and when to use each.',
      href: '/blog/post/appwrite-auth-methods',
    },
    {
      kind: 'blog',
      title: 'Why developers choose Appwrite over Auth0 and Firebase',
      description: 'How the main auth providers compare on security and cost.',
      href: '/blog/post/why-developers-choose-appwrite-auth',
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
      title: 'Manage users',
      description: 'Import, export, and update users from the Users API.',
      href: '/docs/products/auth/users',
    },
  ],
  faq: [
    {
      question: 'Is Appwrite better than Clerk?',
      answer:
        'For most apps that need a backend, yes. Appwrite Auth covers the sign-in methods users expect, includes MFA on every plan, and costs $3 per 1,000 users past 200,000 instead of about $20. Your users also live next to your databases, files, and functions, so permissions reference them directly and there are no webhooks to keep in sync.',
    },
    {
      question: 'Is Appwrite cheaper than Clerk?',
      answer:
        'Yes, as soon as you grow past the free tiers. At 250,000 users, Clerk Pro comes to about $3,700 a month at list price, while Appwrite Pro is $175. Clerk counts monthly retained users and Appwrite counts monthly active users, so treat the comparison as a guide.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'What is the best open-source alternative to Clerk?',
      answer:
        'Appwrite is the best open-source alternative to Clerk. Appwrite Auth is fully open source, self-hosts with Docker so user data stays on your servers, and includes email, OAuth, magic URL, OTP, SMS, MFA, teams, and password policies, with the same APIs on Appwrite Cloud and your own servers.',
      links: [{ label: 'Appwrite Auth', href: '/products/auth' }],
    },
    {
      question: 'Do I need webhooks to keep users in sync with my database?',
      answer:
        'Not on Appwrite. Users, teams, and your data live in the same project, so a row or file can grant access to a user or team directly. With Clerk, users live in Clerk, and most apps mirror them into their own database with webhooks.',
      links: [{ label: 'Permissions', href: '/docs/products/databases/permissions' }],
    },
    {
      question: 'Can I migrate users from Clerk to Appwrite?',
      answer:
        'Yes. Export your users from Clerk, then import them through the Appwrite Users API with their bcrypt password hashes, so nobody has to reset a password. Appwrite upgrades each hash to Argon2 on the first sign-in.',
      links: [{ label: 'Manage users', href: '/docs/products/auth/users' }],
    },
    {
      question: 'Does Appwrite have prebuilt sign-in components like Clerk?',
      answer:
        'Appwrite ships SDKs for web, mobile, and server, plus starter templates for popular frameworks, rather than a hosted component library. Most teams build sign-in with their own design system in a few lines of SDK code.',
      links: [{ label: 'Auth docs', href: '/docs/products/auth' }],
    },
  ],
  sources: [
    { label: 'Clerk pricing', href: 'https://clerk.com/pricing' },
    { label: 'Clerk webhooks for data sync', href: 'https://clerk.com/docs/guides/development/webhooks/overview' },
    { label: 'Clerk organizations', href: 'https://clerk.com/docs/guides/organizations/overview' },
  ],
}
