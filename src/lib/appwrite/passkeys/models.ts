/** A passkey on an account. */
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

/** A WebAuthn ceremony the browser completes before the matching verify call. */
export type PasskeyChallenge<Options> = {
  $id: string
  passkeyId: string
  expire: string
  publicKey: Options
}

export type PasskeyToken = {
  userId: string
  secret: string
  expire: string
}
