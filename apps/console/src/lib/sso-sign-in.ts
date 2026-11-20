import { z } from 'zod'
import { resolvePostAuthRedirect } from '@/lib/post-auth-navigation'

/**
 * Where `/sso` returns the person after signing in: a Console path from its
 * `redirect`, checked the way `/sign-in` checks its own (one leading `/`, no
 * scheme or host, not an auth or marketing page). Anything else is ignored,
 * and the API then lands the person in the provider's organization.
 */
export function ssoReturnPath(redirect: unknown): string | undefined {
  return typeof redirect === 'string'
    ? resolvePostAuthRedirect(redirect)
    : undefined
}

/**
 * The success and failure URLs for a sign-in through a provider. `success` is
 * sent only to return to a page; the failure URL keeps that page, so a retry
 * after a failed sign-in still returns there.
 */
export function ssoSignInURLs(
  origin: string,
  redirect: unknown,
): { success?: string; failure: string } {
  const returnPath = ssoReturnPath(redirect)
  const failure = new URL('/sso', origin)
  if (returnPath) failure.searchParams.set('redirect', returnPath)
  return {
    success: returnPath ? `${origin}${returnPath}` : undefined,
    failure: failure.toString(),
  }
}

/** The longest email the sign-in accepts (RFC 5321 path limit). */
export const SSO_EMAIL_MAX_LENGTH = 254

const workEmail = z.string().trim().email().max(SSO_EMAIL_MAX_LENGTH)

/** Whether the work email is worth asking the server about. */
export function isValidWorkEmail(value: string): boolean {
  return workEmail.safeParse(value).success
}

export const SSO_FAILURE_MESSAGES = {
  misconfigured:
    "Single sign-on for your organization isn't set up correctly. Contact your administrator.",
  domainNotVerified:
    "Your identity provider returned an email that isn't on a domain your organization verified. Sign in with your work email account.",
  emailMissing:
    "Your identity provider didn't share your email address. Ask your administrator to release the email to Appwrite.",
  stateInvalid:
    'This sign-in was started in another browser or took too long. Start again.',
  generic:
    'Single sign-on could not finish. Start again, or contact your administrator if it keeps happening.',
} as const

/**
 * Server messages shown as they are: Cloud's refusals (`SingleSignOn::refusal`)
 * are already written for the person signing in.
 */
const KNOWN_SERVER_MESSAGES = [
  'This identity provider is not available.',
  'Single sign-on could not finish. Start again from the sign-in page.',
  'Single sign-on for your organization is not working right now. Contact your administrator.',
] as const

function parseFailure(
  error: unknown,
): { type?: string; message?: string } | null {
  if (error === undefined || error === null || error === '') return null
  let value: unknown = error
  if (typeof value === 'string') {
    try {
      value = JSON.parse(value)
    } catch {
      return {}
    }
  }
  if (!value || typeof value !== 'object') return {}
  const { type, message } = value as { type?: unknown; message?: unknown }
  return {
    type: typeof type === 'string' ? type : undefined,
    message: typeof message === 'string' ? message : undefined,
  }
}

/**
 * The English message for the `?error=` a failed sign-in returns with, or null
 * when there is none. Never text from the URL: anyone can put any words there,
 * so the message is chosen from the error's type, and from a few exact server
 * messages where the type alone is not enough.
 */
export function ssoFailureMessage(error: unknown): string | null {
  const failure = parseFailure(error)
  if (!failure) return null
  const { type, message = '' } = failure

  const known = KNOWN_SERVER_MESSAGES.find((text) => text === message)
  if (known) return known

  if (type === 'user_oauth2_state_invalid') {
    return SSO_FAILURE_MESSAGES.stateInvalid
  }
  if (type === 'user_oauth2_provider_failure') {
    // CE puts the identity provider's error code in the message.
    const code = (name: string) => new RegExp(`\\b${name}\\b`).test(message)
    if (code('email_domain_not_verified')) {
      return SSO_FAILURE_MESSAGES.domainNotVerified
    }
    if (code('email_missing')) return SSO_FAILURE_MESSAGES.emailMissing
    // The person turned the sign-in down at their identity provider.
    if (code('access_denied')) {
      return SSO_FAILURE_MESSAGES.generic
    }
    return SSO_FAILURE_MESSAGES.misconfigured
  }
  return SSO_FAILURE_MESSAGES.generic
}
