import {
  CONSOLE_PROFILE_FEATURE_LABELS,
  getActiveProfile,
  getCanonicalProfileFeatures,
  getDebugProfileFeatureOverrides,
  resetDebugProfileFeatureOverride,
  resetDebugProfileFeatureOverrides,
  setDebugProfileFeatureOverride,
  type ConsoleProfileFeatures,
} from '@/lib/console-profiles'
import {
  DEBUG_OVERRIDE_KEYS,
  FEATURE_FLAGS_MENU_DEBUG_KEYS,
  getDefaultDebugOverrides,
  loadDebugOverrides,
  resetFeatureFlagsMenuDebugOverride,
  resetFeatureFlagsMenuDebugOverrides,
  setDebugOverride,
  type FeatureFlagsMenuDebugKey,
} from '@/lib/debug-overrides'
import { getRuntimeConfig } from '@/lib/runtime-config'

type FlagKey = keyof ConsoleProfileFeatures | FeatureFlagsMenuDebugKey
export type FlagState = {
  default: boolean
  override: boolean | null
  effective: boolean
}
export type FlagSnapshot = Record<FlagKey, FlagState>

const profileKeys = Object.keys(CONSOLE_PROFILE_FEATURE_LABELS) as Array<
  keyof ConsoleProfileFeatures
>
const keys = new Set<string>([...profileKeys, ...FEATURE_FLAGS_MENU_DEBUG_KEYS])

function assertFlagKey(key: string): asserts key is FlagKey {
  if (!keys.has(key)) throw new TypeError(`Unknown feature flag: ${key}`)
}

function isProfileKey(key: FlagKey): key is keyof ConsoleProfileFeatures {
  return Object.hasOwn(CONSOLE_PROFILE_FEATURE_LABELS, key)
}

function list(): FlagSnapshot {
  const profile = getActiveProfile()
  const defaults = getCanonicalProfileFeatures(profile.id)
  const overrides = getDebugProfileFeatureOverrides()
  const debugDefaults = getDefaultDebugOverrides()
  const debug = loadDebugOverrides()
  return Object.fromEntries([
    ...profileKeys.map((key) => [
      key,
      {
        default: defaults[key],
        override: overrides[key] ?? null,
        effective: profile.features[key],
      },
    ]),
    ...FEATURE_FLAGS_MENU_DEBUG_KEYS.map((key) => {
      const stored = window.localStorage.getItem(DEBUG_OVERRIDE_KEYS[key])
      return [
        key,
        {
          default: debugDefaults[key],
          override:
            stored === 'true' ? true : stored === 'false' ? false : null,
          effective: debug[key],
        },
      ]
    }),
  ]) as FlagSnapshot
}

function set(values: Partial<Record<FlagKey, boolean>>): FlagSnapshot {
  if (!values || typeof values !== 'object' || Array.isArray(values)) {
    throw new TypeError('Expected an object of feature flag names and booleans')
  }
  const entries = Object.entries(values)
  for (const [key, value] of entries) {
    assertFlagKey(key)
    if (typeof value !== 'boolean') {
      throw new TypeError(`Feature flag ${key} requires a boolean`)
    }
  }
  for (const [key, value] of entries as Array<[FlagKey, boolean]>) {
    if (isProfileKey(key)) setDebugProfileFeatureOverride(key, value)
    else setDebugOverride(key, value)
  }
  return list()
}

function reset(key: FlagKey): FlagSnapshot {
  assertFlagKey(key)
  if (isProfileKey(key)) resetDebugProfileFeatureOverride(key)
  else resetFeatureFlagsMenuDebugOverride(key)
  return list()
}

function resetAll(): FlagSnapshot {
  resetDebugProfileFeatureOverrides()
  resetFeatureFlagsMenuDebugOverrides()
  return list()
}

export type VibesBrowserApi = {
  flags: {
    list: typeof list
    set: typeof set
    reset: typeof reset
    resetAll: typeof resetAll
  }
}

declare global {
  interface Window {
    __vibes?: VibesBrowserApi
  }
}

/** Install after hydration; exposure is controlled independently of debug overrides. */
export function installBrowserApi(): (() => void) | undefined {
  if (typeof window === 'undefined') return
  if (
    !import.meta.env.DEV &&
    getRuntimeConfig().browserApi.trim().toLowerCase() !== 'true'
  ) {
    return
  }
  const api: VibesBrowserApi = Object.freeze({
    flags: Object.freeze({ list, set, reset, resetAll }),
  })
  window.__vibes = api
  return () => {
    if (window.__vibes === api) delete window.__vibes
  }
}
