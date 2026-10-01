import { AppwriteException, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import type { Translator } from '@/lib/i18n/translate'

/** WebAuthn in this browser. The JSON helpers are optional: see the fallbacks below. */
export function isPasskeySupported(): boolean {
  if (typeof window === 'undefined') return false
  return (
    typeof window.PublicKeyCredential === 'function' &&
    typeof navigator.credentials?.get === 'function' &&
    typeof navigator.credentials?.create === 'function'
  )
}

/** Whether the browser can offer passkeys in the email field's autofill. */
export async function isPasskeyAutofillAvailable(): Promise<boolean> {
  if (!isPasskeySupported()) return false
  try {
    return (
      (await PublicKeyCredential.isConditionalMediationAvailable?.()) ?? false
    )
  } catch {
    return false
  }
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

function fromBase64Url(value: string): ArrayBuffer {
  const base64 = value.replace(/-/g, '+').replace(/_/g, '/')
  const binary = atob(base64.padEnd(Math.ceil(base64.length / 4) * 4, '='))
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)
  return bytes.buffer
}

function toBase64Url(buffer: ArrayBuffer | null): string | undefined {
  if (!buffer) return undefined
  let binary = ''
  for (const byte of new Uint8Array(buffer)) binary += String.fromCharCode(byte)
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

function parseDescriptors(
  descriptors: PublicKeyCredentialDescriptorJSON[] | undefined,
): PublicKeyCredentialDescriptor[] | undefined {
  return descriptors?.map((descriptor) => ({
    ...descriptor,
    type: descriptor.type as PublicKeyCredentialType,
    transports: descriptor.transports as AuthenticatorTransport[] | undefined,
    id: fromBase64Url(descriptor.id),
  }))
}

function parseRequestOptions(
  options: PublicKeyCredentialRequestOptionsJSON,
): PublicKeyCredentialRequestOptions {
  if (typeof PublicKeyCredential.parseRequestOptionsFromJSON === 'function') {
    return PublicKeyCredential.parseRequestOptionsFromJSON(options)
  }
  return {
    ...options,
    userVerification: options.userVerification as
      | UserVerificationRequirement
      | undefined,
    extensions: options.extensions as AuthenticationExtensionsClientInputs,
    challenge: fromBase64Url(options.challenge),
    allowCredentials: parseDescriptors(options.allowCredentials),
  }
}

function parseCreationOptions(
  options: PublicKeyCredentialCreationOptionsJSON,
): PublicKeyCredentialCreationOptions {
  if (typeof PublicKeyCredential.parseCreationOptionsFromJSON === 'function') {
    return PublicKeyCredential.parseCreationOptionsFromJSON(options)
  }
  return {
    ...options,
    attestation: options.attestation as AttestationConveyancePreference,
    authenticatorSelection:
      options.authenticatorSelection as AuthenticatorSelectionCriteria,
    extensions: options.extensions as AuthenticationExtensionsClientInputs,
    pubKeyCredParams:
      options.pubKeyCredParams as PublicKeyCredentialParameters[],
    challenge: fromBase64Url(options.challenge),
    user: { ...options.user, id: fromBase64Url(options.user.id) },
    excludeCredentials: parseDescriptors(options.excludeCredentials),
  }
}

function credentialToJSON(
  credential: PublicKeyCredential,
): PublicKeyCredentialJSON {
  if (typeof credential.toJSON === 'function') return credential.toJSON()
  const response = credential.response
  const base = {
    id: credential.id,
    rawId: toBase64Url(credential.rawId),
    type: credential.type,
    authenticatorAttachment: credential.authenticatorAttachment ?? undefined,
    clientExtensionResults: credential.getClientExtensionResults(),
  }
  if (response instanceof AuthenticatorAttestationResponse) {
    return {
      ...base,
      response: {
        clientDataJSON: toBase64Url(response.clientDataJSON),
        attestationObject: toBase64Url(response.attestationObject),
        transports: response.getTransports?.() ?? [],
      },
    }
  }
  const assertion = response as AuthenticatorAssertionResponse
  return {
    ...base,
    response: {
      clientDataJSON: toBase64Url(assertion.clientDataJSON),
      authenticatorData: toBase64Url(assertion.authenticatorData),
      signature: toBase64Url(assertion.signature),
      userHandle: toBase64Url(assertion.userHandle),
    },
  }
}

function asPublicKeyCredential(
  credential: Credential | null,
): PublicKeyCredential {
  if (credential instanceof PublicKeyCredential) return credential
  throw new DOMException('No passkey was selected.', 'NotAllowedError')
}

/**
 * Signs in with a passkey and returns a token to exchange for a session. With
 * `autofill`, the browser offers the passkeys in the email field's suggestions
 * instead of opening a prompt, and the request waits until one is picked or
 * `signal` aborts it. `onSelected` runs once the user has picked a passkey.
 */
export async function signInWithPasskey(
  options: {
    autofill?: boolean
    signal?: AbortSignal
    onSelected?: () => void
  } = {},
): Promise<Models.Token> {
  const account = sdk.forConsole.account
  const challenge = await account.createPasskeyToken()
  options.signal?.throwIfAborted()
  const credential = asPublicKeyCredential(
    await navigator.credentials.get({
      publicKey: parseRequestOptions(
        challenge.publicKey as PublicKeyCredentialRequestOptionsJSON,
      ),
      mediation: options.autofill ? 'conditional' : undefined,
      signal: options.signal,
    }),
  )
  options.onSelected?.()
  return account.updatePasskeyToken({
    challengeId: challenge.$id,
    credential: credentialToJSON(credential),
  })
}

/** Registers a new passkey on this device for the signed-in account. */
export async function registerPasskey(name: string): Promise<Models.Passkey> {
  const account = sdk.forConsole.account
  const challenge = await account.createPasskey({ name })
  const credential = asPublicKeyCredential(
    await navigator.credentials.create({
      publicKey: parseCreationOptions(
        challenge.publicKey as PublicKeyCredentialCreationOptionsJSON,
      ),
    }),
  )
  return account.updatePasskeyVerification({
    passkeyId: challenge.passkeyId,
    challengeId: challenge.$id,
    credential: credentialToJSON(credential),
  })
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
          'That passkey is not recognized. It may have been removed from your account.',
        )
      case 'user_invalid_token':
        return t('The passkey sign-in expired. Please try again.')
      case 'user_auth_method_unsupported':
        return t('Passkey sign-in is not available right now.')
      case 'user_blocked':
        return t('This account has been blocked.')
    }
  }
  if (error instanceof DOMException && error.name === 'SecurityError') {
    return t('Passkeys are not set up for this domain.')
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
  if (error instanceof DOMException && error.name === 'SecurityError') {
    return t('Passkeys are not set up for this domain.')
  }
  return null
}
