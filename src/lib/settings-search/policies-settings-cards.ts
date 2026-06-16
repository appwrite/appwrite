import type { SettingsCardIndexEntry } from '@/lib/settings-search'

export const POLICIES_SETTINGS_CARD_INDEX: SettingsCardIndexEntry[] = [
  {
    sectionId: 'sessions',
    title: 'Session length',
    keywords: ['duration', 'expiry', 'timeout', 'logout'],
  },
  {
    sectionId: 'sessions',
    title: 'Sessions limit',
    keywords: ['maximum', 'active', 'concurrent'],
  },
  {
    sectionId: 'sessions',
    title: 'Session alerts',
    keywords: ['notification', 'new session', 'email'],
  },
  {
    sectionId: 'sessions',
    title: 'Invalidate sessions',
    keywords: ['password change', 'revoke', 'sign out'],
  },
  {
    sectionId: 'users',
    title: 'Users limit',
    keywords: ['signup', 'maximum', 'registration', 'unlimited'],
  },
  {
    sectionId: 'emails',
    title: 'Free emails',
    keywords: ['gmail', 'yahoo', 'signup', 'block'],
  },
  {
    sectionId: 'emails',
    title: 'Aliased emails',
    keywords: ['plus', 'alias', 'signup'],
  },
  {
    sectionId: 'emails',
    title: 'Disposable emails',
    keywords: ['mailinator', 'temp', 'signup'],
  },
  {
    sectionId: 'emails',
    title: 'Corporate emails',
    keywords: ['business', 'work', 'organization', 'domain', 'signup'],
  },
  {
    sectionId: 'memberships',
    title: 'Privacy',
    keywords: ['team', 'mfa', 'hidden', 'name', 'email'],
  },
  {
    sectionId: 'passwords',
    title: 'Strength',
    keywords: [
      'length',
      'uppercase',
      'lowercase',
      'complexity',
      'nist',
      'owasp',
    ],
  },
  {
    sectionId: 'passwords',
    title: 'History',
    keywords: ['reuse', 'previous passwords'],
  },
  {
    sectionId: 'passwords',
    title: 'Dictionary',
    keywords: ['common passwords', 'weak'],
  },
  {
    sectionId: 'passwords',
    title: 'Personal data',
    keywords: ['name', 'email in password'],
  },
]
