import {
  Fingerprint,
  KeyRound,
  LockKeyhole,
  Mail,
  Phone,
  ShieldCheck,
  UserRound,
  Users,
} from 'lucide-react'
import type { ProductPageContent } from '@/lib/products/types'

export const authProductContent: ProductPageContent = {
  id: 'auth',
  metaDescription:
    'Add secure authentication to your app with email, OAuth, SMS, magic URLs, MFA, teams, and session management.',
  hero: {
    title: 'Authentication that ships with your product',
    description:
      'Give users a secure sign-in experience without building auth infrastructure. Appwrite Auth supports the methods your users expect and the controls your team needs.',
    stats: [
      { value: '40+', label: 'Social providers' },
      { value: '5', label: 'Auth policy controls' },
      { value: 'Multi-Tenancy', label: 'Memberships & roles' },
      { value: 'MFA', label: 'Authenticator apps' },
    ],
  },
  capabilities: {
    title: 'Everything you need to authenticate users',
    description:
      'From first signup to enterprise access control, Auth covers identity, sessions, and permissions in one service.',
    items: [
      {
        title: 'Flexible sign-in methods',
        description:
          'Email and password, OAuth, SMS, magic URLs, anonymous sessions, and more in a single API.',
        icon: KeyRound,
      },
      {
        title: 'Session management',
        description:
          'Issue JWTs, manage sessions, and verify auth status from client or server SDKs.',
        icon: ShieldCheck,
      },
      {
        title: 'Teams and roles',
        description:
          'Organize users into teams with memberships, invites, and scoped access for multi-tenant apps.',
        icon: Users,
      },
      {
        title: 'Security controls',
        description:
          'MFA, rate limits, password policies, and labels to keep accounts and data protected.',
        icon: LockKeyhole,
      },
      {
        title: 'User management',
        description:
          'Create, update, and manage user profiles, preferences, identities, and verification flows.',
        icon: UserRound,
      },
      {
        title: 'Platform permissions',
        description:
          'Use Auth identities to gate Storage files, Database rows, Functions, and other resources.',
        icon: Fingerprint,
      },
    ],
  },
  visual: {
    title: 'Sign-in flows your users already understand',
    description:
      'Combine OAuth, email, and passwordless options in a flow that matches your brand and security requirements.',
  },
  uniqueSections: [
    {
      type: 'method-cards',
      title: 'Authentication methods',
      description:
        'Pick the sign-in experience that fits your app. Mix and match methods without maintaining separate auth stacks.',
      items: [
        {
          title: 'Email and password',
          description: 'Secure login with Argon2 password hashing.',
          icon: Mail,
          href: '/docs/products/auth/email-password',
        },
        {
          title: 'OAuth 2',
          description: 'GitHub, Google, Apple, and 30+ providers.',
          icon: Users,
          href: '/docs/products/auth/oauth2',
        },
        {
          title: 'Phone (SMS)',
          description: 'Passwordless login with SMS verification.',
          icon: Phone,
          href: '/docs/products/auth/phone-sms',
        },
        {
          title: 'Magic URL',
          description: 'One-click email links without passwords.',
          icon: Mail,
          href: '/docs/products/auth/magic-url',
        },
        {
          title: 'Email OTP',
          description: 'Time-based codes sent to the user inbox.',
          icon: KeyRound,
          href: '/docs/products/auth/email-otp',
        },
        {
          title: 'Anonymous',
          description: 'Guest sessions that convert to full accounts.',
          icon: UserRound,
          href: '/docs/products/auth/anonymous',
        },
      ],
    },
    {
      type: 'feature-grid',
      title: 'Security and access control',
      description:
        'Go beyond sign-in with enterprise-ready identity features built into the platform.',
      items: [
        {
          title: 'Multi-factor authentication',
          description: 'Add TOTP and other factors for sensitive accounts.',
          icon: ShieldCheck,
        },
        {
          title: 'Custom sessions',
          description: 'Control session length, refresh, and revocation from the console.',
          icon: KeyRound,
        },
        {
          title: 'Labels and preferences',
          description: 'Tag users and store profile data for segmentation and personalization.',
          icon: Fingerprint,
        },
        {
          title: 'Server-side rendering',
          description: 'Verify sessions in SSR frameworks with JWT and cookie helpers.',
          icon: LockKeyhole,
        },
      ],
      columns: 2,
      muted: true,
    },
  ],
  integrations: {
    title: 'Works with the Appwrite platform',
    description:
      'Auth is the identity layer for every other Appwrite product in your project.',
    items: [
      {
        productId: 'databases',
        title: 'Row-level permissions',
        description: 'Scope database access to signed-in users, teams, or custom roles.',
      },
      {
        productId: 'storage',
        title: 'File access control',
        description: 'Restrict buckets and files to authenticated users and teams.',
      },
      {
        productId: 'functions',
        title: 'Secure backends',
        description: 'Validate JWTs inside Functions before running business logic.',
      },
      {
        productId: 'messaging',
        title: 'Verified outreach',
        description: 'Send email and push messages to users managed through Auth.',
      },
    ],
  },
  faq: [
    {
      question: 'Can I use Auth without building a custom login UI?',
      answer:
        'Yes. Use the Appwrite SDKs to build your own UI, or integrate with your existing frontend. Docs include quick starts for popular frameworks.',
    },
    {
      question: 'Does Auth support social login?',
      answer:
        'Yes. Appwrite supports OAuth 2 with GitHub, Google, Apple, Discord, and many other providers.',
    },
    {
      question: 'Can I migrate users from another auth provider?',
      answer:
        'You can import users with the Console or server SDKs. Hashed passwords can be migrated when compatible with supported algorithms.',
    },
    {
      question: 'How does Auth work with self-hosted Appwrite?',
      answer:
        'Auth is included in self-hosted deployments with the same APIs and SDKs as Appwrite Cloud.',
    },
  ],
  cta: {
    title: 'Start building with Auth',
    description: 'Create a project and add authentication in minutes with our quick start guides.',
  },
}
