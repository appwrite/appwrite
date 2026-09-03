import { OAuthProvider } from '@appwrite.io/console'
import {
  OAUTH_LOGIN_METHODS,
  type OAuthLoginMethod,
} from '@/lib/utils/auth-storage'

/** Always available on console sign-in and sign-up. */
export const DEFAULT_CONSOLE_OAUTH_LOGIN: OAuthLoginMethod = 'github'

/** Console project OAuth providers. */
export const CONSOLE_OAUTH_PROVIDERS: Record<OAuthLoginMethod, OAuthProvider> = {
  google: OAuthProvider.Google,
  github: OAuthProvider.Github,
  gitlab: OAuthProvider.Gitlab,
  bitbucket: OAuthProvider.Bitbucket,
}

export const OAUTH_LOGIN_ERROR: Record<OAuthLoginMethod, string> = {
  google: 'Failed to initiate Google login',
  github: 'Failed to initiate GitHub login',
  gitlab: 'Failed to initiate GitLab login',
  bitbucket: 'Failed to initiate Bitbucket login',
}

export function getVisibleConsoleOAuthProviders(
  extraOAuthLogin: boolean,
): readonly OAuthLoginMethod[] {
  if (extraOAuthLogin) return OAUTH_LOGIN_METHODS
  return [DEFAULT_CONSOLE_OAUTH_LOGIN]
}

export function isConsoleOAuthProviderEnabled(
  provider: OAuthLoginMethod,
  extraOAuthLogin: boolean,
): boolean {
  return extraOAuthLogin || provider === DEFAULT_CONSOLE_OAUTH_LOGIN
}
