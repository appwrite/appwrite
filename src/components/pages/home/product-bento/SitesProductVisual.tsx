import { useEffect, useRef, useState } from 'react'
import { ArrowRight, CheckCircle2 } from 'lucide-react'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { productBentoContainer, productBentoIdle } from './MockSyntax'

const COMMIT_HASH = '01ab234c'
/** Matches `product-bento-site-build` in styles.css */
const BUILD_ANIMATION_DELAY_MS = 250
const BUILD_ANIMATION_DURATION_MS = 1100
const BUILD_TOTAL_SECONDS = 14

function getBuildProgress(elapsedMs: number) {
  if (elapsedMs < BUILD_ANIMATION_DELAY_MS) {
    return { progress: 0, seconds: 0, complete: false }
  }

  const progress = Math.min(
    1,
    (elapsedMs - BUILD_ANIMATION_DELAY_MS) / BUILD_ANIMATION_DURATION_MS,
  )
  const seconds =
    progress >= 1
      ? BUILD_TOTAL_SECONDS
      : Math.floor(progress * BUILD_TOTAL_SECONDS)

  return { progress, seconds, complete: progress >= 1 }
}

/** Appwrite supporting violet, used sparingly on feed accents. */
const REFETCH_ACCENT = '#7C67FE'
const REFETCH_HEADER_BG = '#19191d'
const REFETCH_MARK = `color-mix(in srgb, ${REFETCH_ACCENT} 72%, var(--brand-cta))`

function AbstractBar({
  className,
  width,
}: {
  className?: string
  width?: string
}) {
  return (
    <span
      className={cn('block h-1 rounded-sm bg-muted-foreground/20', className)}
      style={width ? { width } : undefined}
      aria-hidden
    />
  )
}

function AbstractFeedCard({
  titleWidth,
  metaWidth,
  showBadge,
}: {
  titleWidth: string
  metaWidth: string
  showBadge?: boolean
}) {
  return (
    <div className="flex items-center gap-2 rounded-md border border-border/50 bg-background px-2 py-1.5">
      <div className="flex w-3 shrink-0 flex-col items-center gap-px" aria-hidden>
        <span
          className="h-0 w-0 border-x-[3px] border-b-[4px] border-x-transparent"
          style={{ borderBottomColor: REFETCH_MARK }}
        />
        <span className="h-1 w-2 rounded-[1px] bg-muted-foreground/20" />
        <span className="h-0 w-0 border-x-[3px] border-t-[4px] border-x-transparent border-muted-foreground/25" />
      </div>
      <div className="min-w-0 flex-1 space-y-1.5">
        <div className="flex items-center gap-1.5">
          {showBadge ? (
            <span
              className="h-2 w-7 shrink-0 rounded-[3px]"
              style={{ backgroundColor: `color-mix(in srgb, ${REFETCH_ACCENT} 28%, transparent)` }}
              aria-hidden
            />
          ) : null}
          <AbstractBar className="h-1.5 bg-muted-foreground/40" width={titleWidth} />
        </div>
        <div className="flex items-center gap-1.5">
          <span className="size-2 shrink-0 rounded-[2px] bg-muted-foreground/20" aria-hidden />
          <AbstractBar className="h-[3px]" width={metaWidth} />
          <AbstractBar className="h-[3px] w-8" />
          <AbstractBar className="h-[3px] w-6" />
        </div>
      </div>
    </div>
  )
}

function RefetchSitePreview() {
  return (
    <div className="overflow-hidden bg-muted">
      <div
        className="flex h-6 w-full items-center justify-between gap-2 px-2.5"
        style={{ backgroundColor: REFETCH_HEADER_BG }}
      >
        <div className="flex min-w-0 items-center gap-2">
          <img
            src="/icons/refetch.svg"
            alt=""
            width={72}
            height={16}
            className="h-3.5 w-auto sm:h-4"
            aria-hidden
          />
          <span className="hidden h-3.5 w-8 rounded-[4px] border border-white/20 sm:block" aria-hidden />
          <span className="hidden h-1.5 w-28 rounded-sm bg-white/25 lg:block" aria-hidden />
        </div>
        <div className="flex shrink-0 items-center gap-1.5">
          <span className="hidden h-1.5 w-12 rounded-sm bg-white/25 sm:block" aria-hidden />
          <span className="h-3.5 w-9 rounded-[4px] bg-white/85" aria-hidden />
        </div>
      </div>
      <div className="mx-auto flex w-[88%] max-w-xl gap-2 py-2">
        <div className="hidden w-14 shrink-0 flex-col gap-1 sm:flex">
          <span className="h-3.5 w-full rounded-md border border-border/50 bg-background" aria-hidden />
          <AbstractBar className="h-3 w-[88%]" />
          <AbstractBar className="h-3 w-[72%]" />
          <AbstractBar className="h-3 w-[80%]" />
        </div>
        <div className="min-w-0 flex-1 space-y-1.5">
          <AbstractFeedCard titleWidth="88%" metaWidth="38%" showBadge />
          <AbstractFeedCard titleWidth="74%" metaWidth="32%" />
          <AbstractFeedCard titleWidth="82%" metaWidth="44%" />
        </div>
        <div className="hidden w-[4.5rem] shrink-0 flex-col gap-1.5 sm:flex">
          <div className="rounded-md border border-border/50 bg-background px-1.5 py-1.5">
            <AbstractBar className="h-[3px] w-10" />
            <div className="mt-1.5 flex items-center gap-1">
              <span className="size-1.5 rounded-full bg-emerald-500/65" aria-hidden />
              <AbstractBar className="h-[3px] w-8" />
            </div>
          </div>
          <div className="rounded-md border border-border/50 bg-background px-1.5 py-1.5">
            <AbstractBar className="h-[3px] w-9" />
            <svg viewBox="0 0 48 16" className="mt-1.5 h-4 w-full" aria-hidden>
              <polyline
                fill="none"
                stroke={REFETCH_MARK}
                strokeWidth="1.6"
                strokeLinejoin="round"
                strokeLinecap="round"
                points="0,12 8,10 16,13 24,5 32,8 40,3 48,7"
              />
            </svg>
          </div>
          <div className="hidden rounded-md border border-border/50 bg-background px-1.5 py-1.5 lg:block">
            <AbstractBar className="h-[3px] w-8" />
            <div className="mt-1.5 flex flex-wrap gap-1">
              <span className="h-2 w-6 rounded-sm bg-muted-foreground/15" aria-hidden />
              <span className="h-2 w-8 rounded-sm bg-muted-foreground/15" aria-hidden />
              <span className="h-2 w-5 rounded-sm bg-muted-foreground/15" aria-hidden />
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

function PipelineRow({
  children,
  className,
  revealDelayMs,
}: {
  children: React.ReactNode
  className?: string
  revealDelayMs?: number
}) {
  if (revealDelayMs === undefined) {
    return <div className={className}>{children}</div>
  }

  return (
    <div
      className={cn(
        'max-h-0 overflow-hidden opacity-0 transition-[max-height,opacity] duration-500 group-hover:max-h-28 group-hover:opacity-100 motion-reduce:group-hover:max-h-28 motion-reduce:group-hover:opacity-100',
        className,
      )}
      style={{ transitionDelay: `${revealDelayMs}ms` }}
    >
      {children}
    </div>
  )
}

export function SitesProductVisual() {
  const t = useT()
  const rootRef = useRef<HTMLDivElement>(null)
  const [isHovered, setIsHovered] = useState(false)
  const [buildSeconds, setBuildSeconds] = useState<number | null>(null)
  const [buildComplete, setBuildComplete] = useState(false)
  const [prefersReducedMotion, setPrefersReducedMotion] = useState(false)

  useEffect(() => {
    const media = window.matchMedia('(prefers-reduced-motion: reduce)')
    const update = () => setPrefersReducedMotion(media.matches)
    update()
    media.addEventListener('change', update)
    return () => media.removeEventListener('change', update)
  }, [])

  useEffect(() => {
    const group = rootRef.current?.closest('.group')
    if (!group) return

    const onEnter = () => setIsHovered(true)
    const onLeave = () => setIsHovered(false)
    setIsHovered(group.matches(':hover'))
    group.addEventListener('mouseenter', onEnter)
    group.addEventListener('mouseleave', onLeave)
    return () => {
      group.removeEventListener('mouseenter', onEnter)
      group.removeEventListener('mouseleave', onLeave)
    }
  }, [])

  useEffect(() => {
    if (!isHovered) {
      setBuildSeconds(null)
      setBuildComplete(false)
      return
    }

    if (prefersReducedMotion) {
      setBuildSeconds(BUILD_TOTAL_SECONDS)
      setBuildComplete(true)
      return
    }

    setBuildSeconds(0)
    setBuildComplete(false)

    const startedAt = Date.now()

    const tick = () => {
      const { seconds, complete } = getBuildProgress(Date.now() - startedAt)
      setBuildSeconds(seconds)
      setBuildComplete(complete)
    }

    tick()
    const tickId = window.setInterval(tick, 200)

    return () => {
      window.clearInterval(tickId)
    }
  }, [isHovered, prefersReducedMotion])

  return (
    <div ref={rootRef} className="absolute inset-0 flex flex-col overflow-hidden">
      <div className="flex h-full min-h-0 w-full flex-col justify-end space-y-3.5 transition-transform duration-500 group-hover:-translate-y-1 motion-reduce:group-hover:translate-y-0">
        <div className="mx-auto w-full max-w-[21rem] space-y-3.5">
          <div
            className={cn(
              'flex items-center gap-2.5 px-3.5 py-3 transition-[border-color,background-color] duration-300',
              productBentoContainer.panel,
              'group-hover:border-[color-mix(in_srgb,var(--brand-cta)_28%,var(--border))] group-hover:bg-background',
            )}
          >
            <div
              className="flex shrink-0 items-center gap-1.5"
              aria-label={t('GitHub and Origin')}
            >
              <span className="flex size-8 items-center justify-center rounded-md bg-muted/40">
                <ProductFeaturePublicIcon src="/icons/github.svg" />
              </span>
              <span className="flex size-8 items-center justify-center rounded-md bg-muted/40">
                <ProductFeaturePublicIcon src="/icons/origin.svg" />
              </span>
            </div>
            <div className="min-w-0 flex-1">
              <p className={cn('text-[12px] font-medium', productBentoIdle.text)}>
                {t('Push to main')}
              </p>
              <p className="mt-0.5 font-mono text-[11px] text-muted-foreground">{COMMIT_HASH}</p>
            </div>
            <ArrowRight
              className="size-4 shrink-0 text-muted-foreground/50 transition-[color,transform] duration-300 group-hover:translate-x-0.5 group-hover:text-[var(--brand-cta)] motion-reduce:group-hover:translate-x-0"
              aria-hidden
            />
          </div>

          <div className={cn(productBentoContainer.panel, 'px-3.5 py-3')}>
            <div className="flex items-center justify-between gap-2">
              <span className={cn('text-[12px] font-medium', productBentoIdle.text)}>
                {t('Build')}
              </span>
              <div className="flex items-center gap-2">
                <span
                  className={cn(
                    'min-w-[2rem] text-end font-mono text-[11px] tabular-nums text-muted-foreground transition-opacity duration-200',
                    buildSeconds === null && 'opacity-50',
                  )}
                >
                  {buildSeconds === null ? '-' : `${buildSeconds}s`}
                </span>
                <CheckCircle2
                  className={cn(
                    'size-4 shrink-0 text-emerald-600 transition-opacity duration-300 dark:text-emerald-400',
                    buildComplete ? 'opacity-100' : 'opacity-0',
                  )}
                  aria-hidden
                />
              </div>
            </div>
            <div className="mt-2.5 h-1.5 overflow-hidden rounded-full bg-muted">
              <div className={cn('product-bento-site-build h-full w-[6%] rounded-full motion-reduce:w-full', productBentoIdle.buildBar)} />
            </div>
          </div>

          <PipelineRow revealDelayMs={1400}>
            <div className={cn('flex items-start gap-2.5 px-3.5 py-3', productBentoContainer.panel)}>
              <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted/40">
                <img src="/icons/appwrite.svg" alt="" className="size-3.5" aria-hidden />
              </span>
              <div className="min-w-0 flex-1 space-y-1">
                <p className={cn('text-[12px] font-medium', productBentoIdle.text)}>
                  {t('Your site has been deployed.')}
                </p>
                <p className={cn('text-[11px]', productBentoIdle.link)}>{t('Open preview')}</p>
              </div>
            </div>
          </PipelineRow>
        </div>

        <PipelineRow
          revealDelayMs={1650}
          className="w-full px-2.5 sm:px-3.5 group-hover:max-h-56 sm:group-hover:max-h-64 motion-reduce:group-hover:max-h-56 sm:motion-reduce:group-hover:max-h-64"
        >
          <div className={cn('w-full overflow-hidden', productBentoContainer.shell)}>
            <div className="flex items-center gap-2 border-b border-border bg-muted/15 px-3 py-1.5">
              <span className="size-1.5 rounded-full bg-muted-foreground/25" aria-hidden />
              <span className="size-1.5 rounded-full bg-muted-foreground/25" aria-hidden />
              <span className="size-1.5 rounded-full bg-muted-foreground/25" aria-hidden />
              <span className="ms-1 min-w-0 flex-1 truncate rounded-md bg-muted/50 px-2 py-0.5 text-center font-mono text-[9px] text-muted-foreground">
                preview.appwrite.network
              </span>
            </div>
            <RefetchSitePreview />
          </div>
        </PipelineRow>
      </div>
    </div>
  )
}
