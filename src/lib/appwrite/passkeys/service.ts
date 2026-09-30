/**
 * Hand-written passkey endpoints: the pinned @appwrite.io/console build has no
 * passkey methods. Each method matches the name and parameters of the generated
 * `Account` or `Project` method, so callers move to `account.*` / `project.*` and
 * this folder is deleted once the SDK is regenerated.
 */

import type { Client, Models } from '@appwrite.io/console'

import type {
  Passkey,
  PasskeyChallenge,
  PasskeyList,
  PasskeyToken,
} from './models'

export class Passkeys {
  client: Client

  constructor(client: Client) {
    this.client = client
  }

  private call<T>(
    method: 'get' | 'post' | 'put' | 'patch' | 'delete',
    path: string,
    payload: Record<string, unknown> = {},
  ): Promise<T> {
    // The key must stay lowercase: the SDK only JSON-encodes the body for 'content-type'.
    return this.client.call(
      method,
      new URL(this.client.config.endpoint + path),
      { 'content-type': 'application/json', accept: 'application/json' },
      payload,
    ) as Promise<T>
  }

  /** Starts a passkey sign-in. No email is needed: any passkey the browser holds works. */
  createPasskeyToken(): Promise<
    PasskeyChallenge<PublicKeyCredentialRequestOptionsJSON>
  > {
    return this.call('post', '/account/tokens/passkey')
  }

  /** Verifies the signed challenge and returns a token to exchange for a session. */
  updatePasskeyToken(params: {
    challengeId: string
    credential: PublicKeyCredentialJSON
  }): Promise<PasskeyToken> {
    return this.call('put', '/account/tokens/passkey', params)
  }

  listPasskeys(): Promise<PasskeyList> {
    return this.call('get', '/account/passkeys')
  }

  /** Starts registering a passkey on the signed-in account. */
  createPasskey(params: {
    name?: string
  }): Promise<PasskeyChallenge<PublicKeyCredentialCreationOptionsJSON>> {
    return this.call('post', '/account/passkeys', params)
  }

  updatePasskeyVerification(params: {
    passkeyId: string
    challengeId: string
    credential: PublicKeyCredentialJSON
  }): Promise<Passkey> {
    const { passkeyId, ...payload } = params
    return this.call(
      'put',
      `/account/passkeys/${encodeURIComponent(passkeyId)}/verification`,
      payload,
    )
  }

  updatePasskey(params: { passkeyId: string; name: string }): Promise<Passkey> {
    return this.call(
      'patch',
      `/account/passkeys/${encodeURIComponent(params.passkeyId)}`,
      { name: params.name },
    )
  }

  deletePasskey(params: { passkeyId: string }): Promise<object> {
    return this.call(
      'delete',
      `/account/passkeys/${encodeURIComponent(params.passkeyId)}`,
    )
  }

  /** Send only the fields that changed. */
  updatePasskeyPolicy(params: {
    rpId?: string
    origins?: string[]
  }): Promise<Models.Project> {
    return this.call('patch', '/project/policies/passkey', params)
  }
}
