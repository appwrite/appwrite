import type { SettingsCardIndexEntry } from '@/lib/settings-search'

export const ACCOUNT_SETTINGS_CARD_INDEX: SettingsCardIndexEntry[] = [
  {
    sectionId: 'overview',
    title: 'Account ID',
    keywords: ['id', 'api', 'sdk', 'copy'],
  },
  {
    sectionId: 'overview',
    title: 'Update name',
    keywords: ['name', 'display name', 'profile'],
  },
  {
    sectionId: 'overview',
    title: 'Update email',
    keywords: ['email', 'address', 'verified'],
  },
  {
    sectionId: 'overview',
    title: 'Delete account',
    keywords: ['delete', 'remove', 'destroy', 'danger'],
  },
  {
    sectionId: 'security',
    title: 'Update password',
    keywords: ['password', 'change', 'reset', 'recovery'],
  },
  {
    sectionId: 'security',
    title: 'Identities',
    keywords: ['oauth', 'github', 'google', 'social', 'login'],
  },
  {
    sectionId: 'security',
    title: 'Multi-factor authentication',
    keywords: ['mfa', '2fa', 'totp', 'authenticator', 'recovery codes'],
  },
  {
    sectionId: 'sessions',
    title: 'Active sessions',
    keywords: ['sessions', 'devices', 'logout', 'revoke'],
  },
  {
    sectionId: 'applications',
    title: 'Applications',
    keywords: ['applications', 'oauth', 'authorized', 'consent', 'revoke'],
  },
  {
    sectionId: 'payment-methods',
    title: 'Payment methods',
    keywords: ['card', 'credit card', 'stripe', 'payment method'],
  },
  {
    sectionId: 'billing-addresses',
    title: 'Billing addresses',
    keywords: ['address', 'country', 'city', 'postal', 'zip', 'street'],
  },
]
