import { isHtmlDarkChrome, isResolvedThemeDarkChrome } from '@/lib/html-theme'
import { useTheme } from 'next-themes'
import { useEffect, useRef, type RefObject } from 'react'
import { animate, createTimeline, createTimer, stagger, utils } from 'animejs'
import { cn } from '@/lib/utils'

const ROWS = 13
const GRID: [number, number] = [ROWS, ROWS]
const FROM = 'center'
const PARTICLE_BRIGHTNESS = 0.8

type InitHeroBackgroundProps = {
  containerRef: RefObject<HTMLElement | null>
  compact?: boolean
  /** When false, timers pause (e.g. hidden collapsed header). */
  active?: boolean
}

type ParticleTheme = ReturnType<typeof getParticleTheme>

type AnimationRuntime = {
  particleEls: NodeListOf<Element>
  scaleStagger: ReturnType<typeof stagger>
  grid: [number, number]
  from: string
  mainLoop: ReturnType<typeof createTimer>
  autoMove: ReturnType<typeof createTimeline>
  manualMovementTimeout: ReturnType<typeof createTimer>
  syncLayout: () => void
  pause: () => void
  resume: () => void
}

type ThemeState = {
  particleTheme: ParticleTheme
  opacityStagger: ReturnType<typeof stagger>
}

function getBrandPink() {
  if (typeof document === 'undefined') return '#fd366e'
  return (
    getComputedStyle(document.documentElement).getPropertyValue('--brand-cta').trim() ||
    '#fd366e'
  )
}

/** Prefer resolvedTheme for light/dark so stale <html> classes don't linger after toggling. */
function isDarkChrome(resolvedTheme: string | undefined) {
  if (resolvedTheme === 'light') return false
  if (resolvedTheme === 'dark') return true
  if (!resolvedTheme) return isHtmlDarkChrome()
  if (isResolvedThemeDarkChrome(resolvedTheme)) return true
  return isHtmlDarkChrome()
}

function dim(value: number) {
  return value * PARTICLE_BRIGHTNESS
}

function dimBrandPink(color: string) {
  return `color-mix(in srgb, ${color} ${PARTICLE_BRIGHTNESS * 100}%, transparent)`
}

function getParticleTheme(isDark: boolean): ParticleTheme {
  if (isDark) {
    return {
      opacityRange: [dim(1), dim(0.5)] as [number, number],
      lightnessRange: [dim(80), dim(50)] as [number, number],
      shadowRange: [dim(5), dim(1)] as [number, number],
      pulseOpacity: dim(1),
    }
  }

  return {
    opacityRange: [0.95, 0.6] as [number, number],
    lightnessRange: [72, 56] as [number, number],
    shadowRange: [8, 2] as [number, number],
    pulseOpacity: 1,
  }
}

function applyParticleTheme(
  runtime: Pick<
    AnimationRuntime,
    'particleEls' | 'scaleStagger' | 'grid' | 'from'
  >,
  particleTheme: ParticleTheme,
  isDark: boolean,
) {
  const { particleEls, scaleStagger, grid, from } = runtime
  const brandPink = getBrandPink()
  const opacityStagger = stagger(particleTheme.opacityRange, { grid, from })
  const shadowColor = isDark
    ? dimBrandPink(brandPink)
    : `color-mix(in srgb, ${brandPink} 90%, transparent)`

  utils.set(particleEls, {
    scale: scaleStagger,
    opacity: opacityStagger,
    background: stagger(particleTheme.lightnessRange, {
      grid,
      from,
      modifier: (v) => `hsl(344, 98%, ${v}%)`,
    }),
    boxShadow: stagger(particleTheme.shadowRange, {
      grid,
      from,
      modifier: (v) => `0px 0px ${utils.round(v, 0)}em 0px ${shadowColor}`,
    }),
  })

  return opacityStagger
}

export function InitHeroBackground({
  containerRef,
  compact = false,
  active = true,
}: InitHeroBackgroundProps) {
  const creatureRef = useRef<HTMLDivElement>(null)
  const runtimeRef = useRef<AnimationRuntime | null>(null)
  const themeStateRef = useRef<ThemeState | null>(null)
  const compactRef = useRef(compact)
  const activeRef = useRef(active)
  const canRunRef = useRef(true)
  const { resolvedTheme } = useTheme()
  const isDark = isDarkChrome(resolvedTheme)

  compactRef.current = compact
  activeRef.current = active

  useEffect(() => {
    const runtime = runtimeRef.current
    if (!runtime) return
    if (active && canRunRef.current) {
      runtime.resume()
    } else {
      runtime.pause()
    }
  }, [active])

  useEffect(() => {
    const container = containerRef.current
    const creatureEl = creatureRef.current
    if (!container || !creatureEl) return

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    if (prefersReducedMotion) return

    const grid = GRID
    const from = FROM
    const scaleStagger = stagger([2, 5], { ease: 'inQuad', grid, from })
    const darkChrome = isDarkChrome(resolvedTheme)
    const particleTheme = getParticleTheme(darkChrome)

    for (let i = 0; i < ROWS * ROWS; i++) {
      const particle = document.createElement('div')
      particle.className = 'init-hero-particle'
      creatureEl.appendChild(particle)
    }

    const particleEls = creatureEl.querySelectorAll('.init-hero-particle')

    const syncLayout = () => {
      const isCompact = compactRef.current
      const multiplier = isCompact ? 0.0045 : 0.0025
      const minSize = isCompact ? 0.75 : 1
      creatureEl.style.fontSize = `${Math.max(container.clientHeight * multiplier, minSize)}px`
    }

    syncLayout()

    utils.set(creatureEl, {
      width: `${ROWS * 10}em`,
      height: `${ROWS * 10}em`,
    })

    const opacityStagger = applyParticleTheme(
      { particleEls, scaleStagger, grid, from },
      particleTheme,
      darkChrome,
    )
    themeStateRef.current = { particleTheme, opacityStagger }

    utils.set(particleEls, {
      x: 0,
      y: 0,
      zIndex: stagger([ROWS * ROWS, 1], {
        grid,
        from,
        modifier: utils.round(0),
      }),
    })

    const getViewport = () => {
      const isCompact = compactRef.current
      return {
        w: container.clientWidth * (isCompact ? 0.32 : 0.5),
        h: container.clientHeight * (isCompact ? 0.9 : 0.5),
      }
    }

    let viewport = getViewport()
    const cursor = { x: 0, y: 0 }

    const pulse = () => {
      const themeState = themeStateRef.current
      if (!themeState) return

      animate(particleEls, {
        keyframes: [
          {
            scale: 4,
            opacity: themeState.particleTheme.pulseOpacity,
            delay: stagger(90, { start: 1650, grid, from }),
            duration: 150,
          },
          {
            scale: scaleStagger,
            opacity: themeState.opacityStagger,
            ease: 'inOutQuad',
            duration: 600,
          },
        ],
      })
    }

    const mainLoop = createTimer({
      frameRate: 15,
      onUpdate: () => {
        if (!canRunRef.current || !activeRef.current) return

        animate(particleEls, {
          x: cursor.x,
          y: cursor.y,
          delay: stagger(40, { grid, from }),
          duration: stagger(120, { start: 750, ease: 'inQuad', grid, from }),
          ease: 'inOut',
          composition: 'blend',
        })
      },
    })

    const autoMove = createTimeline()
      .add(
        cursor,
        {
          x: [-viewport.w * 0.45, viewport.w * 0.45],
          modifier: (x) => x + Math.sin(mainLoop.currentTime * 0.0007) * viewport.w * 0.5,
          duration: 3000,
          ease: 'inOutExpo',
          alternate: true,
          loop: true,
          onBegin: pulse,
          onLoop: pulse,
        },
        0,
      )
      .add(
        cursor,
        {
          y: [-viewport.h * 0.45, viewport.h * 0.45],
          modifier: (y) => y + Math.cos(mainLoop.currentTime * 0.00012) * viewport.h * 0.5,
          duration: 1000,
          ease: 'inOutQuad',
          alternate: true,
          loop: true,
        },
        0,
      )

    const manualMovementTimeout = createTimer({
      duration: 1500,
      onComplete: () => {
        if (canRunRef.current && activeRef.current) {
          autoMove.play()
        }
      },
    })

    const pause = () => {
      mainLoop.pause()
      autoMove.pause()
      manualMovementTimeout.pause()
    }

    const resume = () => {
      if (!canRunRef.current || !activeRef.current) return
      if (document.visibilityState === 'hidden') return
      mainLoop.play()
      autoMove.play()
    }

    const followPointer = (event: MouseEvent | TouchEvent) => {
      if (!canRunRef.current || !activeRef.current) return

      const rect = container.getBoundingClientRect()
      const point =
        event.type === 'touchmove'
          ? (event as TouchEvent).touches[0]
          : (event as MouseEvent)

      if (!point) return

      cursor.x = point.clientX - rect.left - viewport.w
      cursor.y = point.clientY - rect.top - viewport.h
      autoMove.pause()
      manualMovementTimeout.restart()
    }

    const handleResize = () => {
      viewport = getViewport()
      syncLayout()
    }

    const handleVisibility = () => {
      if (document.visibilityState === 'hidden') {
        pause()
        return
      }
      if (canRunRef.current && activeRef.current) {
        resume()
      }
    }

    const intersectionObserver = new IntersectionObserver(
      ([entry]) => {
        canRunRef.current = entry?.isIntersecting ?? true
        if (canRunRef.current && activeRef.current && document.visibilityState === 'visible') {
          resume()
        } else {
          pause()
        }
      },
      { threshold: 0.05 },
    )

    const resizeObserver = new ResizeObserver(handleResize)
    resizeObserver.observe(container)
    intersectionObserver.observe(container)

    container.addEventListener('mousemove', followPointer)
    container.addEventListener('touchmove', followPointer, { passive: true })
    document.addEventListener('visibilitychange', handleVisibility)

    if (activeRef.current) {
      resume()
    }

    runtimeRef.current = {
      particleEls,
      scaleStagger,
      grid,
      from,
      mainLoop,
      autoMove,
      manualMovementTimeout,
      syncLayout,
      pause,
      resume,
    }

    return () => {
      resizeObserver.disconnect()
      intersectionObserver.disconnect()
      container.removeEventListener('mousemove', followPointer)
      container.removeEventListener('touchmove', followPointer)
      document.removeEventListener('visibilitychange', handleVisibility)
      pause()
      creatureEl.replaceChildren()
      runtimeRef.current = null
      themeStateRef.current = null
    }
  }, [containerRef])

  useEffect(() => {
    runtimeRef.current?.syncLayout()
  }, [compact])

  useEffect(() => {
    if (!runtimeRef.current) return

    let cancelled = false
    let outerFrame = 0
    let innerFrame = 0

    outerFrame = requestAnimationFrame(() => {
      innerFrame = requestAnimationFrame(() => {
        if (cancelled || !runtimeRef.current) return

        const darkChrome = isDarkChrome(resolvedTheme)
        const particleTheme = getParticleTheme(darkChrome)
        const opacityStagger = applyParticleTheme(runtimeRef.current, particleTheme, darkChrome)
        themeStateRef.current = { particleTheme, opacityStagger }
      })
    })

    return () => {
      cancelled = true
      cancelAnimationFrame(outerFrame)
      cancelAnimationFrame(innerFrame)
    }
  }, [resolvedTheme])

  return (
    <>
      <div
        className={cn('absolute inset-0', compact ? 'bg-transparent' : 'bg-background')}
        aria-hidden
      />

      <div
        className={cn(
          'absolute inset-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px] transition-opacity duration-500',
          compact && 'opacity-0 [mask-image:linear-gradient(to_left,transparent,black_35%)]',
        )}
        aria-hidden
      />
      <div
        className={cn(
          'pointer-events-none absolute inset-0 flex overflow-hidden transition-[justify-content,padding] duration-500 ease-[cubic-bezier(0.4,0,0.2,1)]',
          compact ? 'items-center justify-end pe-2 sm:pe-6' : 'items-center justify-center',
        )}
        aria-hidden
      >
        <div
          ref={creatureRef}
          className={
            isDark
              ? 'flex flex-wrap items-center justify-center [&>.init-hero-particle]:relative [&>.init-hero-particle]:m-[3em] [&>.init-hero-particle]:size-[4em] [&>.init-hero-particle]:rounded-[2em] [&>.init-hero-particle]:[mix-blend-mode:plus-lighter] [&>.init-hero-particle]:will-change-transform [&>.init-hero-particle]:[transform-style:preserve-3d]'
              : 'flex flex-wrap items-center justify-center [&>.init-hero-particle]:relative [&>.init-hero-particle]:m-[3em] [&>.init-hero-particle]:size-[4em] [&>.init-hero-particle]:rounded-[2em] [&>.init-hero-particle]:mix-blend-normal [&>.init-hero-particle]:will-change-transform [&>.init-hero-particle]:[transform-style:preserve-3d]'
          }
          style={{ width: '150em', height: '150em' }}
        />
      </div>
    </>
  )
}
