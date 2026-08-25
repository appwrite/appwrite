import { getDebugEndpointBaseUrl } from '@/lib/debug-endpoint'
import { getRuntimeConfig } from '@/lib/runtime-config'
import { resolveAppwriteEndpointFallback } from '@/lib/runtime-config-shared'

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
  /** Organization-level custom domains (DNS, verification, buy domain, transfer in) */
  domains: boolean
  /** Organization marketplace (browse and publish apps) */
  marketplace: boolean
  /** Project usage statistics, charts, and project overview main chart */
  usageStats: boolean
  /** Activity logs and audit trail */
  activity: boolean
  /** Init launch event page, promo banner, ticket, and related CTAs */
  init: boolean
  /** Marketing home page and related public marketing routes */
  marketing: boolean
  /** Partner documentation hub and /docs/partners routes */
  partnersDocs: boolean
  /** Multiple organizations and organization switching */
  multiTenancy: boolean
  /** Organization role selection (developer, editor, analyst, billing). When false, all members are owners and role UI is hidden. */
  orgRoles: boolean
  /** Appwrite Cloud system status (status.appwrite.online) */ // pragma: allowlist secret
  systemStatus: boolean
  /** Console account MFA (enable/disable, TOTP, email, SMS, recovery codes) */
  accountMfa: boolean
  /** Console account identities (OAuth providers linked to the account) */
  accountIdentities: boolean
  /** Organization compliance (DPA, BAA, SOC 2) */
  compliance: boolean
  /** Organization OAuth apps */
  oauthApps: boolean
  /** Project-level OAuth2 authorization server settings */
  oauth2Server: boolean
  /** Organization API keys */
  orgApiKeys: boolean
  /** In-app AI agent (chat panel, header button, /agent routes, and Agent docs) */
  agent: boolean
  /** Console notifications center (header bell and inbox popover) */
  notifications: boolean
  /** Database backup policies and archives */
  databaseBackups: boolean
  /** Global: dedicated DBs support (wizard + specs). When true, use fullscreen create wizard and show spec upgrade for supported DB types. */
  dedicatedDbsSupport: boolean
  /** Dedicated DBs support for Documents DB. */
  dedicatedDbsDocumentsDB: boolean
  /** Dedicated DBs support for Vectors DB. */
  dedicatedDbsVectorsDB: boolean
  /** Native Postgres databases via the `postgresql` SDK service. */
  nativeDbsPostgres: boolean
  /** Native MySQL databases via the `mysql` SDK service. */
  nativeDbsMySQL: boolean
  /** Native MongoDB databases via the `mongo` SDK service. */
  nativeDbsMongo: boolean
  /** Multi-region support (region picker/labels in project UX). */
  multiRegion: boolean
  /**
   * Edge network for Functions/Sites custom domains. When enabled, the CNAME
   * target shown in Add/Verify domain flows is the edge network host
   * (`appwrite.network`) instead of the project endpoint host. // pragma: allowlist secret
   */
  edgeNetwork: boolean
  /** Require console user email verification after signup (cloud: redirect to verify-email page; self-hosted: skip). */
  userVerification: boolean
  /** Project Firewall (rules, analytics, logs) under Protect */
  firewall: boolean
  /** Account affiliates program (referral codes, rewards, credit claims) */
  affiliates: boolean
  /**
   * Cookie consent banner (locale-gated GDPR prompt, footer cookie settings).
   * When false, consent is treated as granted and tracking scripts may load.
   */
  cookieBanner: boolean
  /**
   * Draft blog posts (`draft: true` frontmatter). When true, the blog index
   * lists every draft above "Explore by topic" and draft post pages resolve
   * instead of 404ing. Preview-only: keep off for public deployments.
   */
  blogDrafts: boolean
}

/** Short labels for debug UI (profile comparison, etc.). */
export const CONSOLE_PROFILE_FEATURE_LABELS: Record<
  keyof ConsoleProfileFeatures,
  string
> = {
  billing: 'Billing',
  domains: 'Domains',
  marketplace: 'Marketplace',
  usageStats: 'Usage stats',
  activity: 'Activity',
  init: 'Init',
  marketing: 'Marketing',
  partnersDocs: 'Partners docs',
  multiTenancy: 'Multi-tenancy',
  orgRoles: 'Org roles',
  systemStatus: 'System status',
  accountMfa: 'Account MFA',
  accountIdentities: 'Account identities',
  compliance: 'Compliance',
  oauthApps: 'OAuth apps',
  oauth2Server: 'OAuth2 server',
  orgApiKeys: 'Org API keys',
  agent: 'Agent',
  notifications: 'Notifications',
  databaseBackups: 'Database backups',
  dedicatedDbsSupport: 'Dedicated DBs (global)',
  dedicatedDbsDocumentsDB: 'Dedicated DBs: Documents',
  dedicatedDbsVectorsDB: 'Dedicated DBs: Vectors',
  nativeDbsPostgres: 'Native DBs: PostgreSQL',
  nativeDbsMySQL: 'Native DBs: MySQL',
  nativeDbsMongo: 'Native DBs: MongoDB',
  multiRegion: 'Multi-region',
  edgeNetwork: 'Edge network',
  userVerification: 'User verification',
  firewall: 'Firewall',
  affiliates: 'Affiliates',
  cookieBanner: 'Cookie banner',
  blogDrafts: 'Blog drafts',
}

export type ConsoleProfile = {
  id: ConsoleProfileId
  label: string
  description: string
  features: ConsoleProfileFeatures
}

type StoredConsoleProfile = Omit<ConsoleProfile, 'features'> & {
  features: Partial<ConsoleProfileFeatures>
}

export const CONSOLE_PROFILES: Record<ConsoleProfileId, ConsoleProfile> = {
  cloud: {
    id: 'cloud',
    label: 'Cloud',
    description: 'Appwrite Cloud - full feature set',
    features: {
      billing: true,
      domains: true,
      marketplace: false,
      usageStats: true,
      activity: true,
      init: true,
      marketing: true,
      partnersDocs: false,
      multiTenancy: true,
      orgRoles: true,
      systemStatus: true,
      accountMfa: true,
      accountIdentities: true,
      compliance: true,
      oauthApps: false,
      oauth2Server: true,
      orgApiKeys: false,
      agent: true,
      notifications: false,
      databaseBackups: true,
      dedicatedDbsSupport: true,
      dedicatedDbsDocumentsDB: false,
      dedicatedDbsVectorsDB: false,
      nativeDbsPostgres: true,
      nativeDbsMySQL: true,
      nativeDbsMongo: false,
      multiRegion: true,
      edgeNetwork: true,
      userVerification: true,
      firewall: true,
      affiliates: true,
      cookieBanner: true,
      blogDrafts: false,
    },
  },
  'self-hosted': {
    id: 'self-hosted',
    label: 'Self-hosted',
    description: 'Self-hosted Appwrite - cloud-only features disabled',
    features: {
      billing: false,
      domains: false,
      marketplace: false,
      usageStats: false,
      activity: false,
      init: false,
      marketing: false,
      partnersDocs: false,
      multiTenancy: false,
      orgRoles: false,
      systemStatus: false,
      accountMfa: false,
      accountIdentities: false,
      compliance: false,
      oauthApps: false,
      oauth2Server: false,
      orgApiKeys: false,
      agent: false,
      notifications: false,
      databaseBackups: false,
      dedicatedDbsSupport: false,
      dedicatedDbsDocumentsDB: false,
      dedicatedDbsVectorsDB: false,
      nativeDbsPostgres: false,
      nativeDbsMySQL: false,
      nativeDbsMongo: false,
      multiRegion: false,
      edgeNetwork: false,
      userVerification: false,
      firewall: false,
      affiliates: false,
      cookieBanner: false,
      blogDrafts: false,
    },
  },
}

const VALID_PROFILE_IDS: ConsoleProfileId[] = ['cloud', 'self-hosted']

function isCloudEndpoint(url: string): boolean {
  try {
    const host = new URL(url).hostname.toLowerCase()
    return host === 'cloud.appwrite.io' || host.endsWith('.cloud.appwrite.io') // pragma: allowlist secret
  } catch {
    return false
  }
}

function detectProfileFromEndpoint(): ConsoleProfileId {
  if (typeof window !== 'undefined') {
    const debugBase = getDebugEndpointBaseUrl()
    if (debugBase) {
      return isCloudEndpoint(debugBase) ? 'cloud' : 'self-hosted'
    }
  }

  const envEndpoint = getRuntimeConfig().appwriteEndpoint // pragma: allowlist secret
  if (envEndpoint.trim()) {
    return isCloudEndpoint(envEndpoint) ? 'cloud' : 'self-hosted'
  }

  if (typeof window !== 'undefined') {
    const config = getRuntimeConfig()
    return isCloudEndpoint(
      resolveAppwriteEndpointFallback(config.consoleProfile, window.location),
    )
      ? 'cloud'
      : 'self-hosted'
  }

  return 'cloud'
}

function getProfileFromEnv(): ConsoleProfileId {
  if (typeof import.meta === 'undefined' || !import.meta.env) {
    return detectProfileFromEndpoint()
  }
  const env = getRuntimeConfig().consoleProfile
  const normalized = env?.toLowerCase().trim().replace(/\s+/g, '-')
  if (
    normalized &&
    VALID_PROFILE_IDS.includes(normalized as ConsoleProfileId)
  ) {
    return normalized as ConsoleProfileId
  }
  return detectProfileFromEndpoint()
}

/**
 * Profile id from VITE_CONSOLE_PROFILE only (defaults to cloud).
 * Avoids endpoint detection so prerendered marketing HTML matches hydration.
 */
export function getEnvProfileId(): ConsoleProfileId {
  const env = getRuntimeConfig().consoleProfile
  const normalized = env?.toLowerCase().trim().replace(/\s+/g, '-')
  if (
    normalized &&
    VALID_PROFILE_IDS.includes(normalized as ConsoleProfileId)
  ) {
    return normalized as ConsoleProfileId
  }
  return 'cloud'
}

/** Feature flags from VITE_CONSOLE_PROFILE only (no localStorage or endpoint fallback). */
export function getEnvProfileFeatures(): ConsoleProfileFeatures {
  const profileId = getEnvProfileId()
  return applyCloudOnlyFeatureGates(
    profileId,
    CONSOLE_PROFILES[profileId].features,
  )
}

/** Store the full profile object (actual value), not just the id. */
const DEBUG_PROFILE_KEY = 'debug:consoleProfile'
/**
 * Cookie mirror of {@link DEBUG_PROFILE_KEY} for client-side persistence across
 * tabs. Do not read this via `@tanstack/react-start/server` from this module —
 * that creates an SSR circular import with the router bootstrap.
 */
const DEBUG_PROFILE_COOKIE = 'debug_console_profile'
const DEBUG_PROFILE_COOKIE_MAX_AGE_SECONDS = 60 * 60 * 24 * 365

/** Map legacy stored feature keys onto the current schema. */
function migrateStoredProfileFeatures(
  features: Partial<ConsoleProfileFeatures> & Record<string, unknown>,
): Partial<ConsoleProfileFeatures> {
  const next = { ...features } as Partial<ConsoleProfileFeatures> &
    Record<string, unknown>
  if (!('agent' in next) && typeof next.aiAssistant === 'boolean') {
    next.agent = next.aiAssistant
  }
  delete next.aiAssistant
  delete next.executionLogs
  return next
}

function parseStoredProfileRaw(
  stored: string | null | undefined,
): StoredConsoleProfile | null {
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
      const profile = parsed as StoredConsoleProfile
      return {
        ...profile,
        features: migrateStoredProfileFeatures(
          profile.features as Partial<ConsoleProfileFeatures> &
            Record<string, unknown>,
        ) as ConsoleProfileFeatures,
      }
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

function syncDebugProfileCookie(profile: StoredConsoleProfile | null) {
  if (typeof document === 'undefined') return
  const secure = window.location.protocol === 'https:' ? '; Secure' : ''
  if (!profile) {
    document.cookie = `${DEBUG_PROFILE_COOKIE}=; path=/; max-age=0; SameSite=Lax${secure}`
    return
  }
  const payload = JSON.stringify({
    id: profile.id,
    features: profile.features ?? {},
  })
  document.cookie = `${DEBUG_PROFILE_COOKIE}=${encodeURIComponent(payload)}; path=/; max-age=${DEBUG_PROFILE_COOKIE_MAX_AGE_SECONDS}; SameSite=Lax${secure}`
}

function persistDebugProfile(profile: StoredConsoleProfile | null) {
  if (typeof window === 'undefined') return
  if (profile) {
    localStorage.setItem(DEBUG_PROFILE_KEY, JSON.stringify(profile))
  } else {
    localStorage.removeItem(DEBUG_PROFILE_KEY)
  }
  syncDebugProfileCookie(profile)
}

let didSyncDebugProfileCookieFromStorage = false

function getStoredProfile(): StoredConsoleProfile | null {
  // localStorage is browser-only. SSR uses canonical/env profile; partners docs
  // route guards defer blocking on the server when the flag is off.
  if (typeof window === 'undefined') return null

  const stored = parseStoredProfileRaw(localStorage.getItem(DEBUG_PROFILE_KEY))
  if (!didSyncDebugProfileCookieFromStorage) {
    didSyncDebugProfileCookieFromStorage = true
    syncDebugProfileCookie(stored)
  }
  return stored
}

/** Whether a debug localStorage profile override is active (vs env). */
export function hasDebugProfileOverride(): boolean {
  return getStoredProfile() !== null
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
let backendUsageStatsAvailability: boolean | null = null

/**
 * Resolve the backend's public `_APP_USAGE_STATS` value. Unknown or omitted
 * values leave the profile default unchanged.
 */
export function resolveBackendUsageStatsAvailability(
  value: string | null | undefined,
): boolean | null {
  return parseEnvFeatureOverride(value ?? '')
}

/**
 * Synchronize the self-hosted usage feature with Console variables. The
 * explicit runtime override remains authoritative when configured.
 */
export function setBackendUsageStatsAvailability(
  value: string | null | undefined,
) {
  const next = resolveBackendUsageStatsAvailability(value)
  if (backendUsageStatsAvailability === next) return
  backendUsageStatsAvailability = next
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent(CONSOLE_PROFILE_CHANGE_EVENT))
  }
}

function applyCloudOnlyFeatureGates(
  profileId: ConsoleProfileId,
  features: ConsoleProfileFeatures,
): ConsoleProfileFeatures {
  if (profileId === 'self-hosted') {
    return {
      ...features,
      marketplace: false,
      init: false,
      marketing: false,
      multiTenancy: false,
      oauth2Server: false,
    }
  }
  return features
}

/** '' or unrecognized → null = no override. */
function parseEnvFeatureOverride(value: string): boolean | null {
  const normalized = value.toLowerCase().trim()
  if (normalized === 'false' || normalized === '0' || normalized === 'disabled')
    return false
  if (normalized === 'true' || normalized === '1' || normalized === 'enabled')
    return true
  return null
}

/**
 * Per-feature overrides from runtime env vars (e.g.
 * VITE_CONSOLE_USER_VERIFICATION, VITE_CONSOLE_COOKIE_BANNER,
 * VITE_CONSOLE_BLOG_DRAFTS), applied on top
 * of the canonical profile. A stored debug override still wins.
 */
function applyEnvFeatureOverrides(
  profileId: ConsoleProfileId,
  features: ConsoleProfileFeatures,
): ConsoleProfileFeatures {
  const config = getRuntimeConfig()
  let next = features
  const userVerification = parseEnvFeatureOverride(config.userVerification)
  if (userVerification !== null) {
    next = { ...next, userVerification }
  }
  const cookieBanner = parseEnvFeatureOverride(config.cookieBanner)
  if (cookieBanner !== null) {
    next = { ...next, cookieBanner }
  }
  const blogDrafts = parseEnvFeatureOverride(config.blogDrafts)
  if (blogDrafts !== null) {
    next = { ...next, blogDrafts }
  }
  const usageStatsOverride = parseEnvFeatureOverride(config.usageStats)
  if (usageStatsOverride !== null) {
    next = { ...next, usageStats: usageStatsOverride }
  } else if (
    profileId === 'self-hosted' &&
    backendUsageStatsAvailability !== null
  ) {
    next = { ...next, usageStats: backendUsageStatsAvailability }
  }
  return next
}

export function getActiveProfile(): ConsoleProfile {
  const stored = getStoredProfile()
  const profileId = stored?.id ?? getProfileFromEnv()
  const canonical = CONSOLE_PROFILES[profileId]
  if (!stored) {
    return {
      ...canonical,
      features: applyEnvFeatureOverrides(
        profileId,
        applyCloudOnlyFeatureGates(profileId, canonical.features),
      ),
    }
  }
  const mergedFeatures = applyCloudOnlyFeatureGates(profileId, {
    ...applyEnvFeatureOverrides(profileId, canonical.features),
    ...stored.features,
  } as ConsoleProfileFeatures)
  return { ...stored, features: mergedFeatures }
}

/**
 * Returns feature flags for the active profile.
 */
export function getActiveProfileFeatures(): ConsoleProfileFeatures {
  return getActiveProfile().features
}

/** Feature defaults for a profile id (cloud-only gates + env overrides) - what a debug reset restores. */
export function getCanonicalProfileFeatures(
  profileId: ConsoleProfileId,
): ConsoleProfileFeatures {
  return applyEnvFeatureOverrides(
    profileId,
    applyCloudOnlyFeatureGates(profileId, CONSOLE_PROFILES[profileId].features),
  )
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
 * Stores the profile with empty feature overrides so defaults (canonical + env)
 * show through. Dispatches CONSOLE_PROFILE_CHANGE_EVENT so UI can re-render.
 */
export function setDebugProfileOverride(profileId: ConsoleProfileId | null) {
  if (typeof window === 'undefined') return
  if (profileId) {
    persistDebugProfile({ ...CONSOLE_PROFILES[profileId], features: {} })
  } else {
    persistDebugProfile(null)
  }
  window.dispatchEvent(new CustomEvent(CONSOLE_PROFILE_CHANGE_EVENT))
}

/**
 * Override a single feature flag for the current profile (debug mode only).
 * Creates or updates the stored profile so the override is persisted.
 * Stored profile only keeps override keys in features; getActiveProfile merges with canonical.
 * Dispatches CONSOLE_PROFILE_CHANGE_EVENT so UI re-renders.
 */
export function setDebugProfileFeatureOverride<
  K extends keyof ConsoleProfileFeatures,
>(key: K, value: ConsoleProfileFeatures[K]) {
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
  persistDebugProfile(nextStored)
  window.dispatchEvent(new CustomEvent(CONSOLE_PROFILE_CHANGE_EVENT))
}

/**
 * Reset stored profile feature overrides to canonical defaults for the stored
 * profile id (Cloud or self-hosted). Keeps debug profile selection; no-op if
 * nothing is stored under the debug console profile localStorage key.
 */
export function resetDebugProfileFeatureOverrides() {
  if (typeof window === 'undefined') return
  const stored = getStoredProfile()
  if (!stored) return
  if (!VALID_PROFILE_IDS.includes(stored.id)) return
  persistDebugProfile({ ...CONSOLE_PROFILES[stored.id], features: {} })
  window.dispatchEvent(new CustomEvent(CONSOLE_PROFILE_CHANGE_EVENT))
}

/** Reset a single profile feature override to the canonical default for the stored profile. */
export function resetDebugProfileFeatureOverride<
  K extends keyof ConsoleProfileFeatures,
>(key: K) {
  if (typeof window === 'undefined') return
  const stored = getStoredProfile()
  if (!stored?.features || !(key in stored.features)) return
  if (!VALID_PROFILE_IDS.includes(stored.id)) return

  const canonical = CONSOLE_PROFILES[stored.id]
  const nextFeatures = { ...stored.features }
  delete nextFeatures[key]

  persistDebugProfile({
    ...canonical,
    id: stored.id,
    features: nextFeatures,
  })
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

  window.addEventListener(
    CONSOLE_PROFILE_CHANGE_EVENT,
    handler as EventListener,
  )
  window.addEventListener('storage', storageHandler)

  return () => {
    window.removeEventListener(
      CONSOLE_PROFILE_CHANGE_EVENT,
      handler as EventListener,
    )
    window.removeEventListener('storage', storageHandler)
  }
}
