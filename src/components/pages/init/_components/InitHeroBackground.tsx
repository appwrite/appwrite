import { isHtmlDarkChrome, isResolvedThemeDarkChrome } from '@/lib/html-theme'
import {
  accentHslToCss,
  parseCssAccentToHsl,
  type ParsedAccentHsl,
} from '@/lib/init/parse-css-accent'
import { useTheme } from 'next-themes'
import { useEffect, useRef, type RefObject } from 'react'
import { animate, createTimeline, createTimer, stagger, utils } from 'animejs'
import type { StaggerFunction } from 'animejs'
import { cn } from '@/lib/utils'
import { useInitLowPowerAnimationDecision } from '@/lib/init/use-init-low-power-animations'

const ROWS = 13
const LOW_POWER_ROWS = 9
const FROM = 'center'
const PARTICLE_BRIGHTNESS = 0.8

type InitHeroBackgroundProps = {
  containerRef: RefObject<HTMLElement | null>
  compact?: boolean
  /** When false, timers pause (e.g. hidden collapsed header). */
  active?: boolean
  /**
   * Particles only - no hero surface or dot grid (e.g. layered under a ticket capture stage).
   */
  bare?: boolean
  /** Ticket underscore / ID color - tints hero particles (defaults to brand CTA). */
  accentColor?: string
  /** Overrides console theme for particle opacity curves (e.g. gold / silver tickets). */
  particleIsDark?: boolean
  /** 1 = default speed; lower values slow particle drift (e.g. ticket video export). */
  particleMotionSpeed?: number
  /** Fired when the user moves the pointer over the particle field. */
  onInteractionStart?: () => void
  /** Fired when pointer interaction ends (leave or idle timeout). */
  onInteractionEnd?: () => void
  /** Keep animating while the document is hidden (ticket video capture). */
  keepAliveWhenHidden?: boolean
}

type ParticleTheme = {
  opacityRange: [number, number]
  lightnessRange: [number, number]
  shadowRange: [number, number]
  pulseOpacity: number
}

type AnimationRuntime = {
  particleEls: NodeListOf<Element>
  scaleStagger: StaggerFunction<number>
  grid: [number, number]
  from: typeof FROM
  mainLoop: ReturnType<typeof createTimer>
  autoMove: ReturnType<typeof createTimeline>
  manualMovementTimeout: ReturnType<typeof createTimer>
  syncLayout: () => void
  pause: () => void
  resume: () => void
}

type ThemeState = {
  particleTheme: ParticleTheme
  opacityStagger: StaggerFunction<number>
  accent: ParsedAccentHsl
  lowPower: boolean
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
  accent: ParsedAccentHsl,
  lowPower: boolean,
) {
  const { particleEls, scaleStagger, grid, from } = runtime
  const accentCss = accentHslToCss(accent)
  const opacityStagger = stagger(particleTheme.opacityRange, { grid, from })
  const shadowColor = isDark
    ? dimBrandPink(accentCss)
    : `color-mix(in srgb, ${accentCss} 90%, transparent)`
  const lightnessStagger = stagger(particleTheme.lightnessRange, { grid, from })
  const shadowStagger = stagger(particleTheme.shadowRange, { grid, from })
  const backgroundStagger: StaggerFunction<string> = (
    target,
    index,
    targets,
    prevTween,
    tl,
  ) => {
    const lightness = lightnessStagger(target, index, targets, prevTween, tl)
    return `hsl(${accent.h}, ${accent.s}%, ${lightness}%)`
  }
  const boxShadowStagger: StaggerFunction<string> = (
    target,
    index,
    targets,
    prevTween,
    tl,
  ) => {
    const shadow = shadowStagger(target, index, targets, prevTween, tl)
    return `0px 0px ${utils.round(shadow, 0)}em 0px ${shadowColor}`
  }

  utils.set(particleEls, {
    scale: scaleStagger,
    opacity: opacityStagger,
    background: backgroundStagger,
    boxShadow: lowPower ? 'none' : boxShadowStagger,
  })

  return { opacityStagger, accent }
}

export function InitHeroBackground({
  containerRef,
  compact = false,
  active = true,
  bare = false,
  accentColor,
  particleIsDark,
  particleMotionSpeed = 1,
  onInteractionStart,
  onInteractionEnd,
  keepAliveWhenHidden = false,
}: InitHeroBackgroundProps) {
  const creatureRef = useRef<HTMLDivElement>(null)
  const runtimeRef = useRef<AnimationRuntime | null>(null)
  const themeStateRef = useRef<ThemeState | null>(null)
  const compactRef = useRef(compact)
  const activeRef = useRef(active)
  const canRunRef = useRef(true)
  const keepAliveWhenHiddenRef = useRef(keepAliveWhenHidden)
  const onInteractionStartRef = useRef(onInteractionStart)
  const onInteractionEndRef = useRef(onInteractionEnd)
  const { resolvedTheme } = useTheme()
  const isDark = particleIsDark ?? isDarkChrome(resolvedTheme)
  const accentColorRef = useRef(accentColor)
  const animationDecision = useInitLowPowerAnimationDecision()
  const lowPower = animationDecision.enabled

  compactRef.current = compact
  activeRef.current = active
  accentColorRef.current = accentColor
  keepAliveWhenHiddenRef.current = keepAliveWhenHidden
  onInteractionStartRef.current = onInteractionStart
  onInteractionEndRef.current = onInteractionEnd

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

    if (!animationDecision.joolAnimationEnabled) return

    const rows = lowPower ? LOW_POWER_ROWS : ROWS
    const grid: [number, number] = [rows, rows]
    const from = FROM
    const motion = Math.max(0.12, Math.min(1, particleMotionSpeed))
    const dur = (ms: number) => ms / motion
    const timeScale = (factor: number) => factor * motion
    const scaleStagger = stagger(lowPower ? [1.6, 3.6] : [2, 5], {
      ease: 'inQuad',
      grid,
      from,
    })
    const darkChrome = particleIsDark ?? isDarkChrome(resolvedTheme)
    const particleTheme = getParticleTheme(darkChrome)
    const accent = parseCssAccentToHsl(
      accentColorRef.current ?? getBrandPink(),
      container,
    )

    for (let i = 0; i < rows * rows; i++) {
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
      width: `${rows * 10}em`,
      height: `${rows * 10}em`,
    })

    const { opacityStagger, accent: appliedAccent } = applyParticleTheme(
      { particleEls, scaleStagger, grid, from },
      particleTheme,
      darkChrome,
      accent,
      lowPower,
    )
    themeStateRef.current = {
      particleTheme,
      opacityStagger,
      accent: appliedAccent,
      lowPower,
    }

    utils.set(particleEls, {
      x: 0,
      y: 0,
      zIndex: stagger([rows * rows, 1], {
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
      if (lowPower) return

      const themeState = themeStateRef.current
      if (!themeState) return

      animate(particleEls, {
        keyframes: [
          {
            scale: 4,
            opacity: themeState.particleTheme.pulseOpacity,
            delay: stagger(dur(90), { start: dur(1650), grid, from }),
            duration: dur(150),
          },
          {
            scale: scaleStagger,
            opacity: themeState.opacityStagger,
            ease: 'inOutQuad',
            duration: dur(600),
          },
        ],
      })
    }

    const mainLoop = createTimer({
      frameRate: lowPower ? 8 : 15,
      onUpdate: () => {
        if (!canRunRef.current || !activeRef.current) return

        animate(particleEls, {
          x: cursor.x,
          y: cursor.y,
          delay: stagger(lowPower ? dur(70) : dur(40), { grid, from }),
          duration: stagger(lowPower ? dur(180) : dur(120), {
            start: lowPower ? dur(950) : dur(750),
            ease: 'inQuad',
            grid,
            from,
          }),
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
          modifier: (x) =>
            x +
            Math.sin(mainLoop.currentTime * timeScale(0.0007)) * viewport.w * 0.5,
          duration: dur(3000),
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
          modifier: (y) =>
            y +
            Math.cos(mainLoop.currentTime * timeScale(0.00012)) * viewport.h * 0.5,
          duration: dur(1000),
          ease: 'inOutQuad',
          alternate: true,
          loop: true,
        },
        0,
      )

    const manualMovementTimeout = createTimer({
      duration: dur(1500),
      onComplete: () => {
        onInteractionEndRef.current?.()
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
      if (document.visibilityState === 'hidden' && !keepAliveWhenHiddenRef.current) return
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

      onInteractionStartRef.current?.()
      cursor.x = point.clientX - rect.left - viewport.w
      cursor.y = point.clientY - rect.top - viewport.h
      autoMove.pause()
      manualMovementTimeout.restart()
    }

    const endPointerInteraction = () => {
      onInteractionEndRef.current?.()
    }

    const handleResize = () => {
      viewport = getViewport()
      syncLayout()
    }

    const handleVisibility = () => {
      if (document.visibilityState === 'hidden') {
        if (keepAliveWhenHiddenRef.current) return
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
        if (
          canRunRef.current &&
          activeRef.current &&
          (document.visibilityState === 'visible' || keepAliveWhenHiddenRef.current)
        ) {
          resume()
        } else if (!keepAliveWhenHiddenRef.current) {
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
    container.addEventListener('mouseleave', endPointerInteraction)
    container.addEventListener('touchend', endPointerInteraction)
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
      container.removeEventListener('mouseleave', endPointerInteraction)
      container.removeEventListener('touchend', endPointerInteraction)
      document.removeEventListener('visibilitychange', handleVisibility)
      pause()
      creatureEl.replaceChildren()
      runtimeRef.current = null
      themeStateRef.current = null
    }
  }, [
    containerRef,
    particleIsDark,
    particleMotionSpeed,
    lowPower,
    resolvedTheme,
    animationDecision.joolAnimationEnabled,
  ])

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

        const container = containerRef.current
        const darkChrome = particleIsDark ?? isDarkChrome(resolvedTheme)
        const particleTheme = getParticleTheme(darkChrome)
        const accent = parseCssAccentToHsl(
          accentColorRef.current ?? getBrandPink(),
          container,
        )
        const { opacityStagger, accent: appliedAccent } = applyParticleTheme(
          runtimeRef.current,
          particleTheme,
          darkChrome,
          accent,
          lowPower,
        )
        themeStateRef.current = {
          particleTheme,
          opacityStagger,
          accent: appliedAccent,
          lowPower,
        }
      })
    })

    return () => {
      cancelled = true
      cancelAnimationFrame(outerFrame)
      cancelAnimationFrame(innerFrame)
    }
  }, [resolvedTheme, accentColor, particleIsDark, containerRef, lowPower])

  const creatureClassName = isDark
    ? 'flex flex-wrap items-center justify-center [&>.init-hero-particle]:relative [&>.init-hero-particle]:m-[3em] [&>.init-hero-particle]:size-[4em] [&>.init-hero-particle]:rounded-[2em] [&>.init-hero-particle]:[mix-blend-mode:plus-lighter] [&>.init-hero-particle]:will-change-transform [&>.init-hero-particle]:[transform-style:preserve-3d]'
    : 'flex flex-wrap items-center justify-center [&>.init-hero-particle]:relative [&>.init-hero-particle]:m-[3em] [&>.init-hero-particle]:size-[4em] [&>.init-hero-particle]:rounded-[2em] [&>.init-hero-particle]:mix-blend-normal [&>.init-hero-particle]:will-change-transform [&>.init-hero-particle]:[transform-style:preserve-3d]'

  return (
    <>
      {!bare ? (
        <div
          className={cn('absolute inset-0', compact ? 'bg-transparent' : 'bg-background')}
          aria-hidden
        />
      ) : null}

      {!bare ? (
        <div
          className={cn(
            'absolute inset-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px] transition-opacity duration-500',
            compact && 'opacity-0 [mask-image:linear-gradient(to_left,transparent,black_35%)]',
          )}
          aria-hidden
        />
      ) : null}
      <div
        className={cn(
          'pointer-events-none absolute inset-0 flex overflow-hidden transition-[justify-content,padding] duration-500 ease-[cubic-bezier(0.4,0,0.2,1)]',
          compact ? 'items-center justify-end pe-2 sm:pe-6' : 'items-center justify-center',
        )}
        aria-hidden
      >
        <div
          ref={creatureRef}
          className={creatureClassName}
          style={{ width: '150em', height: '150em' }}
        />
      </div>
    </>
  )
}
