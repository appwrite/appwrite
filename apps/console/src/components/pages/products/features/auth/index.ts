import type { ComponentType } from 'react'
import { AuthMfaVisual } from '@/components/pages/products/features/auth/AuthMfaVisual'
import { AuthOAuthVisual } from '@/components/pages/products/features/auth/AuthOAuthVisual'
import { AuthPasswordlessVisual } from '@/components/pages/products/features/auth/AuthPasswordlessVisual'
import { AuthPresencesVisual } from '@/components/pages/products/features/auth/AuthPresencesVisual'
import { AuthSecurityVisual } from '@/components/pages/products/features/auth/AuthSecurityVisual'
import { AuthSsrVisual } from '@/components/pages/products/features/auth/AuthSsrVisual'
import { AuthTeamsVisual } from '@/components/pages/products/features/auth/AuthTeamsVisual'

export const AUTH_FEATURE_VISUALS: Record<string, ComponentType> = {
  oauth: AuthOAuthVisual,
  passwordless: AuthPasswordlessVisual,
  teams: AuthTeamsVisual,
  ssr: AuthSsrVisual,
  security: AuthSecurityVisual,
  mfa: AuthMfaVisual,
  presences: AuthPresencesVisual,
}
