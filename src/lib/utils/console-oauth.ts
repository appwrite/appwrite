import { OAuthProvider } from '@appwrite.io/console'
import type { OAuthLoginMethod } from '@/lib/utils/auth-storage'

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
