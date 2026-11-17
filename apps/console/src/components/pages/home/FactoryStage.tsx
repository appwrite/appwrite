'use client'

import { createClientOnlyFn } from '@tanstack/react-start'
import { Globe } from 'lucide-react'
import {
  useEffect,
  useRef,
  useState,
  type MouseEvent as ReactMouseEvent,
  type PointerEvent as ReactPointerEvent,
} from 'react'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { isHtmlDarkChrome } from '@/lib/html-theme'
import { useT } from '@/lib/i18n/translate'
import { PRODUCT_REGISTRY } from '@/lib/products/registry'
import { cn } from '@/lib/utils'
import { canUseWebGL } from '@/lib/webgl'
import { STATION_IDS, type FactoryStationId } from './factory/layout'
import type { FactoryEngine } from './factory/engine'

const loadFactory = createClientOnlyFn(() => import('./factory/engine'))

/** Hero console screenshots, used only when WebGL is unavailable. */
const FALLBACK_LIGHT_SRC = '/images/heroes/console-app-light.avif?v=20260729'
const FALLBACK_DARK_SRC = '/images/heroes/console-app-dark.avif?v=20260729'

/** The gate at the end of the line; a product name, so it is not translated. */
const NETWORK_NAME = 'Appwrite Network'

/** Large screens put the copy over the scene; smaller ones stack it above. */
const OVERLAY_QUERY = '(min-width: 1024px)'

type StageState = 'loading' | 'ready' | 'fallback'

const isRtl = () =>
  typeof document !== 'undefined' && document.documentElement.dir === 'rtl'

/**
 * The hero's 3D software factory. The engine (three.js) loads after
 * hydration, renders only while on screen and never above 60 fps. Product
 * names are shown on the machines; hovering or focusing one unfolds a
 * display that explains the product, and every machine links to its page.
 * `onCoverChange` reports when an open display covers the copy laid over
 * the scene, so the copy can step back.
 */
export function FactoryStage({
  className,
  onCoverChange,
}: {
  className?: string
  onCoverChange?: (covering: boolean) => void
}) {
  const t = useT()
  const containerRef = useRef<HTMLDivElement>(null)
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const linkRefs = useRef<(HTMLLIElement | null)[]>([])
  const iconRefs = useRef<(SVGSVGElement | null)[]>([])
  const engineRef = useRef<FactoryEngine | null>(null)
  const [state, setState] = useState<StageState>('loading')
  const [hovered, setHovered] = useState(-1)

  const names = STATION_IDS.map((id) => t(PRODUCT_REGISTRY[id].name))
  /** What the machine prints: every station's name, then the gate's sign. */
  const plates = [...names, NETWORK_NAME]
  /** What each unfolded display says: up to three lines, ending in the brand underscore. */
  const copy: Record<FactoryStationId, string> = {
    auth: t(
      'Sign users in with email, OAuth, or passkeys, plus MFA, teams, and roles',
    ),
    databases: t(
      'Postgres, MySQL, tables, documents, and vectors, managed in one project',
    ),
    storage: t(
      'Upload files, resize images on the fly, and deliver them over a global CDN',
    ),
    functions: t(
      'Run Node, Python, Go, and more on requests, schedules, or events',
    ),
    realtime: t(
      'Push every database, file, and auth change to your clients as it happens',
    ),
    messaging: t(
      'Send email, SMS, and push to users or topics, now or on a schedule',
    ),
    firewall: t(
      'Block, rate limit, or redirect traffic before it reaches your app',
    ),
    sites: t(
      'Deploy any framework from Git, with a live preview for every branch',
    ),
  }
  const descriptions = STATION_IDS.map((id) => copy[id])
  const counterLabel = t('Projects shipped')
  const labelsKey = `${plates.join('|')}|${descriptions.join('|')}|${counterLabel}`
  const platesRef = useRef(plates)
  platesRef.current = plates
  const descriptionsRef = useRef(descriptions)
  descriptionsRef.current = descriptions
  const counterLabelRef = useRef(counterLabel)
  counterLabelRef.current = counterLabel
  const onCoverChangeRef = useRef(onCoverChange)
  onCoverChangeRef.current = onCoverChange

  useEffect(() => {
    const container = containerRef.current
    const canvas = canvasRef.current
    if (!container || !canvas) return
    if (!canUseWebGL()) {
      setState('fallback')
      return
    }

    let disposed = false
    let engine: FactoryEngine | null = null
    let visible = false
    const cleanups: (() => void)[] = []
    const reducedMotion = window.matchMedia(
      '(prefers-reduced-motion: reduce)',
    ).matches
    const syncActive = () =>
      engine?.setActive(visible && document.visibilityState === 'visible')

    void loadFactory()!
      .then(({ createFactoryEngine }) => {
        if (disposed) return
        engine = createFactoryEngine({
          canvas,
          icons: iconRefs.current.map((icon) => icon?.outerHTML ?? ''),
          names: platesRef.current,
          descriptions: descriptionsRef.current,
          counterLabel: counterLabelRef.current,
          rtl: isRtl(),
          dark: isHtmlDarkChrome(),
          reducedMotion,
          onReady: () => {
            if (!disposed) setState('ready')
          },
          onHover: (index) => {
            if (!disposed) setHovered(index)
          },
          onError: () => {
            if (!disposed) setState('fallback')
          },
        })
        engineRef.current = engine

        const overlay = window.matchMedia(OVERLAY_QUERY)
        // Where the hero copy sits over the scene, so unfolding displays can keep clear of it.
        const syncCopyArea = () => {
          const copy = container
            .closest('section')
            ?.querySelector('[data-factory-copy]')
          if (!overlay.matches || !copy) return engine?.setCopyArea(null)
          const stage = container.getBoundingClientRect()
          const area = copy.getBoundingClientRect()
          engine?.setCopyArea({
            left: area.left - stage.left,
            top: area.top - stage.top,
            right: area.right - stage.left,
            bottom: area.bottom - stage.top,
          })
        }
        const syncComposition = () => {
          engine?.setComposition(overlay.matches ? 'overlay' : 'band')
          syncCopyArea()
        }
        syncComposition()
        overlay.addEventListener('change', syncComposition)
        cleanups.push(() =>
          overlay.removeEventListener('change', syncComposition),
        )

        const rect = container.getBoundingClientRect()
        engine.setSize(rect.width, rect.height)
        const resize = new ResizeObserver(([entry]) => {
          if (entry)
            engine?.setSize(entry.contentRect.width, entry.contentRect.height)
          syncCopyArea()
        })
        resize.observe(container)
        cleanups.push(() => resize.disconnect())

        const intersection = new IntersectionObserver(
          ([entry]) => {
            visible = !!entry?.isIntersecting
            syncActive()
          },
          { rootMargin: '80px 0px' },
        )
        intersection.observe(container)
        cleanups.push(() => intersection.disconnect())

        document.addEventListener('visibilitychange', syncActive)
        cleanups.push(() =>
          document.removeEventListener('visibilitychange', syncActive),
        )

        const syncTheme = () => engine?.setTheme(isHtmlDarkChrome())
        const themeObserver = new MutationObserver(syncTheme)
        themeObserver.observe(document.documentElement, {
          attributes: true,
          attributeFilter: ['class'],
        })
        const media = window.matchMedia('(prefers-color-scheme: dark)')
        media.addEventListener('change', syncTheme)
        cleanups.push(() => {
          themeObserver.disconnect()
          media.removeEventListener('change', syncTheme)
        })
      })
      .catch(() => {
        if (!disposed) setState('fallback')
      })

    return () => {
      disposed = true
      for (const cleanup of cleanups) cleanup()
      engine?.dispose()
      engineRef.current = null
    }
    // The engine is created once; language and theme changes are pushed to it.
  }, [])

  // Reprint the nameplates, descriptions and counter when the language changes.
  useEffect(() => {
    engineRef.current?.setNames(
      platesRef.current,
      descriptionsRef.current,
      isRtl(),
    )
    engineRef.current?.setCounterLabel(counterLabelRef.current)
  }, [labelsKey])

  // The copy steps back while the open display would sit under it.
  useEffect(() => {
    onCoverChangeRef.current?.(
      hovered >= 0 && (engineRef.current?.coversCopy(hovered) ?? false),
    )
  }, [hovered])

  const handlePointerMove = (event: ReactPointerEvent<HTMLDivElement>) => {
    if (event.pointerType === 'touch') return
    const rect = event.currentTarget.getBoundingClientRect()
    engineRef.current?.setPointer(
      event.clientX - rect.left,
      event.clientY - rect.top,
    )
  }

  const handlePointerLeave = () => engineRef.current?.setPointer(null, null)

  const handleClick = (event: ReactMouseEvent<HTMLDivElement>) => {
    if (hovered < 0 || (event.target as HTMLElement).closest('a')) return
    linkRefs.current[hovered]?.querySelector('a')?.click()
  }

  const ready = state === 'ready'

  return (
    <div
      ref={containerRef}
      className={cn(
        'factory-stage relative w-full select-none',
        hovered >= 0 ? 'cursor-pointer' : '',
        className,
      )}
      onPointerMove={handlePointerMove}
      onPointerLeave={handlePointerLeave}
      onClick={handleClick}
    >
      <canvas
        ref={canvasRef}
        role="img"
        aria-label={t(
          'An animated software factory where every Appwrite service builds apps on one production line',
        )}
        className={cn(
          'absolute inset-0 block h-full w-full',
          state === 'fallback' ? 'hidden' : '',
        )}
      />

      {state === 'fallback' ? (
        <div className="absolute inset-x-4 top-6 bottom-0 overflow-hidden rounded-t-[20px] border-x-2 border-t-2 border-muted-foreground/8 bg-muted-foreground/[0.035] p-1.5 sm:inset-x-10 lg:top-[34%] lg:right-[-6%] lg:left-[46%] dark:border-muted/30 dark:bg-muted/10">
          <img
            src={FALLBACK_LIGHT_SRC}
            alt=""
            className="block h-full w-full rounded-t-lg object-cover object-top dark:hidden"
          />
          <img
            src={FALLBACK_DARK_SRC}
            alt=""
            className="hidden h-full w-full rounded-t-lg object-cover object-top dark:block"
          />
        </div>
      ) : null}

      {/* Icon sources for the printed nameplates (the engine reads their SVG). */}
      <div className="hidden" aria-hidden>
        {STATION_IDS.map((id, index) => {
          const Icon = PRODUCT_REGISTRY[id].icon
          return (
            <Icon
              key={id}
              ref={(node: SVGSVGElement | null) => {
                iconRefs.current[index] = node
              }}
              strokeWidth={2}
            />
          )
        })}
        <Globe
          ref={(node: SVGSVGElement | null) => {
            iconRefs.current[STATION_IDS.length] = node
          }}
          strokeWidth={2}
        />
      </div>

      {/* The machines as links: reachable by keyboard and screen readers. */}
      <ul className="sr-only">
        {STATION_IDS.map((id, index) => (
          <li
            key={id}
            ref={(node) => {
              linkRefs.current[index] = node
            }}
          >
            <MarketingSiteLink
              href={PRODUCT_REGISTRY[id].path}
              tabIndex={ready ? 0 : -1}
              {...analyticsAttrs('home-factory-station')}
              onFocus={() => {
                setHovered(index)
                engineRef.current?.setHighlight(index)
              }}
              onBlur={() => {
                setHovered(-1)
                engineRef.current?.setHighlight(-1)
              }}
            >
              {`${names[index]}: ${descriptions[index]}`}
            </MarketingSiteLink>
          </li>
        ))}
      </ul>
    </div>
  )
}
