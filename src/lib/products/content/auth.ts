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
      { value: '40+', label: 'OAuth providers' },
      { value: '7', label: 'Sign-in methods' },
      { value: 'Multi-Tenancy', label: 'Memberships & roles' },
      { value: 'MFA', label: 'Two-factor auth' },
    ],
  },
  faq: [
    {
      question: 'Can I use Auth without building a custom login UI?',
      answer:
        'Yes. Appwrite Auth is API-first, so you keep full control of the UI in your app and call the Account SDK for sign-up, login, sessions, and MFA. Quick starts cover React, Next.js, Vue, SvelteKit, Flutter, and other platforms. For server-rendered apps, dedicated guides show how to verify sessions and issue cookies from your backend.',
      links: [
        { label: 'Quick start', href: '/docs/products/auth/quick-start' },
        { label: 'SSR authentication', href: '/docs/products/auth/server-side-rendering' },
      ],
    },
    {
      question: 'Does Auth support social login?',
      answer:
        'Yes. Appwrite supports OAuth 2.0 with 30+ providers, including GitHub, Google, Apple, Discord, and Microsoft. Enable providers in the Console under Auth > Social providers, add your OAuth credentials and redirect URI, then start the flow from the Account SDK in your app.',
      links: [{ label: 'OAuth2 docs', href: '/docs/products/auth/oauth2' }],
    },
    {
      question: 'Can I migrate users from another auth provider?',
      answer:
        'Yes. Import users through the Console or the Users API with the Server SDK. For email and password accounts, you can create users with plain-text passwords or import existing password hashes when your provider uses a supported algorithm: Argon2, bcrypt, scrypt, scrypt-modified (Firebase), SHA, MD5, or PHPass. New passwords are stored with Argon2. Hashes imported from other algorithms are upgraded to Argon2 after the user\'s first successful sign-in.',
      links: [
        { label: 'Manage users', href: '/docs/products/auth/users' },
        { label: 'Password hashing', href: '/docs/advanced/security/authentication#password-hashing' },
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
    {
      question: 'How are passwords stored and validated?',
      answer:
        'Appwrite hashes passwords with Argon2, including salting and adjustable work factors. You can enforce minimum length, character requirements, password history, dictionary checks, and rules that block personal data in passwords. Email policies can block disposable, aliased, or free-provider addresses at sign-up.',
      links: [
        { label: 'Email and password login', href: '/docs/products/auth/email-password' },
        { label: 'Authentication security', href: '/docs/advanced/security/authentication' },
        { label: 'Email policies', href: '/docs/products/auth/email-policies' },
      ],
    },
  ],
  cta: {
    title: 'Start building with Auth',
    description: 'Create a project and add authentication in minutes with our quick start guides.',
  },
}
