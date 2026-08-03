'use client'

import { useDebugOverrides } from '@/lib/debug-overrides'

/**
 * Vite DEV construction-tape strip. Rendered at the root shell so it spans the
 * full viewport width above both the main column and the console right pane.
 *
 * `useDebugOverrides` is hydration-safe (storage applies after mount), so this
 * can read `showConstruction` directly during render.
 */
export function DevConstructionStripe() {
  const { showConstruction } = useDebugOverrides()
  if (!import.meta.env.DEV || !showConstruction) return null

  return (
    <div
      aria-hidden
      className="h-1 w-full shrink-0 opacity-50"
      style={{
        backgroundImage:
          'repeating-linear-gradient(-45deg, #fbbf24 0 6px, #171717 6px 12px)',
      }}
    />
  )
}
