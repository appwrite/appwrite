/**
 * Account-scope entries (the user's own account at /account).
 */

import {
  CreditCard,
  KeyRound,
  LogOut,
  MapPin,
  Package,
  Shield,
  User,
} from 'lucide-react'
import { registerCommands } from '../registry'
import type { CommandEntry } from '../types'

const ACCOUNT: CommandEntry[] = [
  {
    id: 'account.nav.general',
    scopes: ['account'],
    kind: 'navigation',
    label: 'Account · General',
    description: 'Profile, name, email and account ID',
    icon: User,
    keywords: ['profile', 'general', 'me', 'name', 'email'],
    to: () => '/account',
  },
  {
    id: 'account.nav.security',
    scopes: ['account'],
    kind: 'navigation',
    label: 'Account · Security',
    description: 'Password, identities and MFA',
    icon: Shield,
    keywords: ['security', 'password', 'mfa', '2fa', 'identities'],
    to: () => '/account/security',
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
    id: 'account.nav.applications',
    scopes: ['account'],
    kind: 'navigation',
    label: 'Account · Applications',
    description: 'OAuth applications authorized on your account',
    icon: Package,
    keywords: ['applications', 'oauth', 'authorized', 'consent', 'revoke'],
    to: () => '/account/applications',
  },
  {
    id: 'account.nav.payment-methods',
    scopes: ['account'],
    kind: 'navigation',
    label: 'Account · Payment methods',
    description: 'Saved cards and payment methods',
    icon: CreditCard,
    keywords: ['payments', 'billing', 'cards', 'methods'],
    available: (ctx) => Boolean(ctx.features.billing),
    to: () => '/account/payment-methods',
  },
  {
    id: 'account.nav.billing-addresses',
    scopes: ['account'],
    kind: 'navigation',
    label: 'Account · Billing addresses',
    description: 'Billing addresses on your account',
    icon: MapPin,
    keywords: ['address', 'billing', 'country', 'postal'],
    available: (ctx) => Boolean(ctx.features.billing),
    to: () => '/account/billing-addresses',
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
    to: () => '/account/security#card-password',
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
    to: () => '/account/security#card-mfa',
  },
]

registerCommands(ACCOUNT)
