/**
 * Appwrite side of the SMTP quick setup: claim the provider identity through
 * the console OAuth2 token flow and keep a usable provider access token.
 *
 * Token lifecycle (browser only):
 * 1. `startProviderAuthorization` redirects through `account.createOAuth2Token`.
 * 2. Back on the SMTP tab, `claimProviderIdentity` calls `account.createSession`
 *    with the returned `userId` + `secret`. The new session carries the provider
 *    access and refresh tokens copied from the identity.
 * 3. `resolveProviderAccessToken` reuses that session, or any other session
 *    this account holds for the provider, refreshing it through
 *    `account.updateSession` when the short-lived access token expired.
 *    Only when no session can produce a token does the flow restart at 1.
 *
 * Nothing here is provider-specific beyond the id and scopes passed in, so the
 * same flow serves every entry in `providers.ts`.
 */

import { Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  buildQuickSetupOAuthUrls,
  isProviderTokenExpired,
  rememberQuickSetupPending,
} from './quick-setup'
import type { AvailableSmtpQuickSetupProvider } from './providers'

export type ProviderCredentialSource = 'session' | 'identity'

export interface ProviderAccessToken {
  token: string
  /** ISO 8601 expiry reported by Appwrite. */
  expiry: string
  source: ProviderCredentialSource
}

/** The current console session cannot produce a fresh token; run the OAuth2 flow again. */
export class QuickSetupReauthorizeRequiredError extends Error {
  constructor(
    message = 'Provider authorization is required',
    options?: ErrorOptions,
  ) {
    super(message, options)
    this.name = 'QuickSetupReauthorizeRequiredError'
  }
}

type ProviderCredentials = Pick<
  Models.Session,
  'provider' | 'providerAccessToken' | 'providerAccessTokenExpiry'
>

export function tokenFromSession(
  session: ProviderCredentials,
  providerId: string,
): ProviderAccessToken | null {
  if (session.provider !== providerId || !session.providerAccessToken) {
    return null
  }
  return {
    token: session.providerAccessToken,
    expiry: session.providerAccessTokenExpiry,
    source: 'session',
  }
}

export function sessionCanRefreshProvider(
  session: Pick<Models.Session, 'provider' | 'providerRefreshToken'>,
  providerId: string,
): boolean {
  return (
    session.provider === providerId && Boolean(session.providerRefreshToken)
  )
}

async function getCurrentSession(): Promise<Models.Session> {
  return await sdk.forConsole.account.getSession({ sessionId: 'current' })
}

/**
 * Refresh one of the user's sessions by id. Appwrite re-runs the provider's
 * refresh grant for whichever session is named, current or not, and answers
 * with the session including its new provider access token.
 */
async function refreshSessionById(
  sessionId: string,
  providerId: string,
): Promise<ProviderAccessToken> {
  let refreshed: Models.Session
  try {
    refreshed = await sdk.forConsole.account.updateSession({ sessionId })
  } catch (error) {
    throw new QuickSetupReauthorizeRequiredError(undefined, { cause: error })
  }

  const token = tokenFromSession(refreshed, providerId)
  if (!token || isProviderTokenExpired(token.expiry)) {
    throw new QuickSetupReauthorizeRequiredError()
  }
  return token
}

/**
 * A session created by this provider's OAuth2 flow that still holds a refresh
 * token. Signing in again does not remove it, so it usually outlives the
 * 15-minute access token and is what keeps quick setup off the redirect path.
 */
async function findRefreshableProviderSession(
  providerId: string,
  excludeSessionId?: string,
): Promise<Models.Session | null> {
  const response = await sdk.forConsole.account.listSessions()
  return (
    (response.sessions ?? []).find(
      (session) =>
        session.$id !== excludeSessionId &&
        session.provider === providerId &&
        Boolean(session.providerRefreshToken),
    ) ?? null
  )
}

/**
 * Force a new access token from any session this account holds for the
 * provider. Throws {@link QuickSetupReauthorizeRequiredError} only when no
 * session can produce one, which is the single case that needs the user to
 * authorize again.
 */
export async function refreshProviderAccessToken(
  providerId: string,
): Promise<ProviderAccessToken> {
  const session = await getCurrentSession()
  if (sessionCanRefreshProvider(session, providerId)) {
    return await refreshSessionById('current', providerId)
  }

  const other = await findRefreshableProviderSession(providerId, session.$id)
  if (other) return await refreshSessionById(other.$id, providerId)

  throw new QuickSetupReauthorizeRequiredError()
}

export async function findProviderIdentity(
  providerId: string,
): Promise<Models.Identity | null> {
  const response = await sdk.forConsole.account.listIdentities({
    queries: [Query.equal('provider', [providerId]), Query.limit(1)],
    total: false,
  })
  return response.identities?.[0] ?? null
}

/**
 * Best available provider access token without user interaction, or `null`
 * when only a new authorization can help.
 *
 * Tries, in order: the current session's live token, a refresh of the current
 * session, the identity's token (fresh right after a claim), then a refresh of
 * any other session this account holds for the provider. Appwrite has no
 * endpoint to refresh an identity, which is why the sessions carry the flow.
 */
export async function resolveProviderAccessToken(
  providerId: string,
): Promise<ProviderAccessToken | null> {
  const session = await getCurrentSession()

  const fromSession = tokenFromSession(session, providerId)
  if (fromSession && !isProviderTokenExpired(fromSession.expiry)) {
    return fromSession
  }

  if (sessionCanRefreshProvider(session, providerId)) {
    try {
      return await refreshSessionById('current', providerId)
    } catch (error) {
      if (!(error instanceof QuickSetupReauthorizeRequiredError)) throw error
    }
  }

  const identity = await findProviderIdentity(providerId)
  if (
    identity?.providerAccessToken &&
    !isProviderTokenExpired(identity.providerAccessTokenExpiry)
  ) {
    return {
      token: identity.providerAccessToken,
      expiry: identity.providerAccessTokenExpiry,
      source: 'identity',
    }
  }

  const other = await findRefreshableProviderSession(providerId, session.$id)
  if (other) {
    try {
      return await refreshSessionById(other.$id, providerId)
    } catch (error) {
      if (!(error instanceof QuickSetupReauthorizeRequiredError)) throw error
    }
  }

  return null
}

/**
 * Leave for the provider's consent screen.
 *
 * Appwrite deletes the current session the moment this flow starts, so the
 * redirect targets `/auth/smtp/callback`, which restores the session from the
 * returned `userId` + `secret` before sending the user back to the SMTP tab.
 * The pending record lets that route verify the callback belongs to this
 * browser and this account.
 */
export function startProviderAuthorization(
  provider: AvailableSmtpQuickSetupProvider,
  projectId: string,
  accountId: string,
): void {
  if (typeof window === 'undefined') {
    throw new Error('Provider authorization requires a browser')
  }
  rememberQuickSetupPending({
    providerId: provider.id,
    projectId,
    accountId,
  })
  const { success, failure } = buildQuickSetupOAuthUrls(
    window.location.origin,
    projectId,
    provider.id,
  )
  const result = sdk.forConsole.account.createOAuth2Token({
    provider: provider.oauth.provider,
    success,
    failure,
    scopes: provider.oauth.scopes,
  })
  // In browsers the SDK navigates itself; outside of one it returns the URL.
  if (typeof result === 'string') {
    window.location.assign(result)
  }
}

/**
 * Exchange the one-time OAuth2 token for a session, restoring the console
 * login that Appwrite dropped when the flow started. Callers must first check
 * the claim against the pending record (`isExpectedQuickSetupClaim`).
 */
export async function claimProviderIdentity(params: {
  userId: string
  secret: string
}): Promise<Models.Session> {
  return await sdk.forConsole.account.createSession({
    userId: params.userId,
    secret: params.secret,
  })
}
