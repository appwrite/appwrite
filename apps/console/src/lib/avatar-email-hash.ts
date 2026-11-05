import { useEffect, useState } from 'react'

/**
 * SHA-256 hex of a lowercase, trimmed email for `avatars.getPhoto({ emailHash })`.
 * Pass the hash only - never the raw address - through presence metadata and URLs.
 */
export async function hashEmailForAvatar(email: string): Promise<string | undefined> {
  const normalized = email.trim().toLowerCase()
  if (!normalized || !crypto?.subtle) return undefined

  const msgBuffer = new TextEncoder().encode(normalized)
  const hashBuffer = await crypto.subtle.digest('SHA-256', msgBuffer)
  const hashArray = Array.from(new Uint8Array(hashBuffer))
  return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('')
}

/** Accept only a full SHA-256 hex digest from untrusted presence metadata. */
export function parseAvatarEmailHash(value: unknown): string | undefined {
  if (typeof value !== 'string') return undefined
  const hash = value.trim().toLowerCase()
  if (!/^[a-f0-9]{64}$/.test(hash)) return undefined
  return hash
}

/** SHA-256 `emailHash` derived from an account email for `avatars.getPhoto`. */
export function useAvatarEmailHash(email: string | null | undefined): string | undefined {
  const [emailHash, setEmailHash] = useState<string | undefined>(undefined)

  useEffect(() => {
    const value = email?.trim()
    if (!value) {
      setEmailHash(undefined)
      return
    }

    let cancelled = false
    void hashEmailForAvatar(value).then((hash) => {
      if (!cancelled) setEmailHash(hash)
    })

    return () => {
      cancelled = true
    }
  }, [email])

  return emailHash
}
