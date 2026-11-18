'use client'

import { createClientOnlyFn } from '@tanstack/react-start'
import { Share2 } from 'lucide-react'
import {
  useEffect,
  useRef,
  useState,
  type PointerEvent as ReactPointerEvent,
} from 'react'
import { isHtmlDarkChrome } from '@/lib/html-theme'
import { useT } from '@/lib/i18n/translate'
import { PRODUCT_REGISTRY } from '@/lib/products/registry'
import { cn } from '@/lib/utils'
import { STATION_IDS, type FactoryStationId } from './factory/layout'
import type { FactoryEngine } from './factory/engine'

const importFactory = createClientOnlyFn(() => import('./factory/engine'))
let factory: ReturnType<typeof importFactory> | null = null
/**
 * The engine (and three.js) download once, starting as early as the stage
 * renders, along with the brand face's medium weight the machines print in.
 */
const loadFactory = () => {
  if (!factory) {
    factory = importFactory()
    // A failed download is handled where the engine is created (the fallback).
    factory?.catch(() => {})
    const face =
      getComputedStyle(document.documentElement)
        .getPropertyValue('--font-aeonik-pro')
        .trim() || "'Aeonik Pro'"
    document.fonts?.load(`500 100px ${face}`).catch(() => {})
  }
  return factory
}

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
 * names are shown on the machines; hovering one unfolds a display that
 * explains the product, clear of the hero's title and description laid
 * over the scene (`data-factory-copy`).
 */
export function FactoryStage({ className }: { className?: string }) {
  const t = useT()
  // Start downloading the engine while the page hydrates, not after it has.
  if (typeof window !== 'undefined') void loadFactory()
  const containerRef = useRef<HTMLDivElement>(null)
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const iconRefs = useRef<(SVGSVGElement | null)[]>([])
  const engineRef = useRef<FactoryEngine | null>(null)
  const [state, setState] = useState<StageState>('loading')

  const names = STATION_IDS.map((id) => t(PRODUCT_REGISTRY[id].name))
  /** What the machine prints: every station's name, then the gate's sign. */
  const plates = [...names, NETWORK_NAME]
  /** What each unfolded display says: up to three lines, ending in the brand underscore. */
  const copy: Record<FactoryStationId, string> = {
    auth: t(
      'Sign users in with email, OAuth, or passkeys, plus MFA, teams, and roles',
    ),
    databases: t(
      'Run managed Postgres and MySQL, or store tables, documents, and vectors in one project',
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
  const promptLabel = t('Prompt')
  const labelsKey = `${plates.join('|')}|${descriptions.join('|')}|${counterLabel}|${promptLabel}`
  const platesRef = useRef(plates)
  platesRef.current = plates
  const descriptionsRef = useRef(descriptions)
  descriptionsRef.current = descriptions
  const counterLabelRef = useRef(counterLabel)
  counterLabelRef.current = counterLabel
  const promptLabelRef = useRef(promptLabel)
  promptLabelRef.current = promptLabel

  useEffect(() => {
    const container = containerRef.current
    const canvas = canvasRef.current
    if (!container || !canvas) return
    // No separate WebGL probe: it would create a context only to drop it. If
    // the engine cannot get one, creating it throws and the stage falls back.

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
          promptLabel: promptLabelRef.current,
          rtl: isRtl(),
          dark: isHtmlDarkChrome(),
          reducedMotion,
          onReady: () => {
            if (!disposed) setState('ready')
          },
          onError: () => {
            if (!disposed) setState('fallback')
          },
        })
        engineRef.current = engine

        const overlay = window.matchMedia(OVERLAY_QUERY)
        // Where each line of the hero's title and description sits over the
        // scene (`data-factory-copy`), so unfolding displays can keep clear
        // of the words while rising beside a short line.
        const syncCopyArea = () => {
          if (!overlay.matches) return engine?.setCopyLines([])
          const stage = container.getBoundingClientRect()
          const range = document.createRange()
          const copy = [
            ...(container
              .closest('section')
              ?.querySelectorAll('[data-factory-copy]') ?? []),
          ]
          engine?.setCopyLines(
            copy.flatMap((element) => {
              range.selectNodeContents(element)
              return [...range.getClientRects()]
                .filter((line) => line.width > 0 && line.height > 0)
                .map((line) => ({
                  left: line.left - stage.left,
                  top: line.top - stage.top,
                  right: line.right - stage.left,
                  bottom: line.bottom - stage.top,
                }))
            }),
          )
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
        // The lines move once the brand fonts arrive.
        void document.fonts?.ready.then(() => {
          if (!disposed) syncCopyArea()
        })

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
    engineRef.current?.setPromptLabel(promptLabelRef.current)
  }, [labelsKey])

  const handlePointerMove = (event: ReactPointerEvent<HTMLDivElement>) => {
    if (event.pointerType === 'touch') return
    const rect = event.currentTarget.getBoundingClientRect()
    engineRef.current?.setPointer(
      event.clientX - rect.left,
      event.clientY - rect.top,
    )
  }

  const handlePointerLeave = () => engineRef.current?.setPointer(null, null)

  return (
    <div
      ref={containerRef}
      className={cn('factory-stage relative w-full select-none', className)}
      onPointerMove={handlePointerMove}
      onPointerLeave={handlePointerLeave}
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
        {/* Appwrite Network's mark, as in the Docs navigation. */}
        <Share2
          ref={(node: SVGSVGElement | null) => {
            iconRefs.current[STATION_IDS.length] = node
          }}
          strokeWidth={2}
        />
      </div>

      {/* What the displays say, for screen readers. */}
      <ul className="sr-only">
        {STATION_IDS.map((id, index) => (
          <li key={id}>{`${names[index]}: ${descriptions[index]}`}</li>
        ))}
      </ul>
    </div>
  )
}
