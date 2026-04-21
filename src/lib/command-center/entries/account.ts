/**
 * Account-scope entries (the user's own account at /account).
 */

import { CreditCard, KeyRound, LogOut, Shield, User } from 'lucide-react'
import { registerCommands } from '../registry'
import type { CommandEntry } from '../types'

const ACCOUNT: CommandEntry[] = [
  {
    id: 'account.nav.overview',
    scopes: ['account'],
    kind: 'navigation',
    label: 'Account · Overview',
    description: 'Profile, name, email and preferences',
    icon: User,
    keywords: ['profile', 'overview', 'me', 'name', 'email'],
    to: () => '/account',
  },
  {
    id: 'account.nav.sessions',
    scopes: ['account'],
    kind: 'navigation',
    label: 'Account · Sessions',
    description: 'Active sessions and devices',
    icon: LogOut,
    keywords: ['sessions', 'devices', 'logout', 'sign out'],
    to: () => '/account/sessions',
  },
  {
    id: 'account.nav.payments',
    scopes: ['account'],
    kind: 'navigation',
    label: 'Account · Payments',
    description: 'Payment methods and billing details',
    icon: CreditCard,
    keywords: ['payments', 'billing', 'cards', 'methods'],
    available: (ctx) => Boolean(ctx.features.billing),
    to: () => '/account/payments',
  },
  {
    id: 'account.card.security.password',
    scopes: ['account'],
    kind: 'card',
    group: 'Security',
    label: 'Security · Change password',
    description: 'Update your account password',
    icon: KeyRound,
    keywords: ['password', 'change', 'security', 'reset'],
    to: () => '/account#card-password',
  },
  {
    id: 'account.card.security.mfa',
    scopes: ['account'],
    kind: 'card',
    group: 'Security',
    label: 'Security · Multi-factor authentication',
    description: 'Enable MFA with TOTP, email or SMS',
    icon: Shield,
    keywords: ['mfa', '2fa', 'totp', 'authenticator', 'security'],
    available: (ctx) => Boolean(ctx.features.accountMfa),
    to: () => '/account#card-mfa',
  },
]

registerCommands(ACCOUNT)
