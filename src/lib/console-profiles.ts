/**
 * Console profile configuration for different Appwrite deployments.
 * Each profile defines which features are available.
 *
 * Use VITE_CONSOLE_PROFILE env var to select the profile (default: 'cloud').
 * In debug mode, the profile can be overridden via the Debug menu.
 */

export type ConsoleProfileId = 'cloud' | 'self-hosted'

export type ConsoleProfileFeatures = {
  /** Billing and subscription management */
  billing: boolean
  /** Organization-level custom domains (DNS, verification, buy domain) */
  domains: boolean
  /** Project usage statistics, charts, and project overview main chart */
  usageStats: boolean
  /** Activity logs and audit trail */
  activity: boolean
  /** Organization role selection (developer, editor, analyst, billing). When false, all members are owners and role UI is hidden. */
  orgRoles: boolean
  /** Appwrite Cloud system status (status.appwrite.online) */
  systemStatus: boolean
  /** Console account MFA (enable/disable, TOTP, email, SMS, recovery codes) */
  accountMfa: boolean
  /** Console account identities (OAuth providers linked to the account) */
  accountIdentities: boolean
  /** Organization compliance (DPA, BAA, SOC 2) */
  compliance: boolean
  /** Organization OAuth apps */
  oauthApps: boolean
  /** Organization API keys */
  orgApiKeys: boolean
  /** In-app AI assistant chat panel and header button */
  aiAssistant: boolean
  /** Database backup policies and archives */
  databaseBackups: boolean
  /** Database analytics and insights */
  databaseInsights: boolean
  /** Global: dedicated DBs support (wizard + specs). When true, use fullscreen create wizard and show spec upgrade for supported DB types. */
  dedicatedDbsSupport: boolean
  /** Dedicated DBs support for Tables DB: show spec selector in wizard and "Upgrade database specs" in rows view. */
  dedicatedDbsTablesDB: boolean
  /** Dedicated DBs support for Documents DB. */
  dedicatedDbsDocumentsDB: boolean
  /** Dedicated DBs support for Vectors DB. */
  dedicatedDbsVectorsDB: boolean
  /** Require console user email verification after signup (cloud: redirect to verify-email page; self-hosted: skip). */
  userVerification: boolean
}

export type ConsoleProfile = {
  id: ConsoleProfileId
  label: string
  description: string
  features: ConsoleProfileFeatures
}

export const CONSOLE_PROFILES: Record<ConsoleProfileId, ConsoleProfile> = {
  cloud: {
    id: 'cloud',
    label: 'Cloud',
    description: 'Appwrite Cloud - full feature set',
    features: {
      billing: true,
      domains: true,
      usageStats: true,
      activity: true,
      orgRoles: true,
      systemStatus: true,
      accountMfa: true,
      accountIdentities: true,
      compliance: true,
      oauthApps: true,
      orgApiKeys: true,
      aiAssistant: true,
      databaseBackups: true,
      databaseInsights: true,
      dedicatedDbsSupport: true,
      dedicatedDbsTablesDB: true,
      dedicatedDbsDocumentsDB: false,
      dedicatedDbsVectorsDB: false,
      userVerification: true,
    },
  },
  'self-hosted': {
    id: 'self-hosted',
    label: 'Self-hosted',
    description: 'Self-hosted Appwrite - cloud-only features disabled',
    features: {
      billing: false,
      domains: false,
      usageStats: false,
      activity: false,
      orgRoles: false,
      systemStatus: false,
      accountMfa: false,
      accountIdentities: false,
      compliance: false,
      oauthApps: false,
      orgApiKeys: false,
      aiAssistant: false,
      databaseBackups: false,
      databaseInsights: false,
      dedicatedDbsSupport: false,
      dedicatedDbsTablesDB: false,
      dedicatedDbsDocumentsDB: false,
      dedicatedDbsVectorsDB: false,
      userVerification: false,
    },
  },
}

const VALID_PROFILE_IDS: ConsoleProfileId[] = ['cloud', 'self-hosted']

function getProfileFromEnv(): ConsoleProfileId {
  if (typeof import.meta === 'undefined' || !import.meta.env) {
    return 'cloud'
  }
  const env = (import.meta.env?.VITE_CONSOLE_PROFILE as string) || 'cloud'
  const normalized = env.toLowerCase().trim().replace(/\s+/g, '-')
  if (VALID_PROFILE_IDS.includes(normalized as ConsoleProfileId)) {
    return normalized as ConsoleProfileId
  }
  return 'cloud'
}

/** Store the full profile object (actual value), not just the id. */
const DEBUG_PROFILE_KEY = 'debug:consoleProfile'

function getStoredProfile(): ConsoleProfile | null {
  if (typeof window === 'undefined') return null
  const stored = localStorage.getItem(DEBUG_PROFILE_KEY)
  if (!stored?.trim()) return null
  try {
    const parsed = JSON.parse(stored) as unknown
    if (
      parsed &&
      typeof parsed === 'object' &&
      'id' in parsed &&
      VALID_PROFILE_IDS.includes((parsed as ConsoleProfile).id) &&
      'features' in parsed &&
      typeof (parsed as ConsoleProfile).features === 'object'
    ) {
      return parsed as ConsoleProfile
    }
  } catch {
    // ignore
  }
  // Legacy: stored value was just the id string
  if (VALID_PROFILE_IDS.includes(stored as ConsoleProfileId)) {
    return CONSOLE_PROFILES[stored as ConsoleProfileId]
  }
  return null
}

/**
 * Returns the currently active console profile ID.
 * In debug mode, localStorage override (stored profile value) takes precedence over env var.
 */
export function getActiveProfileId(): ConsoleProfileId {
  const stored = getStoredProfile()
  if (stored) return stored.id
  return getProfileFromEnv()
}

/**
 * Returns the currently active console profile (stored value when set, else from env).
 * Stored profile features are merged with the canonical profile for that id so new
 * feature keys added later get correct defaults (e.g. after localStorage was set).
 */
export function getActiveProfile(): ConsoleProfile {
  const stored = getStoredProfile()
  if (!stored) return CONSOLE_PROFILES[getProfileFromEnv()]
  const canonical = CONSOLE_PROFILES[stored.id]
  const mergedFeatures = { ...canonical.features, ...stored.features } as ConsoleProfileFeatures
  return { ...stored, features: mergedFeatures }
}

/**
 * Returns feature flags for the active profile.
 */
export function getActiveProfileFeatures(): ConsoleProfileFeatures {
  return getActiveProfile().features
}

/**
 * Check if a specific feature is enabled.
 */
export function isFeatureEnabled(
  feature: keyof ConsoleProfileFeatures,
): boolean {
  return getActiveProfileFeatures()[feature]
}

// Event for profile changes (used when debug override changes)
export const CONSOLE_PROFILE_CHANGE_EVENT = 'consoleProfileChange'

/**
 * Set the profile override (debug mode only).
 * Stores the full profile object (actual value) in localStorage.
 * Dispatches CONSOLE_PROFILE_CHANGE_EVENT so UI can re-render.
 */
export function setDebugProfileOverride(profileId: ConsoleProfileId | null) {
  if (typeof window === 'undefined') return
  if (profileId) {
    const profile = CONSOLE_PROFILES[profileId]
    localStorage.setItem(DEBUG_PROFILE_KEY, JSON.stringify(profile))
  } else {
    localStorage.removeItem(DEBUG_PROFILE_KEY)
  }
  window.dispatchEvent(new CustomEvent(CONSOLE_PROFILE_CHANGE_EVENT))
}

/**
 * Override a single feature flag for the current profile (debug mode only).
 * Creates or updates the stored profile so the override is persisted.
 * Stored profile only keeps override keys in features; getActiveProfile merges with canonical.
 * Dispatches CONSOLE_PROFILE_CHANGE_EVENT so UI re-renders.
 */
export function setDebugProfileFeatureOverride<K extends keyof ConsoleProfileFeatures>(
  key: K,
  value: ConsoleProfileFeatures[K],
) {
  if (typeof window === 'undefined') return
  const stored = getStoredProfile()
  const profileId = stored?.id ?? getProfileFromEnv()
  const canonical = CONSOLE_PROFILES[profileId]
  const nextOverrideFeatures = {
    ...(stored?.features ?? {}),
    [key]: value,
  } as Partial<ConsoleProfileFeatures>
  const nextStored = {
    ...canonical,
    id: profileId,
    features: nextOverrideFeatures,
  }
  localStorage.setItem(DEBUG_PROFILE_KEY, JSON.stringify(nextStored))
  window.dispatchEvent(new CustomEvent(CONSOLE_PROFILE_CHANGE_EVENT))
}

/**
 * Subscribe to profile changes (e.g. when debug override changes).
 */
export function subscribeToProfileChange(
  callback: (profileId: ConsoleProfileId) => void,
) {
  if (typeof window === 'undefined') return () => undefined

  const handler = () => callback(getActiveProfileId())
  const storageHandler = (e: StorageEvent) => {
    if (e.key === DEBUG_PROFILE_KEY) handler()
  }

  window.addEventListener(CONSOLE_PROFILE_CHANGE_EVENT, handler as EventListener)
  window.addEventListener('storage', storageHandler)

  return () => {
    window.removeEventListener(
      CONSOLE_PROFILE_CHANGE_EVENT,
      handler as EventListener,
    )
    window.removeEventListener('storage', storageHandler)
  }
}
