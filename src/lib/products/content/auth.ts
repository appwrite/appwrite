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
