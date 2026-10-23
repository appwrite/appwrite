import { useTheme } from 'next-themes'
import { useCallback, useEffect, useRef, useState, type RefObject } from 'react'
import { isHtmlDarkChrome, isResolvedThemeDarkChrome } from '@/lib/html-theme'
import { FIVE_ASPECT } from '@/lib/init/init5-hero/five-field'
import type { Init5HeroVariantId } from '@/lib/init/init5-hero/hero-variant'
import type {
  Init5SceneController,
  Init5SceneFactory,
  Init5SceneLayout,
  Init5ScenePalette,
} from '@/lib/init/init5-hero/scene-types'
import { useInitLowPowerAnimationDecision } from '@/lib/init/use-init-low-power-animations'
import { cn } from '@/lib/utils'
import { INIT5_HERO_ANCHOR_ATTRIBUTE } from './init5/init5-hero-anchor'
import { useInit5Hero } from './init5/init5-hero-context'
import { Init5CompactMark, Init5StaticFive } from './init5/Init5FiveMark'

type Init5HeroBackgroundProps = {
  containerRef: RefObject<HTMLElement | null>
  compact?: boolean
  active?: boolean
  onInteractionStart?: () => void
  onInteractionEnd?: () => void
}

declare global {
  interface Window {
    /** Dev builds only: live Init 5 hero scene (`__init5Hero.seek(1.2)` to inspect a frame). */
    __init5Hero?: Init5SceneController
  }
}

const SCENE_LOADERS: Record<
  Init5HeroVariantId,
  () => Promise<Init5SceneFactory>
> = {
  particles: () =>
    import('@/lib/init/init5-hero/scenes/particles-scene').then(
      (mod) => mod.createParticlesScene,
    ),
  trails: () =>
    import('@/lib/init/init5-hero/scenes/light-trails-scene').then(
      (mod) => mod.createLightTrailsScene,
    ),
  glass: () =>
    import('@/lib/init/init5-hero/scenes/glass-scene').then(
      (mod) => mod.createGlassScene,
    ),
}

const DEFAULT_LAYOUT: Init5SceneLayout = { cx: 0.72, cy: 0.5, height: 0.62 }

/** Glyph fills this much of the anchor box, leaving room for tilt and particles. */
const ANCHOR_FILL = 0.84

function isDarkChrome(resolvedTheme: string | undefined) {
  if (resolvedTheme === 'light') return false
  if (resolvedTheme === 'dark') return true
  if (!resolvedTheme) return isHtmlDarkChrome()
  if (isResolvedThemeDarkChrome(resolvedTheme)) return true
  return isHtmlDarkChrome()
}

let colorProbe: CanvasRenderingContext2D | null = null

/** Any CSS color (hex, rgb, oklch, color-mix...) to `#rrggbb` via a 1px canvas. */
function cssColorToHex(value: string, fallback: string) {
  if (typeof document === 'undefined') return fallback
  if (!colorProbe) {
    const canvas = document.createElement('canvas')
    canvas.width = 1
    canvas.height = 1
    colorProbe = canvas.getContext('2d', { willReadFrequently: true })
  }
  const ctx = colorProbe
  if (!ctx || !value) return fallback
  ctx.clearRect(0, 0, 1, 1)
  ctx.fillStyle = fallback
  ctx.fillStyle = value
  ctx.fillRect(0, 0, 1, 1)
  const [r, g, b] = ctx.getImageData(0, 0, 1, 1).data
  return `#${[r, g, b].map((channel) => channel.toString(16).padStart(2, '0')).join('')}`
}

function readPalette(element: HTMLElement, isDark: boolean): Init5ScenePalette {
  const style = getComputedStyle(element)
  const read = (name: string, fallback: string) =>
    cssColorToHex(style.getPropertyValue(name).trim(), fallback)
  return {
    isDark,
    background: read('--background', isDark ? '#19191c' : '#ffffff'),
    foreground: read('--foreground', isDark ? '#fafafa' : '#18181b'),
    accent: read('--brand-cta', '#fd366e'),
    violet: read('--network-globe-edge', isDark ? '#9b8aff' : '#7c67fe'),
    peach: read('--network-globe-region', isDark ? '#ffb088' : '#fe9567'),
  }
}

function measureLayout(container: HTMLElement): Init5SceneLayout | null {
  const anchor = container.querySelector<HTMLElement>(
    `[${INIT5_HERO_ANCHOR_ATTRIBUTE}]`,
  )
  const box = container.getBoundingClientRect()
  if (!anchor || box.width <= 0 || box.height <= 0) return null
  const rect = anchor.getBoundingClientRect()
  if (rect.width <= 0 || rect.height <= 0) return null
  const glyphHeight = Math.min(
    rect.height * ANCHOR_FILL,
    (rect.width * ANCHOR_FILL) / FIVE_ASPECT,
  )
  return {
    cx: (rect.left + rect.width / 2 - box.left) / box.width,
    cy: (rect.top + rect.height / 2 - box.top) / box.height,
    height: glyphHeight / box.height,
  }
}

function isInteractiveTarget(target: EventTarget | null) {
  return (
    target instanceof Element &&
    Boolean(
      target.closest(
        'a, button, input, select, textarea, [role="button"], [role="radio"]',
      ),
    )
  )
}

function getScrollRoot() {
  return document.getElementById('main-content')
}

function Init5HeroScene({
  containerRef,
  active,
}: {
  containerRef: RefObject<HTMLElement | null>
  active: boolean
}) {
  const { variant, replayToken } = useInit5Hero()
  const hostRef = useRef<HTMLDivElement>(null)
  const controllerRef = useRef<Init5SceneController | null>(null)
  const activeRef = useRef(active)
  const visibleRef = useRef(true)
  const [ready, setReady] = useState(false)
  const [failed, setFailed] = useState(false)
  const [layout, setLayout] = useState<Init5SceneLayout | null>(null)
  const { resolvedTheme } = useTheme()
  const decision = useInitLowPowerAnimationDecision()
  const lowPower = decision.enabled
  const reducedMotion = decision.prefersReducedMotion
  const isDark = isDarkChrome(resolvedTheme)
  const isDarkRef = useRef(isDark)

  activeRef.current = active
  isDarkRef.current = isDark

  const syncActive = useCallback(() => {
    controllerRef.current?.setActive(
      activeRef.current &&
        visibleRef.current &&
        document.visibilityState === 'visible',
    )
  }, [])

  // Boot (or swap) the WebGL scene for the selected concept.
  useEffect(() => {
    const container = containerRef.current
    const host = hostRef.current
    if (!container || !host || !variant) return

    let disposed = false
    let controller: Init5SceneController | null = null
    setReady(false)
    setFailed(false)

    const canvas = document.createElement('canvas')
    canvas.className = 'absolute inset-0 block h-full w-full'
    canvas.setAttribute('aria-hidden', 'true')
    host.appendChild(canvas)

    SCENE_LOADERS[variant]()
      .then((createScene) => {
        if (disposed) return
        const box = container.getBoundingClientRect()
        try {
          controller = createScene({
            canvas,
            width: box.width,
            height: box.height,
            palette: readPalette(container, isDarkRef.current),
            layout: measureLayout(container) ?? DEFAULT_LAYOUT,
            quality: lowPower ? 'low' : 'high',
            reducedMotion,
            onReady: () => {
              if (!disposed) setReady(true)
            },
          })
        } catch (error) {
          console.warn(
            '[init5-hero] WebGL scene unavailable, using static 5.',
            error,
          )
          setFailed(true)
          return
        }
        controllerRef.current = controller
        if (import.meta.env.DEV) window.__init5Hero = controller
        syncActive()
      })
      .catch((error: unknown) => {
        if (disposed) return
        console.warn('[init5-hero] Failed to load hero scene.', error)
        setFailed(true)
      })

    return () => {
      disposed = true
      if (controllerRef.current === controller) controllerRef.current = null
      if (controller && window.__init5Hero === controller)
        delete window.__init5Hero
      controller?.dispose()
      canvas.remove()
    }
  }, [containerRef, variant, lowPower, reducedMotion, syncActive])

  useEffect(() => {
    syncActive()
  }, [active, syncActive])

  useEffect(() => {
    if (replayToken > 0) controllerRef.current?.replayIntro()
  }, [replayToken])

  // Theme changes: wait two frames so the new html class has applied.
  useEffect(() => {
    let outer = 0
    let inner = 0
    outer = requestAnimationFrame(() => {
      inner = requestAnimationFrame(() => {
        const container = containerRef.current
        if (!container) return
        controllerRef.current?.setPalette(
          readPalette(container, isDarkRef.current),
        )
      })
    })
    return () => {
      cancelAnimationFrame(outer)
      cancelAnimationFrame(inner)
    }
  }, [containerRef, resolvedTheme, isDark])

  // Size, anchor alignment, pointer, scroll, visibility.
  useEffect(() => {
    const container = containerRef.current
    if (!container) return
    const scrollRoot = getScrollRoot()
    let frame = 0

    const measure = () => {
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(() => {
        const box = container.getBoundingClientRect()
        const next = measureLayout(container) ?? DEFAULT_LAYOUT
        controllerRef.current?.resize(box.width, box.height)
        controllerRef.current?.setLayout(next)
        container.style.setProperty(
          '--init5-glow-x',
          `${(next.cx * 100).toFixed(2)}%`,
        )
        container.style.setProperty(
          '--init5-glow-y',
          `${(next.cy * 100).toFixed(2)}%`,
        )
        setLayout(next)
      })
    }

    const toLocal = (event: PointerEvent) => {
      const box = container.getBoundingClientRect()
      return {
        x: (event.clientX - box.left) / Math.max(1, box.width),
        y: (event.clientY - box.top) / Math.max(1, box.height),
      }
    }
    const handleMove = (event: PointerEvent) => {
      if (event.pointerType === 'touch' && !event.isPrimary) return
      const point = toLocal(event)
      controllerRef.current?.setPointer(point.x, point.y)
    }
    const handleLeave = () => controllerRef.current?.setPointer(null, null)
    const handleDown = (event: PointerEvent) => {
      if (isInteractiveTarget(event.target)) return
      const point = toLocal(event)
      controllerRef.current?.pulse(point.x, point.y)
    }
    const handleScroll = () => {
      const box = container.getBoundingClientRect()
      const rootTop = scrollRoot?.getBoundingClientRect().top ?? 0
      const progress = (rootTop - box.top) / Math.max(1, box.height * 0.85)
      controllerRef.current?.setScrollProgress(
        Math.min(1, Math.max(0, progress)),
      )
    }
    const handleVisibility = () => syncActive()

    const resizeObserver = new ResizeObserver(measure)
    resizeObserver.observe(container)
    const intersectionObserver = new IntersectionObserver(
      ([entry]) => {
        visibleRef.current = entry?.isIntersecting ?? true
        syncActive()
      },
      { root: scrollRoot, threshold: 0 },
    )
    intersectionObserver.observe(container)

    const scrollTarget: EventTarget = scrollRoot ?? window
    container.addEventListener('pointermove', handleMove, { passive: true })
    container.addEventListener('pointerleave', handleLeave)
    container.addEventListener('pointercancel', handleLeave)
    container.addEventListener('pointerdown', handleDown, { passive: true })
    scrollTarget.addEventListener('scroll', handleScroll, { passive: true })
    window.addEventListener('resize', measure)
    document.addEventListener('visibilitychange', handleVisibility)
    measure()
    handleScroll()

    return () => {
      cancelAnimationFrame(frame)
      resizeObserver.disconnect()
      intersectionObserver.disconnect()
      container.removeEventListener('pointermove', handleMove)
      container.removeEventListener('pointerleave', handleLeave)
      container.removeEventListener('pointercancel', handleLeave)
      container.removeEventListener('pointerdown', handleDown)
      scrollTarget.removeEventListener('scroll', handleScroll)
      window.removeEventListener('resize', measure)
      document.removeEventListener('visibilitychange', handleVisibility)
    }
  }, [containerRef, syncActive, variant])

  const fallbackLayout = layout ?? DEFAULT_LAYOUT

  return (
    <>
      <div
        ref={hostRef}
        aria-hidden
        className={cn(
          'pointer-events-none absolute inset-0 transition-opacity duration-700 ease-out',
          ready && !failed ? 'opacity-100' : 'opacity-0',
        )}
      />
      {failed ? (
        <Init5StaticFive
          className="pointer-events-none absolute -translate-x-1/2 -translate-y-1/2"
          style={{
            left: `${fallbackLayout.cx * 100}%`,
            top: `${fallbackLayout.cy * 100}%`,
            height: `${fallbackLayout.height * 100}%`,
          }}
        />
      ) : null}
    </>
  )
}

/**
 * Init 5 hero stage. Full hero: the WebGL concept picked in the switcher
 * (particles, light trails or glass). Compact sticky bar: lightweight SVG mark.
 */
export function Init5HeroBackground({
  containerRef,
  compact = false,
  active = true,
}: Init5HeroBackgroundProps) {
  const decision = useInitLowPowerAnimationDecision()

  if (compact) {
    return <Init5CompactMark animate={!decision.prefersReducedMotion} />
  }

  return (
    <>
      <div className="absolute inset-0 bg-background" aria-hidden />
      <Init5StageAtmosphere />
      <Init5HeroScene containerRef={containerRef} active={active} />
    </>
  )
}

/** CSS-only light behind the WebGL layer: visible instantly, before any chunk loads. */
function Init5StageAtmosphere() {
  return (
    <>
      <div
        aria-hidden
        className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_46%_56%_at_var(--init5-glow-x,72%)_var(--init5-glow-y,50%),color-mix(in_oklch,var(--brand-cta)_13%,transparent),transparent_72%)]"
      />
      <div
        aria-hidden
        className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:22px_22px] opacity-30 [mask-image:radial-gradient(ellipse_60%_70%_at_var(--init5-glow-x,72%)_var(--init5-glow-y,50%),black,transparent_75%)]"
      />
      <div
        aria-hidden
        className="pointer-events-none absolute inset-x-0 bottom-0 h-24 bg-gradient-to-b from-transparent to-background"
      />
    </>
  )
}
