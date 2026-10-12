import { Clapperboard, Star } from 'lucide-react'
import { ArtChip, riseStyle } from '@/components/pages/products/_components/ArtParts'
import type { PlatformProductId } from '@/lib/alternatives/platform'
import { useT } from '@/lib/i18n/translate'
import { MARKETING_SOCIAL_STATS } from '@/lib/marketing/social-stats'
import { PRODUCT_NAV_REGISTRY } from '@/lib/products/registry'
import type { ProductIcon } from '@/lib/products/types'
import { cn } from '@/lib/utils'
import { AppwriteMark, CompetitorMonogram } from './ComparisonParts'

type OrbitNode = { id: PlatformProductId | 'videos'; label: string; soon?: boolean }

/** Products both platforms offer: the inner ring. */
const SHARED_RING: OrbitNode[] = [
  { id: 'auth', label: 'Auth' },
  { id: 'databases', label: 'Databases' },
  { id: 'postgres', label: 'PostgreSQL' },
  { id: 'storage', label: 'Storage' },
  { id: 'functions', label: 'Functions' },
  { id: 'realtime', label: 'Realtime' },
]

/** Products only Appwrite ships: the glowing outer ring. */
const APPWRITE_RING: OrbitNode[] = [
  { id: 'sites', label: 'Sites' },
  { id: 'messaging', label: 'Messaging' },
  { id: 'domains', label: 'Domains' },
  { id: 'firewall', label: 'Firewall' },
  { id: 'videos', label: 'Videos', soon: true },
]

function nodeIcon(id: OrbitNode['id']): ProductIcon {
  return id === 'videos' ? Clapperboard : PRODUCT_NAV_REGISTRY[id].icon
}

function orbitPosition(index: number, total: number, offset = 0) {
  const angle = (index / total) * Math.PI * 2 - Math.PI / 2 + offset
  return {
    left: `${(50 + 50 * Math.cos(angle)).toFixed(2)}%`,
    top: `${(50 + 50 * Math.sin(angle)).toFixed(2)}%`,
  }
}

function OrbitTile({ node, outer }: { node: OrbitNode; outer: boolean }) {
  const t = useT()
  const Icon = nodeIcon(node.id)
  return (
    <span className="flex flex-col items-center gap-1.5">
      <span
        className={cn(
          'relative flex items-center justify-center rounded-2xl border bg-background dark:bg-card',
          outer
            ? node.soon
              ? 'size-12 border-dashed border-[rgb(var(--tone-rgb)/0.6)] text-[var(--tone-ink)] sm:size-14'
              : 'size-12 border-[rgb(var(--tone-rgb)/0.55)] text-[var(--tone-ink)] shadow-[0_14px_34px_-14px_rgb(var(--tone-rgb)/0.85)] sm:size-14'
            : 'size-9 border-border text-muted-foreground shadow-sm sm:size-10',
        )}
      >
        <Icon className={outer ? 'size-5 sm:size-6' : 'size-4'} strokeWidth={1.75} aria-hidden />
      </span>
      <span
        className={cn(
          'whitespace-nowrap rounded-full px-1.5 text-[10px] font-medium leading-4 sm:text-[11px]',
          outer ? 'bg-background/80 text-foreground dark:bg-card/80' : 'text-muted-foreground',
        )}
      >
        {t(node.label)}
        {node.soon ? <span className="ms-1 text-[var(--tone-ink)]">{t('Soon')}</span> : null}
      </span>
    </span>
  )
}

/**
 * Appwrite at the center of its product universe. The inner ring is what Supabase also covers;
 * the glowing outer ring is what you only get with Appwrite.
 */
export function SupabaseHeroArt() {
  const t = useT()
  const stars = MARKETING_SOCIAL_STATS.github.stat

  return (
    <div className="relative mx-auto min-w-0 w-full max-w-[560px] overflow-x-clip py-2 sm:py-4">
      <div className="relative mx-auto aspect-square w-[86%] sm:w-full">
        <div className="absolute inset-[4%] rounded-full border border-dashed border-[rgb(var(--tone-rgb)/0.45)]" aria-hidden />
        <div
          className="absolute inset-[4%] rounded-full bg-[radial-gradient(circle,transparent_52%,rgb(var(--tone-rgb)/0.09)_72%,transparent_100%)]"
          aria-hidden
        />
        <div className="absolute inset-[27%] rounded-full border border-border" aria-hidden />
        <div
          className="absolute inset-[36%] rounded-full bg-[radial-gradient(circle,rgb(var(--tone-rgb)/0.4),transparent_70%)]"
          aria-hidden
        />

        <div className="product-hero-orbit absolute inset-[4%]">
          {APPWRITE_RING.map((node, index) => (
            <span
              key={node.id}
              className="product-hero-rise absolute -translate-x-1/2 -translate-y-1/2"
              style={riseStyle(500 + index * 110, orbitPosition(index, APPWRITE_RING.length))}
            >
              <span className="product-hero-orbit-reverse block">
                <OrbitTile node={node} outer />
              </span>
            </span>
          ))}
        </div>

        <div className="product-hero-orbit-reverse absolute inset-[27%]">
          {SHARED_RING.map((node, index) => (
            <span
              key={node.id}
              className="product-hero-rise absolute -translate-x-1/2 -translate-y-1/2"
              style={riseStyle(
                200 + index * 80,
                orbitPosition(index, SHARED_RING.length, Math.PI / SHARED_RING.length),
              )}
            >
              <span className="product-hero-orbit block">
                <OrbitTile node={node} outer={false} />
              </span>
            </span>
          ))}
        </div>

        <div
          className="product-hero-rise absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2"
          style={riseStyle(80)}
        >
          <span className="relative flex">
            <span
              className="absolute -inset-3 animate-ping rounded-[28px] bg-[rgb(var(--tone-rgb)/0.14)] [animation-duration:3s] motion-reduce:animate-none"
              aria-hidden
            />
            <span className="relative flex size-16 items-center justify-center rounded-3xl border border-[rgb(var(--tone-rgb)/0.55)] bg-background shadow-[0_20px_50px_-18px_rgb(var(--tone-rgb)/0.9)] dark:bg-card sm:size-20">
              <AppwriteMark className="size-8 sm:size-10" />
            </span>
          </span>
        </div>
      </div>

      <ArtChip className="start-0 top-[2%] sm:-start-6" delayMs={1100} floatDelayMs={200}>
        <div className="flex items-center gap-2">
          <span className="flex size-7 items-center justify-center rounded-md bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]">
            <Star className="size-3.5" aria-hidden />
          </span>
          <div>
            <p className="text-[12px] font-medium leading-4 text-foreground">
              <bdi>{stars}</bdi> {t('GitHub stars')}
            </p>
            <p className="text-[10px] leading-4 text-muted-foreground">{t('Open source, self-host anywhere')}</p>
          </div>
        </div>
      </ArtChip>

      <ArtChip className="bottom-[2%] end-0 sm:-end-6" delayMs={1400} floatDelayMs={900}>
        <ul className="space-y-1.5 text-[11px]">
          <li className="flex items-center gap-2 font-medium text-foreground">
            <span className="size-2.5 rounded-full bg-[var(--tone-ink)] shadow-[0_0_10px_rgb(var(--tone-rgb))]" aria-hidden />
            {t('Only on Appwrite')}
          </li>
          <li className="flex items-center gap-2 text-muted-foreground">
            <CompetitorMonogram name="Supabase" className="size-4 text-[9px]" />
            {t('Also on Supabase')}
          </li>
        </ul>
      </ArtChip>
    </div>
  )
}
