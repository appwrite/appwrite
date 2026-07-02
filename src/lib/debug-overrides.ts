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
export type DebugLanguageOverride = 'auto' | 'en' | 'he'

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
  /** Document direction for RTL layout testing in debug mode. */
  pageDirection: PageDirectionOverride
  /** App language preference from debug menu. */
  language: DebugLanguageOverride
}

const isBrowser = typeof window !== 'undefined'

function readBooleanFromStorage(key: string, defaultValue = false) {
  if (!isBrowser) return defaultValue
  const raw = localStorage.getItem(key)
  if (raw === null) return defaultValue
  return raw === 'true'
}

function readStringFromStorage<T extends string>(
  key: string,
  allowedValues: readonly T[],
  defaultValue: T,
): T {
  if (!isBrowser) return defaultValue
  const raw = localStorage.getItem(key)
  if (raw === null) return defaultValue
  return allowedValues.includes(raw as T) ? (raw as T) : defaultValue
}

function readNullableInitDayFromStorage(key: string): number | null {
  if (!isBrowser) return null
  const raw = localStorage.getItem(key)
  if (raw === null || raw === 'auto') return null
  const parsed = Number.parseInt(raw, 10)
  if (!isValidInitMockCurrentDay(parsed)) return null
  return parsed
}

function readNullableInitTicketTypeFromStorage(key: string): InitTicketTypeId | null {
  if (!isBrowser) return null
  const raw = localStorage.getItem(key)
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
    pageDirection: readStringFromStorage(
      DEBUG_OVERRIDE_KEYS.pageDirection,
      ['ltr', 'rtl'] as const,
      'ltr',
    ),
    language: readStringFromStorage(
      DEBUG_OVERRIDE_KEYS.language,
      ['auto', 'en', 'he'] as const,
      'auto',
    ),
  }
}

export function setDebugOverride<K extends keyof DebugOverrides>(
  key: K,
  value: DebugOverrides[K],
) {
  if (!isBrowser) return
  if (EPHEMERAL_OVERRIDE_KEYS.has(key)) {
    ;(ephemeralOverrides as Record<K, DebugOverrides[K]>)[key] = value
    window.dispatchEvent(new CustomEvent(DEBUG_OVERRIDE_EVENT))
    return
  }
  const storageKey = DEBUG_OVERRIDE_KEYS[key]
  if (typeof value === 'boolean') {
    localStorage.setItem(storageKey, value ? 'true' : 'false')
  } else if (typeof value === 'string') {
    localStorage.setItem(storageKey, value)
  } else if (value === null) {
    localStorage.removeItem(storageKey)
  } else if (typeof value === 'number') {
    localStorage.setItem(storageKey, String(value))
  } else if (value) {
    localStorage.setItem(storageKey, 'true')
  } else {
    localStorage.removeItem(storageKey)
  }
  window.dispatchEvent(new CustomEvent(DEBUG_OVERRIDE_EVENT))
}

export function resetDebugOverrides() {
  if (!isBrowser) return
  EPHEMERAL_OVERRIDE_KEYS.forEach((key) => {
    delete ephemeralOverrides[key]
  })
  Object.values(DEBUG_OVERRIDE_KEYS).forEach((key) => {
    localStorage.removeItem(key)
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
  'disableUsageBreakdownQueries',
  'disableOverviewBandwidthChart',
  'disableOverviewRequestsChart',
  'disableOverviewStorageChart',
  'disableOverviewExecutionsChart',
  'disableOverviewComputeChart',
  'unlockOnboardingLocks',
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
  disableUsageBreakdownQueries: false,
  disableOverviewBandwidthChart: false,
  disableOverviewRequestsChart: false,
  disableOverviewStorageChart: false,
  disableOverviewExecutionsChart: false,
  disableOverviewComputeChart: false,
  unlockOnboardingLocks: false,
}

/** Clear persisted debug overrides used by the Feature flags submenu only. */
export function resetFeatureFlagsMenuDebugOverrides() {
  if (!isBrowser) return
  FEATURE_FLAGS_MENU_DEBUG_KEYS.forEach((key) => {
    localStorage.removeItem(DEBUG_OVERRIDE_KEYS[key])
  })
  window.dispatchEvent(new CustomEvent(DEBUG_OVERRIDE_EVENT))
}

/** Reset a single debug override from the Feature flags submenu to its default. */
export function resetFeatureFlagsMenuDebugOverride(key: FeatureFlagsMenuDebugKey) {
  if (!isBrowser) return
  localStorage.removeItem(DEBUG_OVERRIDE_KEYS[key])
  window.dispatchEvent(new CustomEvent(DEBUG_OVERRIDE_EVENT))
}

export function subscribeToDebugOverrides(
  callback: (overrides: DebugOverrides) => void,
) {
  if (!isBrowser) return () => undefined

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
