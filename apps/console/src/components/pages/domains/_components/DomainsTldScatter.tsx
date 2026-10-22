import { floatStyle, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { cn } from '@/lib/utils'

type TldSpot = { tld: string; x: number; y: number; size: 'sm' | 'md' | 'lg'; dim?: boolean }

/** Percent centers in the page gutters; the search column spans roughly x 27-73%. */
const SPOTS: TldSpot[] = [
  { tld: 'com', x: 12, y: 14, size: 'lg' },
  { tld: 'dev', x: 21, y: 29, size: 'md' },
  { tld: 'xyz', x: 6, y: 36, size: 'sm', dim: true },
  { tld: 'io', x: 15, y: 48, size: 'md' },
  { tld: 'shop', x: 5, y: 60, size: 'sm', dim: true },
  { tld: 'cloud', x: 19, y: 66, size: 'sm', dim: true },
  { tld: 'app', x: 88, y: 13, size: 'lg' },
  { tld: 'ai', x: 79, y: 28, size: 'md' },
  { tld: 'site', x: 94, y: 35, size: 'sm', dim: true },
  { tld: 'co', x: 85, y: 47, size: 'md' },
  { tld: 'tech', x: 95, y: 59, size: 'sm', dim: true },
  { tld: 'store', x: 80, y: 65, size: 'sm', dim: true },
]

const CHIP_SIZE = {
  sm: 'px-2.5 py-1 text-[12px] rounded-lg',
  md: 'px-3 py-1.5 text-[14px] rounded-xl',
  lg: 'px-4 py-2 text-[17px] rounded-xl',
} as const

export function DomainsTldScatter() {
  return (
    <div className="pointer-events-none absolute inset-0 z-[1] hidden lg:block" aria-hidden>
      {SPOTS.map((spot, index) => (
        <span
          key={spot.tld}
          className="product-hero-rise absolute -translate-x-1/2 -translate-y-1/2"
          style={riseStyle(200 + index * 60, { left: `${spot.x}%`, top: `${spot.y}%` })}
        >
          <span
            dir="ltr"
            className={cn(
              'product-hero-float flex items-baseline border border-border bg-background font-mono tracking-tight text-foreground shadow-[0_14px_34px_-18px_rgb(0_0_0/0.4)] dark:border-white/10 dark:bg-card',
              CHIP_SIZE[spot.size],
              spot.dim && 'opacity-50',
            )}
            style={floatStyle(index * 310)}
          >
            <span className="text-[var(--brand-cta)]">.</span>
            {spot.tld}
          </span>
        </span>
      ))}
    </div>
  )
}
