/**
 * Appwrite side of the Resend quick setup: claim the Resend identity through
 * the console OAuth2 token flow and keep a usable provider access token.
 *
 * Token lifecycle (browser only):
 * 1. `startResendAuthorization` redirects through `account.createOAuth2Token`.
 * 2. Back on the SMTP tab, `claimResendIdentity` calls `account.createSession`
 *    with the returned `userId` + `secret`. The new session carries the Resend
 *    access and refresh tokens copied from the identity.
 * 3. `resolveResendAccessToken` reuses that session (refreshing through
 *    `account.updateSession` when the 15-minute access token expired) and falls
 *    back to the stored identity. When neither works the caller restarts at 1.
 */

import { OAuthProvider, Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  RESEND_OAUTH_SCOPES,
  RESEND_PROVIDER_ID,
  buildResendOAuthUrls,
  isProviderTokenExpired,
} from './resend'

export type ResendCredentialSource = 'session' | 'identity'

export interface ResendAccessToken {
  token: string
  /** ISO 8601 expiry reported by Appwrite. */
  expiry: string
  source: ResendCredentialSource
}

/** The current console session cannot produce a fresh Resend token; run the OAuth2 flow again. */
export class ResendReauthorizeRequiredError extends Error {
  constructor(
    message = 'Resend authorization is required',
    options?: ErrorOptions,
  ) {
    super(message, options)
    this.name = 'ResendReauthorizeRequiredError'
  }
}

/** The OAuth2 callback returned a token for a different console account. */
export class ResendAccountMismatchError extends Error {
  constructor() {
    super('The Resend account was linked to a different Appwrite account')
    this.name = 'ResendAccountMismatchError'
  }
}

type ProviderCredentials = Pick<
  Models.Session,
  'provider' | 'providerAccessToken' | 'providerAccessTokenExpiry'
>

export function tokenFromSession(
  session: ProviderCredentials,
): ResendAccessToken | null {
  if (session.provider !== RESEND_PROVIDER_ID || !session.providerAccessToken) {
    return null
  }
  return {
    token: session.providerAccessToken,
    expiry: session.providerAccessTokenExpiry,
    source: 'session',
  }
}

export function sessionCanRefreshResend(
  session: Pick<Models.Session, 'provider' | 'providerRefreshToken'>,
): boolean {
  return (
    session.provider === RESEND_PROVIDER_ID &&
    Boolean(session.providerRefreshToken)
  )
}

async function getCurrentSession(): Promise<Models.Session> {
  return await sdk.forConsole.account.getSession({ sessionId: 'current' })
}

/**
 * Appwrite refreshes the provider tokens of the *session* through
 * `updateSession`, so only a session created from the Resend token flow can
 * be refreshed. Identities keep the original (now rotated) refresh token and
 * have no refresh endpoint.
 */
async function refreshFromSession(
  session: Models.Session,
): Promise<ResendAccessToken> {
  if (!sessionCanRefreshResend(session)) {
    throw new ResendReauthorizeRequiredError()
  }

  let refreshed: Models.Session
  try {
    refreshed = await sdk.forConsole.account.updateSession({
      sessionId: 'current',
    })
  } catch (error) {
    throw new ResendReauthorizeRequiredError(undefined, { cause: error })
  }

  const token = tokenFromSession(refreshed)
  if (!token || isProviderTokenExpired(token.expiry)) {
    throw new ResendReauthorizeRequiredError()
  }
  return token
}

/**
 * Force a new access token for the current session. Throws
 * {@link ResendReauthorizeRequiredError} when the session is not a Resend
 * session or Resend rejected the refresh token.
 */
export async function refreshResendAccessToken(): Promise<ResendAccessToken> {
  return await refreshFromSession(await getCurrentSession())
}

export async function findResendIdentity(): Promise<Models.Identity | null> {
  const response = await sdk.forConsole.account.listIdentities({
    queries: [Query.equal('provider', [RESEND_PROVIDER_ID]), Query.limit(1)],
    total: false,
  })
  return response.identities?.[0] ?? null
}

/**
 * Best available Resend access token without user interaction, or `null`
 * when a new authorization is needed.
 */
export async function resolveResendAccessToken(): Promise<ResendAccessToken | null> {
  const session = await getCurrentSession()

  const fromSession = tokenFromSession(session)
  if (fromSession && !isProviderTokenExpired(fromSession.expiry)) {
    return fromSession
  }

  if (sessionCanRefreshResend(session)) {
    try {
      return await refreshFromSession(session)
    } catch (error) {
      if (!(error instanceof ResendReauthorizeRequiredError)) throw error
    }
  }

  // Identity tokens are only fresh right after a claim (15-minute lifetime),
  // but they cover the case where the callback session was replaced since.
  const identity = await findResendIdentity()
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

  return null
}

/**
 * Leave for Resend's consent screen. Appwrite redirects back to the SMTP tab
 * with `userId` + `secret` (success) or `error` (failure) query params.
 */
export function startResendAuthorization(projectId: string): void {
  if (typeof window === 'undefined') {
    throw new Error('Resend authorization requires a browser')
  }
  const { success, failure } = buildResendOAuthUrls(
    window.location.origin,
    projectId,
  )
  const result = sdk.forConsole.account.createOAuth2Token({
    provider: OAuthProvider.Resend,
    success,
    failure,
    scopes: RESEND_OAUTH_SCOPES,
  })
  // In browsers the SDK navigates itself; outside of one it returns the URL.
  if (typeof result === 'string') {
    window.location.assign(result)
  }
}

/**
 * Finish the identity claim. Refuses to create the session when the token
 * belongs to another account (the callback ran without the console session),
 * because that would silently switch the signed-in user.
 */
export async function claimResendIdentity(params: {
  userId: string
  secret: string
  expectedUserId?: string | null
}): Promise<Models.Session> {
  if (params.expectedUserId && params.userId !== params.expectedUserId) {
    throw new ResendAccountMismatchError()
  }
  return await sdk.forConsole.account.createSession({
    userId: params.userId,
    secret: params.secret,
  })
}
