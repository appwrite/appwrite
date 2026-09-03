import { OAuthProvider } from '@appwrite.io/console'
import {
  OAUTH_LOGIN_METHODS,
  type OAuthLoginMethod,
} from '@/lib/utils/auth-storage'

/** Preselected on console sign-in and sign-up when no last-used method applies. */
export const DEFAULT_CONSOLE_OAUTH_LOGIN: OAuthLoginMethod = 'github'

/** Always available on console sign-in and sign-up, regardless of profile flags. */
const ALWAYS_ENABLED_LOGIN_METHODS = new Set<OAuthLoginMethod>([
  'google',
  'github',
  'gitlab',
  'bitbucket',
])

/** Console project OAuth providers. Cursor is not in the public SDK enum yet. */
export const CONSOLE_OAUTH_PROVIDERS: Record<OAuthLoginMethod, OAuthProvider> = {
  google: OAuthProvider.Google,
  github: OAuthProvider.Github,
  gitlab: OAuthProvider.Gitlab,
  bitbucket: OAuthProvider.Bitbucket,
  cursor: 'cursor' as OAuthProvider,
}

export const OAUTH_LOGIN_ERROR: Record<OAuthLoginMethod, string> = {
  google: 'Failed to initiate Google login',
  github: 'Failed to initiate GitHub login',
  gitlab: 'Failed to initiate GitLab login',
  bitbucket: 'Failed to initiate Bitbucket login',
  cursor: 'Failed to initiate Cursor login',
}

export function getVisibleConsoleOAuthProviders(
  extraOAuthLogin: boolean,
): readonly OAuthLoginMethod[] {
  return OAUTH_LOGIN_METHODS.filter((provider) =>
    isConsoleOAuthProviderEnabled(provider, extraOAuthLogin),
  )
}

export function isConsoleOAuthProviderEnabled(
  provider: OAuthLoginMethod,
  extraOAuthLogin: boolean,
): boolean {
  return ALWAYS_ENABLED_LOGIN_METHODS.has(provider) || extraOAuthLogin
}
