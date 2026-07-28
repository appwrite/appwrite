import { useEffect, useState } from 'react'
import { ArrowRight, CheckCircle2 } from 'lucide-react'
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
    <div
      className="absolute inset-0 flex flex-col overflow-hidden"
      onMouseEnter={() => setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
    >
      <div className="mx-auto flex h-full min-h-0 w-full max-w-[21rem] flex-col justify-end space-y-3.5 transition-transform duration-500 group-hover:-translate-y-1 motion-reduce:group-hover:translate-y-0">
        <div
          className={cn(
            'flex items-center gap-2.5 px-3.5 py-3 transition-[border-color,background-color] duration-300',
            productBentoContainer.panel,
            'group-hover:border-[color-mix(in_srgb,var(--brand-cta)_28%,var(--border))] group-hover:bg-background',
          )}
        >
          <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted/40">
            <img src="/icons/github.svg" alt="" className="size-4" aria-hidden />
          </span>
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

        <PipelineRow revealDelayMs={1650} className="group-hover:max-h-36 sm:group-hover:max-h-40">
          <div className={cn('overflow-hidden p-2.5', productBentoContainer.panel)}>
            <div className="overflow-hidden rounded-md border border-border/80 bg-card">
              <div className="flex items-center gap-1.5 border-b border-border/80 bg-muted/15 px-2.5 py-1.5">
                <span className="size-1.5 rounded-full bg-muted-foreground/25" aria-hidden />
                <span className="size-1.5 rounded-full bg-muted-foreground/25" aria-hidden />
                <span className="size-1.5 rounded-full bg-muted-foreground/25" aria-hidden />
                <span className="ms-0.5 truncate font-mono text-[9px] text-muted-foreground">
                  preview.appwrite.network
                </span>
              </div>
              <div className="space-y-2 bg-muted/8 p-3">
                <div className="flex items-center gap-1.5">
                  <span
                    className={cn('size-2 rounded-full', productBentoIdle.brandDot)}
                    aria-hidden
                  />
                  <span className={cn('text-[11px] font-semibold', productBentoIdle.text)}>Appwrite</span>
                </div>
                <p className={cn('text-[11px] font-medium leading-tight', productBentoIdle.text)}>
                  {t('Ship faster with Appwrite')} {/* pragma: allowlist secret */}
                </p>
                <div
                  className="h-2 w-full max-w-[11rem] rounded-sm bg-muted-foreground/10"
                  aria-hidden
                />
                <div
                  className={cn('h-4 w-14 rounded-sm', productBentoIdle.ctaBlock)}
                  aria-hidden
                />
              </div>
            </div>
          </div>
        </PipelineRow>
      </div>
    </div>
  )
}
