import { useLayoutEffect, useState } from 'react'

export const CHART_ENTER_ANIMATION_DURATION_MS = 800

/** Disable Recharts enter animation (prevents replay on ResponsiveContainer resize). */
export const CHART_ANIMATION_DISABLED = { isAnimationActive: false as const }

type ChartEnterAnimationProps =
  | typeof CHART_ANIMATION_DISABLED
  | {
      isAnimationActive: true
      animationDuration: number
      animationEasing: 'ease-out'
      animationBegin: 0
    }

/**
 * Play a one-time enter animation when `animationKey` changes.
 * After the duration elapses, animation is disabled so terminal/viewport
 * resizes do not replay the chart draw.
 */
export function useChartEnterAnimation(
  animationKey: string,
  enabled: boolean,
): ChartEnterAnimationProps {
  const [shouldAnimate, setShouldAnimate] = useState(false)

  useLayoutEffect(() => {
    if (!enabled) {
      setShouldAnimate(false)
      return
    }

    setShouldAnimate(true)
    const timer = window.setTimeout(
      () => setShouldAnimate(false),
      CHART_ENTER_ANIMATION_DURATION_MS + 50,
    )
    return () => clearTimeout(timer)
  }, [animationKey, enabled])

  if (!shouldAnimate) {
    return CHART_ANIMATION_DISABLED
  }

  return {
    isAnimationActive: true,
    animationDuration: CHART_ENTER_ANIMATION_DURATION_MS,
    animationEasing: 'ease-out',
    animationBegin: 0,
  }
}
