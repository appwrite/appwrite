import { useTheme } from 'next-themes'
import { useEffect, useRef } from 'react'
import {
  createInitStorm,
  type InitStorm,
  type InitStormEdges,
} from '@/lib/init/init5-hero/storm'
import { isDarkChrome, readPalette } from '@/lib/init/init5-hero/palette'
import { cn } from '@/lib/utils'

/**
 * The Init 5 storm behind a surface: soft fog along its `edges` with
 * lightning flashing inside it. Fills its nearest positioned parent and runs
 * only while it is on screen.
 */
export function Init5Storm({
  edges,
  dark = false,
  className,
}: {
  edges: InitStormEdges
  /** Always the dark storm, for a surface that is dark in either theme. */
  dark?: boolean
  className?: string
}) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const stormRef = useRef<InitStorm | null>(null)
  const { resolvedTheme } = useTheme()
  const isDark = dark || isDarkChrome(resolvedTheme)

  useEffect(() => {
    const canvas = canvasRef.current
    const surface = canvas?.parentElement
    if (!canvas || !surface) return
    const reducedMotion = window.matchMedia(
      '(prefers-reduced-motion: reduce)',
    ).matches
    const storm = createInitStorm(canvas, {
      palette: readPalette(surface, isDark),
      reducedMotion,
      edges,
    })
    if (!storm) return
    stormRef.current = storm

    const layout = () => {
      const box = canvas.getBoundingClientRect()
      storm.resize(box.width, box.height)
    }
    layout()
    const resizeObserver = new ResizeObserver(layout)
    resizeObserver.observe(canvas)
    const visibility = new IntersectionObserver(([entry]) =>
      storm.setActive(Boolean(entry?.isIntersecting)),
    )
    visibility.observe(canvas)

    return () => {
      resizeObserver.disconnect()
      visibility.disconnect()
      storm.dispose()
      stormRef.current = null
      // Free the context once the canvas has left the page, so storms that come
      // and go never pile up live contexts. A development remount keeps the
      // same canvas, and its context, attached.
      queueMicrotask(() => {
        if (canvas.isConnected) return
        canvas
          .getContext('webgl')
          ?.getExtension('WEBGL_lose_context')
          ?.loseContext()
      })
    }
    // The storm is built once per edge layout; theme changes repaint it below.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [edges])

  useEffect(() => {
    const surface = canvasRef.current?.parentElement
    if (surface) stormRef.current?.setPalette(readPalette(surface, isDark))
  }, [isDark, resolvedTheme])

  return (
    <canvas
      ref={canvasRef}
      aria-hidden
      className={cn(
        'pointer-events-none absolute inset-0 h-full w-full',
        // Light adds to a dark surface, as in the hero.
        isDark && 'mix-blend-screen',
        className,
      )}
    />
  )
}
