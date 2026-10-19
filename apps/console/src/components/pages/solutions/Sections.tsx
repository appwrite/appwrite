import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { ArrowRight, Database, Fingerprint, Folder, Globe, MessageSquare, Shield, Zap } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { TrustedByLogo } from '@/components/global/shared/TrustedByLogo'
import { AppwriteMark } from '@/components/pages/alternative-to/$competitor/_components/ComparisonParts'
import { ArtIconMask, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useMediaMinWidth } from '@/hooks/use-media-min-width'
import { allCustomerLogos } from '@/lib/home/customer-logos'
import { PLATFORM_PRODUCTS } from '@/lib/alternatives/platform'
import { enterpriseVendorSprawl } from '@/lib/enterprise/content'
import { PRODUCT_NAV_REGISTRY } from '@/lib/products/registry'
import { CapabilityJourney } from '@/components/pages/solutions/CapabilityJourney'
import { CapabilityPath } from '@/components/pages/solutions/CapabilityPath'
import type { SolutionCapabilityGroup, SolutionFeature } from '@/lib/solutions/content'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const LOGO_ROTATE_MS = 5000

function usePrefersReducedMotion() {
  return useSyncExternalStore(
    (onChange) => {
      if (typeof window === 'undefined') return () => {}
      const query = window.matchMedia('(prefers-reduced-motion: reduce)')
      query.addEventListener('change', onChange)
      return () => query.removeEventListener('change', onChange)
    },
    () =>
      typeof window !== 'undefined' &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    () => false,
  )
}

function useLogoCount() {
  const isLg = useMediaMinWidth(1024)
  const isSm = useMediaMinWidth(640)
  if (isLg) return 5
  if (isSm) return 4
  return 3
}

/** A short row of customer logos. The row cycles through the full set. */
export function SolutionLogoCloud() {
  const logos = allCustomerLogos
  const count = useLogoCount()
  const pageCount = Math.max(1, Math.ceil(logos.length / count))
  const [page, setPage] = useState(0)
  const [inView, setInView] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const reduceMotion = usePrefersReducedMotion()

  useEffect(() => {
    setPage(0)
  }, [count])

  useEffect(() => {
    const node = rootRef.current
    if (!node) return

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry) setInView(entry.isIntersecting)
      },
      { rootMargin: '120px 0px', threshold: 0 },
    )
    observer.observe(node)
    return () => observer.disconnect()
  }, [])

  useEffect(() => {
    if (reduceMotion || !inView || pageCount <= 1) return

    const intervalId = window.setInterval(() => {
      setPage((current) => (current + 1) % pageCount)
    }, LOGO_ROTATE_MS)

    return () => window.clearInterval(intervalId)
  }, [reduceMotion, inView, pageCount])

  const start = page * count
  const visible = Array.from({ length: Math.min(count, logos.length) }, (_, index) => {
    return logos[(start + index) % logos.length]
  })

  return (
    <div ref={rootRef}>
      <ul
        key={page}
        className={cn(
          'mx-auto grid max-w-5xl grid-cols-3 items-center gap-x-8 sm:grid-cols-4 lg:grid-cols-5 lg:gap-x-12',
          !reduceMotion && 'animate-in fade-in duration-700 motion-reduce:animate-none',
        )}
      >
        {visible.map((logo, index) => (
          <li key={`${logo.src}-${index}`} className="flex min-h-8 items-center justify-center">
            <TrustedByLogo
              src={logo.src}
              alt={logo.alt}
              width={logo.width}
              height={logo.height}
              mask={logo.mask}
              maskSrc={logo.maskSrc}
              inverseMask={logo.inverseMask}
              interactive={false}
              className={cn(
                'w-auto opacity-70',
                logo.size === 'lg' ? 'max-h-6 sm:max-h-7' : 'max-h-5 sm:max-h-6',
              )}
            />
          </li>
        ))}
      </ul>
    </div>
  )
}

const VENDOR_ICONS: Record<(typeof enterpriseVendorSprawl)[number], LucideIcon> = {
  'Identity provider': Fingerprint,
  'Database service': Database,
  'File storage': Folder,
  'Function hosting': Zap,
  'Messaging provider': MessageSquare,
  'Web hosting': Globe,
  'Firewall and DDoS': Shield,
}

export function ValueProps({ items }: { items: readonly SolutionFeature[] }) {
  const t = useT()
  return (
    <ul className="grid gap-x-10 gap-y-10 sm:grid-cols-2">
      {items.map((item, index) => {
        return (
          <li
            key={item.title}
            className="product-hero-rise relative border-t border-foreground/15 pt-5"
            style={riseStyle(120 + index * 90)}
          >
            <span className="absolute -top-px start-0 h-px w-12 bg-[var(--tone-ink)]" aria-hidden />
            <h3 className="flex items-center gap-2.5 text-[16px] font-medium text-foreground">
              {item.icon ? (
                <item.icon className="size-[18px] shrink-0 text-[var(--tone-ink)]" strokeWidth={1.75} aria-hidden />
              ) : item.iconSrc ? (
                <ArtIconMask src={item.iconSrc} className="size-[18px] text-[var(--tone-ink)]" />
              ) : null}
              {t(item.title)}
            </h3>
            <p className="mt-2 text-[13px] leading-6 text-muted-foreground">{t(item.description)}</p>
          </li>
        )
      })}
    </ul>
  )
}

export function Consolidation({
  platformLabel,
  outcomes,
}: {
  platformLabel: string
  outcomes: readonly string[]
}) {
  const t = useT()
  return (
    <div className="grid min-w-0 items-center gap-12 lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,1.1fr)] lg:gap-10">
      <div>
        <p className="font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground">{t('Without a platform')}</p>
        <ul className="mt-4 divide-y divide-foreground/10 border-y border-foreground/15">
          {enterpriseVendorSprawl.map((vendor, index) => {
            const Icon = VENDOR_ICONS[vendor]
            return (
              <li
                key={vendor}
                className="product-hero-rise flex items-center gap-3 py-3"
                style={riseStyle(80 + index * 70)}
              >
                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-dashed border-foreground/25 text-muted-foreground">
                  <Icon className="size-4" strokeWidth={1.75} aria-hidden />
                </span>
                <span className="min-w-0 flex-1 truncate text-[13px] text-foreground/75">{t(vendor)}</span>
                <span className="shrink-0 font-mono text-[10px] text-muted-foreground">{t('Separate contract')}</span>
              </li>
            )
          })}
        </ul>
      </div>

      <span
        className="hidden size-11 items-center justify-center rounded-full border border-[rgb(var(--tone-rgb)/0.5)] bg-background text-[var(--tone-ink)] shadow-[0_0_24px_rgb(var(--tone-rgb)/0.45)] lg:flex dark:bg-card"
        aria-hidden
      >
        <ArrowRight className="size-4 rtl:-scale-x-100" />
      </span>

      <div className="relative isolate">
        <span
          className="pointer-events-none absolute -inset-10 -z-10 bg-[radial-gradient(ellipse_at_center,rgb(var(--tone-rgb)/0.18),transparent_70%)]"
          aria-hidden
        />
        <p className="flex items-center gap-2 text-[13px] font-medium text-foreground">
          <AppwriteMark className="size-4" />
          {t(platformLabel)}
        </p>
        <ul className="mt-5 grid grid-cols-5 gap-x-2 gap-y-5">
          {PLATFORM_PRODUCTS.map((product, index) => {
            const Icon = PRODUCT_NAV_REGISTRY[product.id].icon
            return (
              <li
                key={product.id}
                className="product-hero-rise flex flex-col items-center gap-2 text-center"
                style={riseStyle(420 + index * 55)}
              >
                <span className="flex size-11 items-center justify-center rounded-2xl border border-[rgb(var(--tone-rgb)/0.45)] bg-background text-[var(--tone-ink)] shadow-[0_10px_26px_-14px_rgb(var(--tone-rgb)/0.8)] dark:bg-card">
                  <Icon className="size-5" strokeWidth={1.75} aria-hidden />
                </span>
                <span className="text-[11px] font-medium text-foreground">{t(product.name)}</span>
              </li>
            )
          })}
        </ul>
        <ul className="mt-7 flex flex-wrap gap-x-6 gap-y-2 border-t border-foreground/15 pt-4 text-[12px] font-medium text-foreground">
          {outcomes.map((outcome) => (
            <li key={outcome} className="flex items-center gap-2">
              <span className="size-1.5 rounded-full bg-[var(--tone-ink)]" aria-hidden />
              {t(outcome)}
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}

function CapabilityStage({
  group,
  index,
  itemsClassName,
  className,
}: {
  group: SolutionCapabilityGroup
  index: number
  itemsClassName: string
  className?: string
}) {
  const t = useT()
  const GroupIcon = group.icon

  return (
    <div className={cn('product-hero-rise relative', className)} style={riseStyle(100 + index * 110)}>
      <div className="relative flex items-center gap-2.5 border-t border-foreground/15 pt-5">
        <span className="absolute -top-px start-0 h-px w-12 bg-[var(--tone-ink)]" aria-hidden />
        <GroupIcon className="size-[18px] text-[var(--tone-ink)]" strokeWidth={1.75} aria-hidden />
        <h3 className="text-[16px] font-medium text-foreground">{t(group.title)}</h3>
      </div>
      <ul className={cn('mt-5', itemsClassName)}>
        {group.items.map((item) => (
          <li key={item.title}>
            <p className="text-[14px] font-medium text-foreground/90">{t(item.title)}</p>
            <p className="mt-1 text-[13px] leading-6 text-muted-foreground">{t(item.description)}</p>
          </li>
        ))}
      </ul>
    </div>
  )
}

export function CapabilityGroups({ groups }: { groups: readonly SolutionCapabilityGroup[] }) {
  return (
    <div className={cn('grid gap-x-10 gap-y-12 sm:grid-cols-2', groups.length > 2 && 'lg:grid-cols-3')}>
      {groups.map((group, groupIndex) => (
        <CapabilityStage
          key={group.title}
          group={group}
          index={groupIndex}
          itemsClassName="space-y-5"
        />
      ))}
    </div>
  )
}

/** First stage spans the row. Later stages sit beside it, wide enough for what they hold. */
export function CapabilityStages({ groups }: { groups: readonly SolutionCapabilityGroup[] }) {
  return <CapabilityJourney groups={groups} />
}

/** A pilot, the demo, then the same APIs on Enterprise. */
export function CapabilityPathSection({ groups }: { groups: readonly SolutionCapabilityGroup[] }) {
  return <CapabilityPath groups={groups} />
}
