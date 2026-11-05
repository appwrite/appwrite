import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { floatStyle, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { FUNCTIONS_RUNTIME_ITEMS } from '@/lib/products/hero-logo-strip'
import { cn } from '@/lib/utils'

type ScatterSpot = { x: number; y: number; size: 'sm' | 'md' | 'lg'; dim?: boolean }

/**
 * Percent centers inside the hero copy + art block. The art spans roughly x 8-92% from y 58%,
 * and the title roughly x 14-86% between y 12-50%, so spots stay in the gutters around both.
 */
const SPOTS: ScatterSpot[] = [
  { x: 7, y: 6, size: 'lg' },
  { x: 16, y: 21, size: 'md' },
  { x: 4, y: 33, size: 'sm', dim: true },
  { x: 11, y: 45, size: 'md' },
  { x: 3.5, y: 60, size: 'md' },
  { x: 3.5, y: 77, size: 'sm', dim: true },
  { x: 3, y: 93, size: 'md' },
  { x: 25, y: 1, size: 'sm', dim: true },
  { x: 75, y: 2, size: 'sm', dim: true },
  { x: 93, y: 7, size: 'lg' },
  { x: 84, y: 21, size: 'md' },
  { x: 96, y: 32, size: 'sm', dim: true },
  { x: 89, y: 45, size: 'md' },
  { x: 96.5, y: 61, size: 'md' },
  { x: 96.5, y: 80, size: 'sm', dim: true },
]

const TILE_SIZE = {
  sm: 'size-10 rounded-xl',
  md: 'size-12 rounded-xl',
  lg: 'size-14 rounded-2xl',
} as const

const ICON_SIZE = {
  sm: 'size-5',
  md: 'size-6',
  lg: 'size-7',
} as const

export function FunctionsHeroRuntimes() {
  return (
    <div className="pointer-events-none absolute inset-0 z-0 hidden lg:block" aria-hidden>
      {FUNCTIONS_RUNTIME_ITEMS.slice(0, SPOTS.length).map((runtime, index) => {
        const spot = SPOTS[index]!
        return (
          <span
            key={runtime.key}
            className="product-hero-rise absolute -translate-x-1/2 -translate-y-1/2"
            style={riseStyle(250 + index * 70, { left: `${spot.x}%`, top: `${spot.y}%` })}
          >
            <span
              className={cn(
                'product-hero-float flex items-center justify-center border border-border bg-background shadow-[0_14px_34px_-18px_rgb(0_0_0/0.4)] dark:border-white/10 dark:bg-card',
                TILE_SIZE[spot.size],
                spot.dim && 'opacity-50',
              )}
              style={floatStyle(index * 330)}
              title={runtime.name}
            >
              <RuntimeIcon runtime={runtime.key} className={ICON_SIZE[spot.size]} />
            </span>
          </span>
        )
      })}
    </div>
  )
}
