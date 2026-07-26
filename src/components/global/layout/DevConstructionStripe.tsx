import { useDebugOverrides } from '@/lib/debug-overrides'

/**
 * Vite DEV construction-tape strip. Sits at the top of the sticky header stack
 * (above status / promo / impersonation banners and the main header bar).
 */
export function DevConstructionStripe() {
  const { showDevConstructionStripe } = useDebugOverrides()
  if (!import.meta.env.DEV || !showDevConstructionStripe) return null

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
