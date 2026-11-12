import { useEffect, useMemo, useRef, useState, type RefObject } from 'react'
import { useLocation, useParams } from '@tanstack/react-router'
import { Shield, ShieldCheck, X } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useScreenshotMode } from '@/components/global/providers/ScreenshotMode'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  FIREWALL_SPIDER_PROMO_BANNER_ID,
  getConsoleBannerById,
  isConsoleBannerVisible,
  isFirewallSpiderPromoPath,
  useDebugConsoleBannerPreviews,
} from '@/lib/console-banners'
import { useT } from '@/lib/i18n/translate'
import { useDismissConsoleBanner } from '@/lib/react-query/hooks'
import { isConsoleBannerDismissed, type UserPrefs } from '@/lib/user-prefs-keys'
import { FirewallInfo } from './FirewallInfo'
import { createSpiderScene, type SpiderSceneController } from './spider-scene'
import type { Vec } from './spider-targets'

const FIREWALL_SPIDER_BANNER = getConsoleBannerById(
  FIREWALL_SPIDER_PROMO_BANNER_ID,
)!

/** Let the page settle so the spider has real UI to crawl over. */
const OPEN_DELAY_MS = 2600
/** Keep the 403 burst on screen after the spider has fled. */
const BLOCKED_LINGER_MS = 900
/** Let the 403 burst play before the info modal covers it. */
const BLOCKED_INFO_DELAY_MS = 1100
const BRAND_PINK = '#fd366e'
const LEG_INDEXES = [0, 1, 2, 3, 4, 5, 6, 7] as const

type ExitReason = 'blocked' | 'left'

function canAnimate(): boolean {
  if (typeof window === 'undefined' || !window.matchMedia) return false
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches)
    return false
  return window.matchMedia('(min-width: 1024px) and (pointer: fine)').matches
}

export function FirewallSpiderPromo() {
  const location = useLocation()
  const { projectId } = useParams({ strict: false })
  const { account, isAuthenticated } = useAuth()
  const { isScreenshotModeActive } = useScreenshotMode()
  const { isPreviewEnabled } = useDebugConsoleBannerPreviews()
  const dismissBanner = useDismissConsoleBanner()
  const [ready, setReady] = useState(false)
  const [finished, setFinished] = useState(false)
  const [infoOpen, setInfoOpen] = useState(false)
  const [run, setRun] = useState(0)
  const infoTimerRef = useRef(0)
  const dismissedRunRef = useRef<number | null>(null)

  const preview = isPreviewEnabled(FIREWALL_SPIDER_PROMO_BANNER_ID)
  const dismissed = useMemo(
    () =>
      isConsoleBannerDismissed(
        account?.prefs as UserPrefs | undefined,
        FIREWALL_SPIDER_PROMO_BANNER_ID,
      ),
    [account?.prefs],
  )

  // Project pages only, even in debug preview.
  const canShow =
    !finished &&
    !isScreenshotModeActive &&
    isFirewallSpiderPromoPath(location.pathname)
  const eligible =
    canShow &&
    (preview ||
      (isAuthenticated &&
        isConsoleBannerVisible(FIREWALL_SPIDER_BANNER, { dismissed })))

  useEffect(() => {
    if (!preview) return
    setFinished(false)
    setRun((value) => value + 1)
  }, [preview])

  useEffect(() => {
    if (!canShow) setReady(false)
  }, [canShow])

  // Once started, the spider plays out even after the dismissal is saved.
  useEffect(() => {
    if (ready || !eligible) return
    const timer = window.setTimeout(() => {
      if (canAnimate()) setReady(true)
    }, OPEN_DELAY_MS)
    return () => window.clearTimeout(timer)
  }, [eligible, ready, run])

  useEffect(() => () => window.clearTimeout(infoTimerRef.current), [])

  const dismiss = () => {
    if (!isAuthenticated || dismissedRunRef.current === run) return
    dismissedRunRef.current = run
    dismissBanner.mutate(FIREWALL_SPIDER_PROMO_BANNER_ID)
  }

  const openInfo = (delayMs: number) => {
    dismiss()
    window.clearTimeout(infoTimerRef.current)
    infoTimerRef.current = window.setTimeout(() => setInfoOpen(true), delayMs)
  }

  const handleFinish = () => {
    setFinished(true)
    setReady(false)
    dismiss()
  }

  return (
    <>
      {ready && canShow ? (
        <SpiderStage
          key={run}
          onBlock={() => openInfo(BLOCKED_INFO_DELAY_MS)}
          onLearnMore={() => openInfo(0)}
          onDismiss={dismiss}
          onFinish={handleFinish}
        />
      ) : null}
      {projectId ? (
        <FirewallInfo
          open={infoOpen}
          onOpenChange={setInfoOpen}
          projectId={projectId}
        />
      ) : null}
    </>
  )
}

function SpiderStage({
  onBlock,
  onLearnMore,
  onDismiss,
  onFinish,
}: {
  onBlock: () => void
  onLearnMore: () => void
  onDismiss: () => void
  onFinish: () => void
}) {
  const t = useT()
  const rootRef = useRef<HTMLDivElement>(null)
  const spiderRef = useRef<SVGGElement>(null)
  const abdomenRef = useRef<SVGGElement>(null)
  const femurRefs = useRef<Array<SVGPathElement | null>>([])
  const tibiaRefs = useRef<Array<SVGPathElement | null>>([])
  const ropeRef = useRef<SVGPathElement>(null)
  const draglineRef = useRef<SVGLineElement>(null)
  const silkRef = useRef<SVGPathElement>(null)
  const cardRef = useRef<HTMLElement>(null)
  const hitTargetRef = useRef<HTMLButtonElement>(null)
  const sceneRef = useRef<SpiderSceneController | null>(null)
  const reasonRef = useRef<ExitReason | null>(null)
  const onFinishRef = useRef(onFinish)
  onFinishRef.current = onFinish
  const [blockedAt, setBlockedAt] = useState<Vec | null>(null)

  useEffect(() => {
    const femurs = femurRefs.current.filter(Boolean) as SVGPathElement[]
    const tibias = tibiaRefs.current.filter(Boolean) as SVGPathElement[]
    if (
      !rootRef.current ||
      !spiderRef.current ||
      !abdomenRef.current ||
      !ropeRef.current ||
      !draglineRef.current ||
      !silkRef.current ||
      !cardRef.current ||
      !hitTargetRef.current ||
      femurs.length !== LEG_INDEXES.length ||
      tibias.length !== LEG_INDEXES.length
    ) {
      return
    }

    let lingerTimer = 0
    const scene = createSpiderScene(
      {
        root: rootRef.current,
        spider: spiderRef.current,
        abdomen: abdomenRef.current,
        femurs,
        tibias,
        rope: ropeRef.current,
        dragline: draglineRef.current,
        silk: silkRef.current,
        card: cardRef.current,
        hitTarget: hitTargetRef.current,
      },
      {
        onBlocked: (position) => setBlockedAt(position),
        onDone: () => {
          if (reasonRef.current === 'blocked') {
            lingerTimer = window.setTimeout(
              () => onFinishRef.current(),
              BLOCKED_LINGER_MS,
            )
          } else {
            onFinishRef.current()
          }
        },
      },
    )
    sceneRef.current = scene
    return () => {
      window.clearTimeout(lingerTimer)
      scene.destroy()
      sceneRef.current = null
    }
  }, [])

  const block = () => {
    if (reasonRef.current === 'blocked') return
    reasonRef.current = 'blocked'
    sceneRef.current?.setHeld(false)
    sceneRef.current?.block()
    onBlock()
  }

  const leave = () => {
    if (reasonRef.current) return false
    reasonRef.current = 'left'
    sceneRef.current?.setHeld(false)
    sceneRef.current?.leave()
    return true
  }

  const dismiss = () => {
    if (leave()) onDismiss()
  }

  const learnMore = () => {
    if (reasonRef.current === 'blocked') return
    leave()
    onLearnMore()
  }

  const hold = (held: boolean) => {
    if (reasonRef.current) return
    sceneRef.current?.setHeld(held)
  }

  return (
    <div
      ref={rootRef}
      data-firewall-spider=""
      className="pointer-events-none fixed inset-0 z-[60] overflow-hidden transition-opacity duration-200 [--spider-body:#1d1d21] [--spider-eye:rgba(255,255,255,0.85)] [--spider-leg:#1d1d21] [--spider-silk:rgba(63,63,70,0.55)] dark:[--spider-body:#d4d4d8] dark:[--spider-eye:#19191c] dark:[--spider-leg:#d4d4d8] dark:[--spider-silk:rgba(228,228,231,0.45)]"
    >
      <aside
        ref={cardRef}
        aria-label="Appwrite Firewall"
        className="pointer-events-auto absolute left-0 top-0 w-[252px] origin-top will-change-transform"
        style={{ transform: 'translate3d(-9999px, 0, 0)' }}
        onPointerEnter={() => hold(true)}
        onPointerLeave={() => hold(false)}
        onFocus={() => hold(true)}
        onBlur={(event) => {
          if (
            !event.currentTarget.contains(event.relatedTarget as Node | null)
          ) {
            hold(false)
          }
        }}
        onKeyDown={(event) => {
          if (event.key === 'Escape') dismiss()
        }}
      >
        <div className="relative rounded-xl border border-border bg-card p-3 shadow-[0_14px_36px_-14px_rgba(0,0,0,0.4)]">
          <span
            aria-hidden
            className="absolute left-1/2 top-0 size-2 -translate-x-1/2 -translate-y-1/2 rounded-full border border-border bg-background"
          />
          <div className="flex items-start gap-2.5">
            <div className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
              <Shield className="size-4" aria-hidden />
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-1.5">
                <p className="truncate text-[13px] font-medium text-foreground">
                  Appwrite Firewall
                </p>
                <Badge variant="info" className="text-[10px] shrink-0">
                  {t('New')}
                </Badge>
              </div>
              <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                {t(
                  'This one is harmless. Firewall stops the rest before they reach your API, Functions, or Sites.',
                )}
              </p>
            </div>
            <button
              type="button"
              aria-label={t('Dismiss')}
              className="-me-1 -mt-1 flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
              {...analyticsAttrs('firewall-spider-promo-dismiss')}
              onClick={dismiss}
            >
              <X className="size-3.5" aria-hidden />
            </button>
          </div>
          <div className="mt-3 flex items-center gap-2">
            <Button
              type="button"
              size="sm"
              className="h-8 flex-1 text-[12px]"
              disabled={blockedAt !== null}
              {...analyticsAttrs('firewall-spider-promo-block')}
              onClick={block}
            >
              {t('Block this crawler')}
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-8 text-[12px] text-muted-foreground"
              disabled={blockedAt !== null}
              {...analyticsAttrs('firewall-spider-promo-learn-more')}
              onClick={learnMore}
            >
              {t('Learn more')}
            </Button>
          </div>
        </div>
      </aside>

      <svg aria-hidden className="absolute inset-0 size-full overflow-visible">
        <defs>
          <filter
            id="firewall-spider-shadow"
            x="-50%"
            y="-50%"
            width="200%"
            height="200%"
          >
            <feDropShadow
              dx="0"
              dy="5"
              stdDeviation="3.5"
              floodColor="#000"
              floodOpacity="0.22"
            />
          </filter>
        </defs>

        <line
          ref={draglineRef}
          stroke="var(--spider-silk)"
          strokeWidth={0.9}
          opacity={0}
        />
        <path
          ref={ropeRef}
          fill="none"
          stroke="var(--spider-silk)"
          strokeWidth={1}
          strokeLinecap="round"
        />
        <path
          ref={silkRef}
          fill="none"
          stroke="var(--spider-silk)"
          strokeWidth={0.8}
          strokeLinecap="round"
          opacity={0}
        />

        <g
          filter="url(#firewall-spider-shadow)"
          fill="none"
          stroke="var(--spider-leg)"
          strokeLinecap="round"
        >
          {LEG_INDEXES.map((index) => (
            <g key={index}>
              <path
                ref={(node) => {
                  femurRefs.current[index] = node
                }}
                strokeWidth={2.2}
              />
              <path
                ref={(node) => {
                  tibiaRefs.current[index] = node
                }}
                strokeWidth={1.5}
              />
            </g>
          ))}
        </g>

        <g ref={spiderRef} transform="translate(-9999 0)">
          <SpiderBody abdomenRef={abdomenRef} />
        </g>
      </svg>

      <button
        ref={hitTargetRef}
        type="button"
        aria-label={t('Block this crawler')}
        className="pointer-events-auto absolute left-0 top-0 size-12 cursor-pointer rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring"
        style={{ transform: 'translate3d(-9999px, 0, 0)' }}
        disabled={blockedAt !== null}
        {...analyticsAttrs('firewall-spider-promo-block')}
        onClick={block}
      />

      {blockedAt ? <BlockedBurst position={blockedAt} /> : null}
    </div>
  )
}

function SpiderBody({
  abdomenRef,
}: {
  abdomenRef: RefObject<SVGGElement | null>
}) {
  return (
    <g filter="url(#firewall-spider-shadow)" fill="var(--spider-body)">
      <g ref={abdomenRef}>
        <ellipse cx={-5.5} cy={0} rx={2.4} ry={1.8} />
        <ellipse cx={-16} cy={0} rx={11} ry={9} />
      </g>
      <circle cx={2.5} cy={0} r={6.4} />
      <circle cx={6.6} cy={-2} r={0.95} fill="var(--spider-eye)" />
      <circle cx={6.6} cy={2} r={0.95} fill="var(--spider-eye)" />
    </g>
  )
}

function BlockedBurst({ position }: { position: Vec }) {
  const t = useT()
  const ringRefs = useRef<Array<HTMLSpanElement | null>>([])
  const pillRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    ringRefs.current.forEach((ring, index) => {
      ring?.animate(
        [
          { transform: 'translate(-50%, -50%) scale(0.2)', opacity: 0.9 },
          { transform: 'translate(-50%, -50%) scale(1.7)', opacity: 0 },
        ],
        {
          duration: 720,
          delay: index * 140,
          easing: 'cubic-bezier(0.2, 0.8, 0.2, 1)',
          fill: 'both',
        },
      )
    })
    pillRef.current?.animate(
      [
        { transform: 'translate(-50%, -48px) scale(0.85)', opacity: 0 },
        {
          transform: 'translate(-50%, -64px) scale(1)',
          opacity: 1,
          offset: 0.18,
        },
        {
          transform: 'translate(-50%, -66px) scale(1)',
          opacity: 1,
          offset: 0.8,
        },
        { transform: 'translate(-50%, -76px) scale(0.98)', opacity: 0 },
      ],
      { duration: 1700, easing: 'ease-out', fill: 'both' },
    )
  }, [])

  return (
    <div
      className="pointer-events-none absolute"
      style={{ left: position.x, top: position.y }}
    >
      {[0, 1].map((index) => (
        <span
          key={index}
          ref={(node) => {
            ringRefs.current[index] = node
          }}
          className="absolute left-0 top-0 size-28 rounded-full border-2"
          style={{ borderColor: BRAND_PINK, opacity: 0 }}
        />
      ))}
      <div
        ref={pillRef}
        className="absolute left-0 top-0 flex items-center gap-1.5 whitespace-nowrap rounded-full border border-border bg-card px-2.5 py-1 text-[11px] font-medium text-foreground shadow-md"
        style={{ opacity: 0 }}
      >
        <ShieldCheck
          className="size-3.5"
          style={{ color: BRAND_PINK }}
          aria-hidden
        />
        <span className="font-mono">403</span>
        <span className="text-muted-foreground">
          {t('Blocked by Firewall')}
        </span>
      </div>
    </div>
  )
}
