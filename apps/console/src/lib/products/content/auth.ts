import type { ProductPageContent } from '@/lib/products/types'

export const authProductContent: ProductPageContent = {
  id: 'auth',
  metaDescription:
    'Add secure authentication to your app with email, OAuth, SMS, magic URLs, MFA, teams, presences, and session management.',
  hero: {
    title: 'Authentication that ships with your product',
    description:
      'Give users a secure sign-in experience without building auth infrastructure. Appwrite Auth supports the methods your users expect, team collaboration signals, and the controls your team needs.',
    stats: [
      { value: '40+', label: 'OAuth providers' },
      { value: '7', label: 'Sign-in methods' },
      { value: 'Multi-Tenancy', label: 'Memberships & roles' },
      { value: 'MFA', label: 'Two-factor auth' },
      { value: 'Presences', label: 'Team collaboration' },
    ],
  },
  faq: [
    {
      question: 'Can I use Auth without building a custom login UI?',
      answer:
        'Yes. Appwrite Auth is API-first, so you keep full control of the UI in your app and call the Account SDK for sign-up, login, sessions, and MFA. Quick starts cover React, Next.js, Vue, SvelteKit, Flutter, and other platforms. For server-rendered apps, verify sessions on your backend and issue HTTP-only cookies using the SSR guides.',
      links: [
        { label: 'Quick start', href: '/docs/products/auth/quick-start' },
        { label: 'SSR authentication', href: '/docs/products/auth/server-side-rendering' },
        { label: 'Checking auth status', href: '/docs/products/auth/checking-auth-status' },
      ],
    },
    {
      question: 'Does Auth support social login and linked identities?',
      answer:
        'Yes. Appwrite supports OAuth 2.0 with 30+ providers, including GitHub, Google, Apple, Discord, and Microsoft. Enable providers in the Console under Auth > Social providers, add your OAuth credentials and redirect URI, then start the flow from the Account SDK. Each OAuth sign-in creates an identity linked to the user account, so one person can connect multiple providers without duplicate accounts.',
      links: [
        { label: 'OAuth2', href: '/docs/products/auth/oauth2' },
        { label: 'Identities', href: '/docs/products/auth/identities' },
      ],
    },
    {
      question: 'How does passwordless sign-in work?',
      answer:
        'Enable Magic URL, Email OTP, and Phone SMS from Auth settings in the Console. Magic URL sends a one-click sign-in link to the user\'s email. Email OTP delivers a time-limited code they enter in your app. Phone SMS verifies users through text messages without a password. You can offer passwordless methods alongside email and password, or disable password login entirely for a password-free experience.',
      links: [
        { label: 'Magic URL', href: '/docs/products/auth/magic-url' },
        { label: 'Email OTP', href: '/docs/products/auth/email-otp' },
        { label: 'Phone SMS', href: '/docs/products/auth/phone-sms' },
      ],
    },
    {
      question: 'How do teams and multi-tenancy work?',
      answer:
        'Create a team for each customer, organization, or workspace in your app. Invite members by email, assign roles, and scope databases, storage buckets, functions, and other resources with team-based permissions. Teams give you tenant isolation without building custom RBAC, and membership privacy settings let you control whether member lists are visible to other users.',
      links: [
        { label: 'Multi-tenancy', href: '/docs/products/auth/multi-tenancy' },
        { label: 'Teams', href: '/docs/products/auth/teams' },
        { label: 'Team invites', href: '/docs/products/auth/team-invites' },
      ],
    },
    {
      question: 'How do I add multi-factor authentication?',
      answer:
        'Enable MFA in Auth settings, then let users enroll an authenticator app (TOTP) and download recovery codes. MFA adds a second step after the primary sign-in method. Require it for sensitive actions such as updating credentials or accessing protected resources. Users who lose their device can sign in with a recovery code instead of the TOTP.',
      links: [
        { label: 'MFA', href: '/docs/products/auth/mfa' },
        { label: 'Security', href: '/docs/products/auth/security' },
      ],
    },
    {
      question: 'Can I migrate users from another auth provider?',
      answer:
        'Yes. Import users through the Console or the Users API with the Server SDK. For email and password accounts, create users with plain-text passwords or import existing password hashes when your provider uses a supported algorithm: Argon2, bcrypt, scrypt, scrypt-modified (Firebase), SHA, MD5, or PHPass. New passwords are stored with Argon2. Hashes imported from other algorithms are upgraded to Argon2 after the user\'s first successful sign-in.',
      links: [
        { label: 'Manage users', href: '/docs/products/auth/users' },
        { label: 'Password hashing', href: '/docs/advanced/security/authentication#password-hashing' },
      ],
    },
    {
      question: 'How are passwords, sessions, and security policies configured?',
      answer:
        'Appwrite hashes passwords with Argon2, including salting and adjustable work factors. From Auth Policies and Settings, set minimum length, character requirements, password history, dictionary checks, and rules that block personal data in passwords. Email policies can block disposable, aliased, or free-provider addresses at sign-up. Session settings control duration, limits per user, and cookie behavior for web apps.',
      links: [
        { label: 'Email and password login', href: '/docs/products/auth/email-password' },
        { label: 'Email policies', href: '/docs/products/auth/email-policies' },
        { label: 'Security', href: '/docs/products/auth/security' },
      ],
    },
    {
      question: 'What team collaboration features can presences power?',
      answer:
        'Presences show who is active right now: online, away, typing, or viewing a page or channel. Upsert records with status and metadata, then subscribe over Realtime for live updates. Use them for team rosters, shared doc viewers, chat typing indicators, and support queue availability.',
      links: [
        { label: 'Presences', href: '/docs/products/auth/presences' },
        { label: 'Realtime', href: '/docs/apis/realtime' },
      ],
    },
    {
      question: 'How does Auth work with self-hosted Appwrite?',
      answer:
        'Auth is included in every Appwrite deployment. Self-hosted installations use the same Auth APIs, SDKs, OAuth providers, policies, and session behavior as Appwrite Cloud. Configure auth methods, password rules, and security policies from the Console the same way.',
      links: [
        { label: 'Auth overview', href: '/docs/products/auth' },
        { label: 'Self-hosting', href: '/docs/advanced/self-hosting' },
      ],
    },
  ],
  cta: {
    title: 'Start building with Auth',
    description: 'Create a project and add authentication in minutes with our quick start guides.',
  },
}
