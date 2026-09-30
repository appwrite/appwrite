import { AppwriteException } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import type { Translator } from '@/lib/i18n/translate'

/** A passkey on the console account (not yet a model in the pinned SDK build). */
export type Passkey = {
  $id: string
  $createdAt: string
  $updatedAt: string
  name: string
  /** Empty until the passkey is first used to sign in. */
  accessedAt: string
  /** Synced across devices by a passkey provider, rather than bound to one device. */
  backedUp: boolean
}

export type PasskeyList = {
  total: number
  passkeys: Passkey[]
}

type PasskeyChallenge<Options> = {
  $id: string
  passkeyId: string
  expire: string
  publicKey: Options
}

type PasskeyToken = {
  userId: string
  secret: string
  expire: string
}

/**
 * The pinned @appwrite.io/console build has no passkey methods, so these call the
 * endpoints through the generic client. Replace them with the generated methods once
 * the SDK is regenerated.
 *
 * The 'content-type' header key must stay lowercase: the SDK switches on that exact key
 * to decide to JSON-encode the body, and a capitalised key silently sends no body at all.
 */
async function call<T>(
  method: 'get' | 'post' | 'put' | 'patch' | 'delete',
  path: string,
  body: Record<string, unknown> = {},
): Promise<T> {
  const client = sdk.forConsole.client
  return (await client.call(
    method,
    new URL(`${client.config.endpoint}${path}`),
    {
      'X-Appwrite-Project': client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    },
    body,
  )) as T
}

/** WebAuthn plus the JSON helpers the ceremonies rely on. */
export function isPasskeySupported(): boolean {
  if (typeof window === 'undefined') return false
  const credential = window.PublicKeyCredential
  return (
    typeof credential === 'function' &&
    typeof credential.parseRequestOptionsFromJSON === 'function' &&
    typeof credential.parseCreationOptionsFromJSON === 'function' &&
    typeof navigator.credentials?.get === 'function'
  )
}

/** The user dismissed the browser prompt, or it timed out without a choice. */
export function isPasskeyCancellation(error: unknown): boolean {
  return (
    error instanceof DOMException &&
    (error.name === 'NotAllowedError' || error.name === 'AbortError')
  )
}

export function isPasskeyReauthenticationError(error: unknown): boolean {
  return (
    error instanceof AppwriteException &&
    error.type === 'user_reauthentication_required'
  )
}

function asPublicKeyCredential(
  credential: Credential | null,
): PublicKeyCredential {
  if (credential instanceof PublicKeyCredential) return credential
  throw new DOMException('No passkey was selected.', 'NotAllowedError')
}

/**
 * Signs in with a passkey and returns a token to exchange for a session. No email is
 * needed: the browser offers every passkey it holds for the console.
 */
export async function createPasskeyToken(): Promise<PasskeyToken> {
  const challenge = await call<
    PasskeyChallenge<PublicKeyCredentialRequestOptionsJSON>
  >('post', '/account/tokens/passkey')
  const credential = asPublicKeyCredential(
    await navigator.credentials.get({
      publicKey: PublicKeyCredential.parseRequestOptionsFromJSON(
        challenge.publicKey,
      ),
    }),
  )
  return call<PasskeyToken>('put', '/account/tokens/passkey', {
    challengeId: challenge.$id,
    credential: credential.toJSON(),
  })
}

export function listPasskeys(): Promise<PasskeyList> {
  return call<PasskeyList>('get', '/account/passkeys')
}

/** Registers a new passkey on this device for the signed-in account. */
export async function createPasskey(name: string): Promise<Passkey> {
  const challenge = await call<
    PasskeyChallenge<PublicKeyCredentialCreationOptionsJSON>
  >('post', '/account/passkeys', { name })
  const credential = asPublicKeyCredential(
    await navigator.credentials.create({
      publicKey: PublicKeyCredential.parseCreationOptionsFromJSON(
        challenge.publicKey,
      ),
    }),
  )
  return call<Passkey>(
    'put',
    `/account/passkeys/${encodeURIComponent(challenge.passkeyId)}/verification`,
    { challengeId: challenge.$id, credential: credential.toJSON() },
  )
}

export function updatePasskey(passkeyId: string, name: string) {
  return call<Passkey>(
    'patch',
    `/account/passkeys/${encodeURIComponent(passkeyId)}`,
    { name },
  )
}

export async function deletePasskey(passkeyId: string): Promise<void> {
  await call<unknown>(
    'delete',
    `/account/passkeys/${encodeURIComponent(passkeyId)}`,
  )
}

/** A readable default name for a passkey created here, like "Chrome on macOS". */
export function defaultPasskeyName(): string {
  if (typeof navigator === 'undefined') return ''
  const agent = navigator.userAgent
  const browser = agent.includes('Edg/')
    ? 'Edge'
    : agent.includes('OPR/')
      ? 'Opera'
      : agent.includes('Firefox/')
        ? 'Firefox'
        : agent.includes('Chrome/') || agent.includes('CriOS/')
          ? 'Chrome'
          : agent.includes('Safari/')
            ? 'Safari'
            : ''
  const system =
    agent.includes('iPhone') || agent.includes('iPad')
      ? 'iOS'
      : agent.includes('Android')
        ? 'Android'
        : agent.includes('Mac OS X')
          ? 'macOS'
          : agent.includes('Windows')
            ? 'Windows'
            : agent.includes('CrOS')
              ? 'ChromeOS'
              : agent.includes('Linux')
                ? 'Linux'
                : ''
  if (browser && system) return `${browser} on ${system}`
  return browser || system
}

/** A friendly message for a failed passkey sign-in, or null when there is none. */
export function passkeySignInErrorMessage(
  error: unknown,
  t: Translator,
): string | null {
  if (error instanceof AppwriteException) {
    switch (error.type) {
      case 'user_passkey_invalid':
        return t(
          'That passkey is not recognised. It may have been removed from your account.',
        )
      case 'user_invalid_token':
        return t('The passkey sign-in expired. Please try again.')
      case 'user_auth_method_unsupported':
        return t('Passkey sign-in is not available right now.')
      case 'user_blocked':
        return t('This account has been blocked.')
    }
  }
  if (error instanceof DOMException) {
    return t('Your browser could not use a passkey. Please try again.')
  }
  return null
}

/** A friendly message for a failed passkey change, or null when there is none. */
export function passkeyErrorMessage(
  error: unknown,
  t: Translator,
): string | null {
  if (error instanceof AppwriteException) {
    switch (error.type) {
      case 'user_reauthentication_required':
        return t(
          'For your security, sign in again before changing your passkeys.',
        )
      case 'user_passkey_limit_exceeded':
        return t('You can add up to 10 passkeys. Remove one to add another.')
      case 'user_passkey_already_exists':
        return t('This passkey is already added to your account.')
      case 'user_passkey_invalid':
        return t('The passkey could not be verified. Please try again.')
      case 'user_invalid_token':
        return t('The request expired. Please try again.')
      case 'user_auth_method_unsupported':
        return t('Passkeys are not available right now.')
    }
  }
  if (error instanceof DOMException && error.name === 'InvalidStateError') {
    return t('This passkey is already added to your account.')
  }
  return null
}
