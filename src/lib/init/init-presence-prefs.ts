export const INIT_PRESENCE_PREFS_KEY_PREFIX = 'console.init.presence'

/** @deprecated Session-only legacy keys; migrated on read. */
const LEGACY_PARTICIPANT_ONLINE_SESSION_KEY = 'console.init.participantOnline'
/** @deprecated Session-only legacy keys; migrated on read. */
const LEGACY_IDENTITY_VISIBLE_SESSION_KEY = 'console.init.participantIdentityVisible'

export interface InitPresencePrefs {
  /** When true, others see the signed-in user's name, photo, and country. */
  identityVisible?: boolean
  /** When false, the user is hidden from the online list (still connected). */
  participantOnline?: boolean
}

export function getInitPresencePrefsAccountKey(eventId: string): string {
  return `${INIT_PRESENCE_PREFS_KEY_PREFIX}.${eventId}`
}

export function getInitPresencePrefsStorageKey(
  eventId: string,
  userId: string,
): string {
  return `${INIT_PRESENCE_PREFS_KEY_PREFIX}.v1.${eventId}.${userId}`
}

export function parseInitPresencePrefs(value: unknown): InitPresencePrefs | null {
  if (!value || typeof value !== 'object') return null
  const record = value as Record<string, unknown>
  const identityVisible =
    typeof record.identityVisible === 'boolean' ? record.identityVisible : undefined
  const participantOnline =
    typeof record.participantOnline === 'boolean' ? record.participantOnline : undefined
  if (identityVisible === undefined && participantOnline === undefined) return null
  return {
    ...(identityVisible !== undefined ? { identityVisible } : {}),
    ...(participantOnline !== undefined ? { participantOnline } : {}),
  }
}

export function readInitPresencePrefsFromAccountPrefs(
  accountPrefs: Record<string, unknown> | undefined,
  eventId: string,
): InitPresencePrefs | null {
  if (!accountPrefs) return null
  const raw = accountPrefs[getInitPresencePrefsAccountKey(eventId)]
  if (typeof raw === 'string') {
    try {
      return parseInitPresencePrefs(JSON.parse(raw))
    } catch {
      return null
    }
  }
  return parseInitPresencePrefs(raw)
}

export function readInitPresencePrefsFromStorage(
  eventId: string,
  userId: string,
): InitPresencePrefs | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = window.localStorage.getItem(
      getInitPresencePrefsStorageKey(eventId, userId),
    )
    if (!raw) return null
    return parseInitPresencePrefs(JSON.parse(raw))
  } catch {
    return null
  }
}

export function writeInitPresencePrefsToStorage(
  eventId: string,
  userId: string,
  prefs: InitPresencePrefs,
): void {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.setItem(
      getInitPresencePrefsStorageKey(eventId, userId),
      JSON.stringify(prefs),
    )
  } catch {
    /* private mode */
  }
}

export function mergeInitPresencePrefsIntoAccountPrefs(
  existingPrefs: Record<string, unknown> | undefined,
  eventId: string,
  presencePrefs: InitPresencePrefs,
): Record<string, unknown> {
  return {
    ...(existingPrefs ?? {}),
    [getInitPresencePrefsAccountKey(eventId)]: JSON.stringify(presencePrefs),
  }
}

function readLegacySessionIdentityVisible(): boolean | undefined {
  if (typeof window === 'undefined') return undefined
  try {
    const stored = window.sessionStorage.getItem(LEGACY_IDENTITY_VISIBLE_SESSION_KEY)
    if (stored === 'true') return true
    if (stored === 'false') return false
  } catch {
    /* private mode */
  }
  return undefined
}

function readLegacySessionParticipantOnline(): boolean | undefined {
  if (typeof window === 'undefined') return undefined
  try {
    const stored = window.sessionStorage.getItem(LEGACY_PARTICIPANT_ONLINE_SESSION_KEY)
    if (stored === 'true') return true
    if (stored === 'false') return false
  } catch {
    /* private mode */
  }
  return undefined
}

function resolveStoredInitPresencePrefs(
  accountPrefs: Record<string, unknown> | undefined,
  eventId: string,
  userId: string,
): InitPresencePrefs | null {
  return (
    readInitPresencePrefsFromAccountPrefs(accountPrefs, eventId) ??
    readInitPresencePrefsFromStorage(eventId, userId)
  )
}

/** Resolve whether the signed-in user shows their real identity on Init. */
export function resolveInitIdentityVisiblePreference(
  accountPrefs: Record<string, unknown> | undefined,
  eventId: string,
  userId: string,
  identityHiddenByDefault: boolean,
): boolean {
  const stored = resolveStoredInitPresencePrefs(accountPrefs, eventId, userId)
  if (typeof stored?.identityVisible === 'boolean') {
    return stored.identityVisible
  }

  const legacy = readLegacySessionIdentityVisible()
  if (typeof legacy === 'boolean') {
    return legacy
  }

  return !identityHiddenByDefault
}

/** Resolve whether the signed-in user appears in the Init online list. */
export function resolveInitParticipantOnlinePreference(
  accountPrefs: Record<string, unknown> | undefined,
  eventId: string,
  userId: string,
): boolean {
  const stored = resolveStoredInitPresencePrefs(accountPrefs, eventId, userId)
  if (typeof stored?.participantOnline === 'boolean') {
    return stored.participantOnline
  }

  const legacy = readLegacySessionParticipantOnline()
  if (typeof legacy === 'boolean') {
    return legacy
  }

  return true
}

export function patchInitPresencePrefs(
  current: InitPresencePrefs,
  patch: Partial<InitPresencePrefs>,
): InitPresencePrefs {
  return { ...current, ...patch }
}
