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
  /** Organization members and role management (owner, developer, editor, analyst, billing) */
  orgRoles: boolean
  /** Appwrite Cloud system status (status.appwrite.online) */
  systemStatus: boolean
  /** Console account MFA (enable/disable, TOTP, email, SMS, recovery codes) */
  accountMfa: boolean
  /** Console account identities (OAuth providers linked to the account) */
  accountIdentities: boolean
  /** Organization compliance, OAuth apps, and org API keys (cloud only) */
  orgCloudSettings: boolean
  /** In-app AI assistant chat panel and header button */
  aiAssistant: boolean
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
      orgCloudSettings: true,
      aiAssistant: true,
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
      orgCloudSettings: false,
      aiAssistant: false,
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

const DEBUG_PROFILE_KEY = 'debug:consoleProfile'

function getDebugProfileOverride(): ConsoleProfileId | null {
  if (typeof window === 'undefined') return null
  const stored = localStorage.getItem(DEBUG_PROFILE_KEY)
  if (stored && VALID_PROFILE_IDS.includes(stored as ConsoleProfileId)) {
    return stored as ConsoleProfileId
  }
  return null
}

/**
 * Returns the currently active console profile ID.
 * In debug mode, localStorage override takes precedence over env var.
 */
export function getActiveProfileId(): ConsoleProfileId {
  const override = getDebugProfileOverride()
  if (override) return override
  return getProfileFromEnv()
}

/**
 * Returns the currently active console profile.
 */
export function getActiveProfile(): ConsoleProfile {
  return CONSOLE_PROFILES[getActiveProfileId()]
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
 * Dispatches CONSOLE_PROFILE_CHANGE_EVENT so UI can re-render.
 */
export function setDebugProfileOverride(profileId: ConsoleProfileId | null) {
  if (typeof window === 'undefined') return
  if (profileId) {
    localStorage.setItem(DEBUG_PROFILE_KEY, profileId)
  } else {
    localStorage.removeItem(DEBUG_PROFILE_KEY)
  }
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
