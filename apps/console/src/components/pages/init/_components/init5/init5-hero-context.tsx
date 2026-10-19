import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import {
  loadDebugOverrides,
  setDebugOverride,
  subscribeToDebugOverrides,
} from '@/lib/debug-overrides'
import {
  DEFAULT_INIT5_HERO_VARIANT,
  INIT5_HERO_VARIANT_SEARCH_PARAM,
  isInit5HeroVariantId,
  type Init5HeroVariantId,
} from '@/lib/init/init5-hero/hero-variant'

type Init5HeroContextValue = {
  /** `null` until the persisted choice is read on mount (avoids booting a scene twice). */
  variant: Init5HeroVariantId | null
  setVariant: (variant: Init5HeroVariantId) => void
  /** Bumps whenever the staged intro (copy + visual) should play again. */
  replayToken: number
  replay: () => void
  /** Concept switcher: dev builds, or when the page was opened with `?hero=`. */
  showSwitcher: boolean
}

const FALLBACK: Init5HeroContextValue = {
  variant: DEFAULT_INIT5_HERO_VARIANT,
  setVariant: () => undefined,
  replayToken: 0,
  replay: () => undefined,
  showSwitcher: false,
}

const Init5HeroContext = createContext<Init5HeroContextValue | null>(null)

export function Init5HeroProvider({ children }: { children: ReactNode }) {
  const [variant, setVariantState] = useState<Init5HeroVariantId | null>(null)
  const [replayToken, setReplayToken] = useState(0)
  const [showSwitcher, setShowSwitcher] = useState(false)
  const previousVariantRef = useRef<Init5HeroVariantId | null>(null)

  useEffect(() => {
    const param = new URLSearchParams(window.location.search).get(
      INIT5_HERO_VARIANT_SEARCH_PARAM,
    )
    if (
      isInit5HeroVariantId(param) &&
      loadDebugOverrides().init5HeroVariant !== param
    ) {
      setDebugOverride('init5HeroVariant', param)
    }
    setShowSwitcher(Boolean(import.meta.env.DEV) || param !== null)
    setVariantState(loadDebugOverrides().init5HeroVariant)
    return subscribeToDebugOverrides((overrides) => {
      setVariantState(overrides.init5HeroVariant)
    })
  }, [])

  useEffect(() => {
    if (!variant) return
    const previous = previousVariantRef.current
    previousVariantRef.current = variant
    // Switching concepts replays the whole first impression, copy included.
    if (previous && previous !== variant) setReplayToken((token) => token + 1)
  }, [variant])

  const setVariant = useCallback((next: Init5HeroVariantId) => {
    setDebugOverride('init5HeroVariant', next)
  }, [])

  const replay = useCallback(() => setReplayToken((token) => token + 1), [])

  const value = useMemo(
    () => ({ variant, setVariant, replayToken, replay, showSwitcher }),
    [variant, setVariant, replayToken, replay, showSwitcher],
  )

  return (
    <Init5HeroContext.Provider value={value}>
      {children}
    </Init5HeroContext.Provider>
  )
}

export function useInit5Hero(): Init5HeroContextValue {
  return useContext(Init5HeroContext) ?? FALLBACK
}
