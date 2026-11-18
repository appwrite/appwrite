'use client'

import { createClientOnlyFn } from '@tanstack/react-start'
import { Share2 } from 'lucide-react'
import {
  useEffect,
  useLayoutEffect,
  useRef,
  useState,
  type PointerEvent as ReactPointerEvent,
} from 'react'
import { useT } from '@/lib/i18n/translate'
import { PRODUCT_REGISTRY } from '@/lib/products/registry'
import { cn } from '@/lib/utils'
import type { FactoryLabels, FactoryMount } from './factory/boot'
import { factoryBoot, holdFactory, takeFactory } from './factory/early'
import type { FactoryEngine } from './factory/engine'
import { STATION_IDS, type FactoryStationId } from './factory/layout'

const importFactory = createClientOnlyFn(() => import('./factory/boot'))
let factory: ReturnType<typeof importFactory> | null = null
/**
 * The factory (the engine and three.js) downloads once, starting as early as
 * the stage renders (on a page served with it, it is already building: see
 * `early.ts`), along with the brand face's medium weight the machines print in.
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

const isRtl = () =>
  typeof document !== 'undefined' && document.documentElement.dir === 'rtl'

/**
 * The hero's 3D software factory. It starts before the page hydrates, from
 * the labels and icons this renders (`data-factory-labels`,
 * `data-factory-icons`), renders only while on screen and never above
 * 60 fps. Product names are shown on the machines; hovering one unfolds a
 * display that explains the product, clear of the hero's title and
 * description laid over the scene (`data-factory-copy`).
 */
export function FactoryStage({ className }: { className?: string }) {
  const t = useT()
  // Start downloading the engine while the page hydrates, not after it has.
  if (typeof window !== 'undefined') void loadFactory()
  const containerRef = useRef<HTMLDivElement>(null)
  const engineRef = useRef<FactoryEngine | null>(null)
  const [fallback, setFallback] = useState(false)

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
  const labels: FactoryLabels = {
    names: plates,
    descriptions,
    counterLabel,
    promptLabel,
  }
  const labelsKey = `${plates.join('|')}|${descriptions.join('|')}|${counterLabel}|${promptLabel}`
  const platesRef = useRef(plates)
  platesRef.current = plates
  const descriptionsRef = useRef(descriptions)
  descriptionsRef.current = descriptions
  const counterLabelRef = useRef(counterLabel)
  counterLabelRef.current = counterLabel
  const promptLabelRef = useRef(promptLabel)
  promptLabelRef.current = promptLabel

  // Before the page paints: a factory already running for this page (started
  // before hydration, or left by this stage as the page remounted it) moves
  // in and keeps going; otherwise one starts here.
  useLayoutEffect(() => {
    const container = containerRef.current
    if (!container) return
    let alive = true
    let mount: FactoryMount | null = null
    const use = (next: FactoryMount | null) => {
      if (!alive) {
        if (next) holdFactory(next, true)
        return
      }
      if (!next) return setFallback(true)
      mount = next
      next.moveTo(container)
      engineRef.current = next.engine
      next.onError(() => {
        if (alive) setFallback(true)
      })
    }
    const running = takeFactory()
    if (running) use(running)
    else
      void factoryBoot
        .then(
          () =>
            takeFactory() ??
            loadFactory()!.then(({ startFactory }) => startFactory(container)),
        )
        .then(use)
        .catch(() => {
          if (alive) setFallback(true)
        })

    return () => {
      alive = false
      engineRef.current = null
      // Left running for a moment rather than disposed: when the page
      // remounts the stage, the new one takes it straight back.
      if (mount) holdFactory(mount, true)
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
      data-factory-stage
      data-factory-labels={JSON.stringify(labels)}
      className={cn('factory-stage relative w-full select-none', className)}
      onPointerMove={handlePointerMove}
      onPointerLeave={handlePointerLeave}
    >
      <canvas
        role="img"
        aria-label={t(
          'An animated software factory where every Appwrite service builds apps on one production line',
        )}
        className={cn(
          'absolute inset-0 block h-full w-full',
          fallback ? 'hidden' : '',
        )}
      />

      {fallback ? (
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
      <div data-factory-icons className="hidden" aria-hidden>
        {STATION_IDS.map((id) => {
          const Icon = PRODUCT_REGISTRY[id].icon
          return <Icon key={id} strokeWidth={2} />
        })}
        {/* Appwrite Network's mark, as in the Docs navigation. */}
        <Share2 strokeWidth={2} />
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
