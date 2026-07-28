import { useState, useEffect } from 'react'
import {
  isValidInitMockCurrentDay,
} from '@/lib/init/mock-current-day'
import {
  type InitTicketTypeId,
  isInitTicketTypeId,
} from '@/lib/init/ticket-types'

const DEBUG_OVERRIDE_EVENT = 'debugOverridesChange'

export const DEBUG_OVERRIDE_KEYS = {
  showNativeAppBar: 'debug:showNativeAppBar',
  showAIAssistant: 'debug:showAIAssistant',
  showActivityChart: 'debug:showActivityChart',
  showSuccessTeamCard: 'debug:showSuccessTeamCard',
  mockCloudStatusAlert: 'debug:mockCloudStatusAlert',
  showFullscreenLoader: 'debug:showFullscreenLoader',
  showFunctionsLocalEditor: 'debug:showFunctionsLocalEditor',
  showConstruction: 'debug:showConstruction',
  mockInitCurrentDay: 'debug:mockInitCurrentDay',
  mockInitTicketType: 'debug:mockInitTicketType',
  previewInitReactionConfetti: 'debug:previewInitReactionConfetti',
  initLowPowerAnimations: 'debug:initLowPowerAnimations',
  keyboardLayout: 'debug:keyboardLayout',
  disableUsageBreakdownQueries: 'debug:disableUsageBreakdownQueries',
  disableOverviewBandwidthChart: 'debug:disableOverviewBandwidthChart',
  disableOverviewRequestsChart: 'debug:disableOverviewRequestsChart',
  disableOverviewStorageChart: 'debug:disableOverviewStorageChart',
  disableOverviewExecutionsChart: 'debug:disableOverviewExecutionsChart',
  disableOverviewComputeChart: 'debug:disableOverviewComputeChart',
  /** When true, onboarding product sections are unlocked without completing Connect. */
  unlockOnboardingLocks: 'debug:unlockOnboardingLocks',
  /** When true, Get started progress panel previews the 100% complete advocacy state. */
  previewOnboardingComplete: 'debug:previewOnboardingComplete',
  /** When true, force-show the community support fullscreen wizard. */
  previewCommunitySupportWizard: 'debug:previewCommunitySupportWizard',
  /** Page text direction for RTL layout testing. Default ltr. */
  pageDirection: 'debug:pageDirection',
  /** App copy language preference used by the i18n provider. */
  language: 'debug:language',
} as const

/** Overrides that are not persisted to localStorage (reset on reload). */
const EPHEMERAL_OVERRIDE_KEYS = new Set<keyof DebugOverrides>([
  'showFullscreenLoader',
])

const ephemeralOverrides: Partial<DebugOverrides> = {}

export type MockCloudStatusAlert =
  | 'live'
  | 'operational'
  | 'degraded'
  | 'downtime'
  | 'maintenance'

export type InitLowPowerAnimationsOverride = 'auto' | 'on' | 'off'

export type KeyboardLayoutOverride = 'auto' | 'macos' | 'windows'

export type PageDirectionOverride = 'ltr' | 'rtl'
export type DebugLanguageOverride = 'en' | 'he' | 'ja'

export type DebugOverrides = {
  showNativeAppBar: boolean
  /** When true, the AI assistant is shown regardless of profile (experimental). Default false. */
  showAIAssistant: boolean
  /** When true, the activity log volume chart is shown above activity events. Default false. */
  showActivityChart: boolean
  /** When true, the success team card is shown on organization overview (custom plans). Default false. */
  showSuccessTeamCard: boolean
  /** Mock Appwrite Cloud status alert state for design review in debug mode. */
  mockCloudStatusAlert: MockCloudStatusAlert
  /** When true, the fullscreen loader is always shown (for preview). Default false. */
  showFullscreenLoader: boolean
  /**
   * When true, exposes the Functions “Local editor” entry and /functions/editor route
   * (Monaco + gzip deploy prep). Default false.
   */
  showFunctionsLocalEditor: boolean
  /**
   * When true, show the DEV construction bar at the top of the header stack.
   * Default true, or VITE_CONSTRUCTION when set.
   */
  showConstruction: boolean
  /**
   * Mock which Init launch day is "today" (0 = before, 1–5 = during, 6 = after,
   * 7 = 7+ days after event, org promo banner hidden).
   * Null uses the real calendar date.
   */
  mockInitCurrentDay: number | null
  /** Mock Init ticket tier on /init. Null uses account rules (gold, silver, standard). */
  mockInitTicketType: InitTicketTypeId | null
  /** When true, Init reaction confetti triggers with a single online user. */
  previewInitReactionConfetti: boolean
  /** Controls Init animation optimizations for constrained devices. */
  initLowPowerAnimations: InitLowPowerAnimationsOverride
  /** Command center keyboard visualizer and shortcut labels. */
  keyboardLayout: KeyboardLayoutOverride
  /** When true, skip usage listEvents/listGauges calls that pass dimensions (overview breakdown panels). */
  disableUsageBreakdownQueries: boolean
  /** When true, hide the matching usage chart tab on the project overview. */
  disableOverviewBandwidthChart: boolean
  disableOverviewRequestsChart: boolean
  disableOverviewStorageChart: boolean
  disableOverviewExecutionsChart: boolean
  disableOverviewComputeChart: boolean
  /** When true, skip the Connect gate on the Get started onboarding page. */
  unlockOnboardingLocks: boolean
  /** When true, force Get started progress to 100% to preview advocacy copy + Star CTA. */
  previewOnboardingComplete: boolean
  /** When true, force-show the community support fullscreen wizard. */
  previewCommunitySupportWizard: boolean
  /** Document direction for RTL layout testing in debug mode. */
  pageDirection: PageDirectionOverride
  /** App language preference from debug menu. */
  language: DebugLanguageOverride
}

function getStorage(): Storage | null {
  if (typeof window === 'undefined' || !window.localStorage) return null
  return window.localStorage
}

function readBooleanFromStorage(key: string, defaultValue = false) {
  const storage = getStorage()
  if (!storage) return defaultValue
  const raw = storage.getItem(key)
  if (raw === null) return defaultValue
  return raw === 'true'
}

/**
 * Default for the Vite DEV construction bar when localStorage has no override.
 * Set `VITE_CONSTRUCTION=false` (or 0/off/no) to hide it for agent browsers.
 * Unset defaults to on.
 */
export function getShowConstructionDefault(): boolean {
  const raw = String(import.meta.env.VITE_CONSTRUCTION ?? '')
    .trim()
    .toLowerCase()
  if (!raw) return true
  if (['0', 'false', 'off', 'no'].includes(raw)) return false
  if (['1', 'true', 'on', 'yes'].includes(raw)) return true
  return true
}

function readStringFromStorage<T extends string>(
  key: string,
  allowedValues: readonly T[],
  defaultValue: T,
): T {
  const storage = getStorage()
  if (!storage) return defaultValue
  const raw = storage.getItem(key)
  if (raw === null) return defaultValue
  return allowedValues.includes(raw as T) ? (raw as T) : defaultValue
}

function readNullableInitDayFromStorage(key: string): number | null {
  const storage = getStorage()
  if (!storage) return null
  const raw = storage.getItem(key)
  if (raw === null || raw === 'auto') return null
  const parsed = Number.parseInt(raw, 10)
  if (!isValidInitMockCurrentDay(parsed)) return null
  return parsed
}

function readNullableInitTicketTypeFromStorage(key: string): InitTicketTypeId | null {
  const storage = getStorage()
  if (!storage) return null
  const raw = storage.getItem(key)
  if (raw === null || raw === 'auto') return null
  return isInitTicketTypeId(raw) ? raw : null
}

export function loadDebugOverrides(): DebugOverrides {
  return {
    showNativeAppBar: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.showNativeAppBar,
    ),
    showAIAssistant: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.showAIAssistant,
      false,
    ),
    showActivityChart: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.showActivityChart,
      false,
    ),
    showSuccessTeamCard: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.showSuccessTeamCard,
      false,
    ),
    mockCloudStatusAlert: readStringFromStorage(
      DEBUG_OVERRIDE_KEYS.mockCloudStatusAlert,
      ['live', 'operational', 'degraded', 'downtime', 'maintenance'] as const,
      'live',
    ),
    showFullscreenLoader: ephemeralOverrides.showFullscreenLoader ?? false,
    showFunctionsLocalEditor: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.showFunctionsLocalEditor,
      false,
    ),
    showConstruction: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.showConstruction,
      getShowConstructionDefault(),
    ),
    mockInitCurrentDay: readNullableInitDayFromStorage(
      DEBUG_OVERRIDE_KEYS.mockInitCurrentDay,
    ),
    mockInitTicketType: readNullableInitTicketTypeFromStorage(
      DEBUG_OVERRIDE_KEYS.mockInitTicketType,
    ),
    previewInitReactionConfetti: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.previewInitReactionConfetti,
      false,
    ),
    initLowPowerAnimations: readStringFromStorage(
      DEBUG_OVERRIDE_KEYS.initLowPowerAnimations,
      ['auto', 'on', 'off'] as const,
      'auto',
    ),
    keyboardLayout: readStringFromStorage(
      DEBUG_OVERRIDE_KEYS.keyboardLayout,
      ['auto', 'macos', 'windows'] as const,
      'auto',
    ),
    disableUsageBreakdownQueries: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.disableUsageBreakdownQueries,
      false,
    ),
    disableOverviewBandwidthChart: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.disableOverviewBandwidthChart,
      false,
    ),
    disableOverviewRequestsChart: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.disableOverviewRequestsChart,
      false,
    ),
    disableOverviewStorageChart: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.disableOverviewStorageChart,
      false,
    ),
    disableOverviewExecutionsChart: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.disableOverviewExecutionsChart,
      false,
    ),
    disableOverviewComputeChart: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.disableOverviewComputeChart,
      false,
    ),
    unlockOnboardingLocks: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.unlockOnboardingLocks,
      false,
    ),
    previewOnboardingComplete: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.previewOnboardingComplete,
      false,
    ),
    previewCommunitySupportWizard: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.previewCommunitySupportWizard,
      false,
    ),
    pageDirection: readStringFromStorage(
      DEBUG_OVERRIDE_KEYS.pageDirection,
      ['ltr', 'rtl'] as const,
      'ltr',
    ),
    language: readStringFromStorage(
      DEBUG_OVERRIDE_KEYS.language,
      ['en', 'he', 'ja'] as const,
      'en',
    ),
  }
}

export function setDebugOverride<K extends keyof DebugOverrides>(
  key: K,
  value: DebugOverrides[K],
) {
  if (EPHEMERAL_OVERRIDE_KEYS.has(key)) {
    ;(ephemeralOverrides as Record<K, DebugOverrides[K]>)[key] = value
    if (typeof window !== 'undefined') {
      window.dispatchEvent(new CustomEvent(DEBUG_OVERRIDE_EVENT))
    }
    return
  }
  const storage = getStorage()
  if (!storage) return
  const storageKey = DEBUG_OVERRIDE_KEYS[key]
  if (typeof value === 'boolean') {
    storage.setItem(storageKey, value ? 'true' : 'false')
  } else if (typeof value === 'string') {
    storage.setItem(storageKey, value)
  } else if (value === null) {
    storage.removeItem(storageKey)
  } else if (typeof value === 'number') {
    storage.setItem(storageKey, String(value))
  } else if (value) {
    storage.setItem(storageKey, 'true')
  } else {
    storage.removeItem(storageKey)
  }
  window.dispatchEvent(new CustomEvent(DEBUG_OVERRIDE_EVENT))
}

export function resetDebugOverrides() {
  const storage = getStorage()
  if (!storage) return
  EPHEMERAL_OVERRIDE_KEYS.forEach((key) => {
    delete ephemeralOverrides[key]
  })
  Object.values(DEBUG_OVERRIDE_KEYS).forEach((key) => {
    storage.removeItem(key)
  })
  window.dispatchEvent(new CustomEvent(DEBUG_OVERRIDE_EVENT))
}

/** Keys toggled from Debug → Settings → Feature flags (not other debug sections). */
export const FEATURE_FLAGS_MENU_DEBUG_KEYS = [
  'showAIAssistant',
  'showActivityChart',
  'showNativeAppBar',
  'showSuccessTeamCard',
  'showFunctionsLocalEditor',
  'showConstruction',
  'disableUsageBreakdownQueries',
  'disableOverviewBandwidthChart',
  'disableOverviewRequestsChart',
  'disableOverviewStorageChart',
  'disableOverviewExecutionsChart',
  'disableOverviewComputeChart',
  'unlockOnboardingLocks',
  'previewOnboardingComplete',
  'previewCommunitySupportWizard',
] as const satisfies readonly (keyof DebugOverrides)[]

export type FeatureFlagsMenuDebugKey =
  (typeof FEATURE_FLAGS_MENU_DEBUG_KEYS)[number]

/** Default values for debug overrides shown in the Feature flags submenu. */
export const FEATURE_FLAGS_MENU_DEBUG_DEFAULTS: Pick<
  DebugOverrides,
  FeatureFlagsMenuDebugKey
> = {
  showAIAssistant: false,
  showActivityChart: false,
  showNativeAppBar: false,
  showSuccessTeamCard: false,
  showFunctionsLocalEditor: false,
  showConstruction: getShowConstructionDefault(),
  disableUsageBreakdownQueries: false,
  disableOverviewBandwidthChart: false,
  disableOverviewRequestsChart: false,
  disableOverviewStorageChart: false,
  disableOverviewExecutionsChart: false,
  disableOverviewComputeChart: false,
  unlockOnboardingLocks: false,
  previewOnboardingComplete: false,
  previewCommunitySupportWizard: false,
}

/** Clear persisted debug overrides used by the Feature flags submenu only. */
export function resetFeatureFlagsMenuDebugOverrides() {
  const storage = getStorage()
  if (!storage) return
  FEATURE_FLAGS_MENU_DEBUG_KEYS.forEach((key) => {
    storage.removeItem(DEBUG_OVERRIDE_KEYS[key])
  })
  window.dispatchEvent(new CustomEvent(DEBUG_OVERRIDE_EVENT))
}

/** Reset a single debug override from the Feature flags submenu to its default. */
export function resetFeatureFlagsMenuDebugOverride(key: FeatureFlagsMenuDebugKey) {
  const storage = getStorage()
  if (!storage) return
  storage.removeItem(DEBUG_OVERRIDE_KEYS[key])
  window.dispatchEvent(new CustomEvent(DEBUG_OVERRIDE_EVENT))
}

export function subscribeToDebugOverrides(
  callback: (overrides: DebugOverrides) => void,
) {
  if (typeof window === 'undefined') return () => undefined

  const handler = () => callback(loadDebugOverrides())

  window.addEventListener(DEBUG_OVERRIDE_EVENT, handler as EventListener)
  window.addEventListener('storage', handler)

  return () => {
    window.removeEventListener(DEBUG_OVERRIDE_EVENT, handler as EventListener)
    window.removeEventListener('storage', handler)
  }
}

export function useDebugOverrides(): DebugOverrides {
  const [overrides, setOverrides] = useState(loadDebugOverrides)
  useEffect(() => {
    return subscribeToDebugOverrides(setOverrides)
  }, [])
  return overrides
}

/** When false, overview usage fetchers skip dimension-based breakdown API calls. */
export function areUsageBreakdownQueriesEnabled(): boolean {
  return !loadDebugOverrides().disableUsageBreakdownQueries
}

export function getPageDirection(): PageDirectionOverride {
  return loadDebugOverrides().pageDirection
}

export function isPageRtl(): boolean {
  return getPageDirection() === 'rtl'
}
