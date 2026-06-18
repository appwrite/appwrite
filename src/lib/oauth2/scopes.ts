/**
 * Human-readable descriptions for OAuth2 / OIDC scopes shown on the consent
 * screen. Built-in OIDC scopes are described explicitly; anything else (project
 * scopes) falls back to a generic, readable label derived from the scope id.
 */
import { User, Mail, IdCard, KeyRound, type LucideIcon } from 'lucide-react'

export interface ScopeDescriptor {
  id: string
  title: string
  description: string
  icon: LucideIcon
}

const BUILTIN_SCOPES: Record<string, Omit<ScopeDescriptor, 'id'>> = {
  openid: {
    title: 'Verify your identity',
    description: 'Confirm who you are using your Appwrite account.',
    icon: IdCard,
  },
  profile: {
    title: 'View your profile',
    description: 'Read your name and profile details.',
    icon: User,
  },
  email: {
    title: 'View your email address',
    description: 'Read the email address associated with your account.',
    icon: Mail,
  },
}

function titleizeScope(scope: string): string {
  const cleaned = scope.replace(/[._:-]+/g, ' ').trim()
  if (!cleaned) return scope
  return cleaned.charAt(0).toUpperCase() + cleaned.slice(1)
}

export function describeScope(scope: string): ScopeDescriptor {
  const builtin = BUILTIN_SCOPES[scope]
  if (builtin) {
    return { id: scope, ...builtin }
  }
  return {
    id: scope,
    title: titleizeScope(scope),
    description: `Access to ${scope}.`,
    icon: KeyRound,
  }
}

export function describeScopes(scopes: string[]): ScopeDescriptor[] {
  return scopes.map(describeScope)
}
