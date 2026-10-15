import { useEffect, useState } from 'react'
import {
  useDebugOverrides,
  type InitLowPowerAnimationsOverride,
} from '@/lib/debug-overrides'

type NavigatorWithDeviceHints = Navigator & {
  deviceMemory?: number
  connection?: {
    saveData?: boolean
    effectiveType?: string
  }
}

export type InitLowPowerAnimationDecision = {
  enabled: boolean
  autoDetected: boolean
  override: InitLowPowerAnimationsOverride
  reason: string
  prefersReducedMotion: boolean
  joolAnimationEnabled: boolean
  joolAnimationReason: string
  signals: {
    hardwareConcurrency: number | null
    deviceMemory: number | null
    saveData: boolean | null
    effectiveType: string | null
  }
}

function getLowPowerAnimationSignals(): InitLowPowerAnimationDecision['signals'] {
  if (typeof window === 'undefined') {
    return {
      hardwareConcurrency: null,
      deviceMemory: null,
      saveData: null,
      effectiveType: null,
    }
  }

  const navigatorWithHints = navigator as NavigatorWithDeviceHints
  const connection = navigatorWithHints.connection

  return {
    hardwareConcurrency: navigator.hardwareConcurrency || null,
    deviceMemory: navigatorWithHints.deviceMemory ?? null,
    saveData: connection?.saveData ?? null,
    effectiveType: connection?.effectiveType ?? null,
  }
}

function getPrefersReducedMotion() {
  if (typeof window === 'undefined') return false
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

function evaluateAutoLowPowerDecision(
  signals: InitLowPowerAnimationDecision['signals'],
) {
  if (signals.saveData) {
    return { autoDetected: true, reason: 'Data Saver is enabled.' }
  }
  if (signals.effectiveType === 'slow-2g' || signals.effectiveType === '2g') {
    return {
      autoDetected: true,
      reason: `Connection effective type is ${signals.effectiveType}.`,
    }
  }
  if (typeof signals.deviceMemory === 'number' && signals.deviceMemory <= 4) {
    return {
      autoDetected: true,
      reason: `Device memory is ${signals.deviceMemory} GB, at or below the 4 GB threshold.`,
    }
  }
  if (
    typeof signals.hardwareConcurrency === 'number' &&
    signals.hardwareConcurrency > 0 &&
    signals.hardwareConcurrency <= 4
  ) {
    return {
      autoDetected: true,
      reason: `CPU reports ${signals.hardwareConcurrency} logical cores, at or below the 4-core threshold.`,
    }
  }

  return {
    autoDetected: false,
    reason:
      'No low-power signal matched. Auto mode keeps the full Init animation.',
  }
}

function getInitLowPowerAnimationDecision(
  override: InitLowPowerAnimationsOverride,
): InitLowPowerAnimationDecision {
  const signals = getLowPowerAnimationSignals()
  const prefersReducedMotion = getPrefersReducedMotion()
  const auto = evaluateAutoLowPowerDecision(signals)
  const enabled =
    override === 'on' ? true : override === 'off' ? false : auto.autoDetected
  const reason =
    override === 'on'
      ? 'Debug override is set to On.'
      : override === 'off'
        ? 'Debug override is set to Off.'
        : auto.reason
  const joolAnimationEnabled = !prefersReducedMotion
  const joolAnimationReason = prefersReducedMotion
    ? 'System reduced-motion preference is enabled, so Jool particles are not mounted.'
    : 'System motion preference allows Jool particles to run.'

  return {
    enabled,
    autoDetected: auto.autoDetected,
    override,
    reason,
    prefersReducedMotion,
    joolAnimationEnabled,
    joolAnimationReason,
    signals,
  }
}

export function useInitLowPowerAnimationDecision() {
  const { initLowPowerAnimations } = useDebugOverrides()
  const [decision, setDecision] = useState(() =>
    getInitLowPowerAnimationDecision(initLowPowerAnimations),
  )

  useEffect(() => {
    setDecision(getInitLowPowerAnimationDecision(initLowPowerAnimations))
    const media = window.matchMedia('(prefers-reduced-motion: reduce)')
    const handleChange = () => {
      setDecision(getInitLowPowerAnimationDecision(initLowPowerAnimations))
    }

    media.addEventListener('change', handleChange)
    return () => media.removeEventListener('change', handleChange)
  }, [initLowPowerAnimations])

  return decision
}

export function useInitLowPowerAnimations() {
  return useInitLowPowerAnimationDecision().enabled
}
