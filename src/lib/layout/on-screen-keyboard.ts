import { useEffect, useState } from 'react'

/**
 * Shortest covered strip treated as an on-screen keyboard. Phone keyboards are
 * well above this; scrollbars, toolbar rounding, and iPad's hardware-keyboard
 * shortcut bar stay below it.
 */
export const ON_SCREEN_KEYBOARD_MIN_HEIGHT_PX = 120

/** Visible slice of the layout viewport, in CSS px from its top edge. */
export type VisibleViewportBand = {
  top: number
  height: number
}

export type ViewportMetrics = {
  /** `window.innerHeight`: the layout viewport the fixed app shell fills. */
  layoutHeight: number
  visualHeight: number
  visualOffsetTop: number
  visualScale: number
}

/**
 * iOS Safari and Chrome on Android shrink only the visual viewport when the
 * on-screen keyboard opens, so `position: fixed` and `100dvh` UI keeps painting
 * underneath it. Returns the band still visible above the keyboard, or null
 * when no keyboard covers the viewport.
 */
export function getOnScreenKeyboardBand({
  layoutHeight,
  visualHeight,
  visualOffsetTop,
  visualScale,
}: ViewportMetrics): VisibleViewportBand | null {
  // Pinch zoom shrinks visualHeight by the zoom factor; only a keyboard
  // shrinks it on screen.
  const keyboardHeight = layoutHeight - visualHeight * visualScale
  if (keyboardHeight < ON_SCREEN_KEYBOARD_MIN_HEIGHT_PX) return null

  return {
    top: Math.round(visualOffsetTop),
    height: Math.floor(visualHeight),
  }
}

/**
 * Tracks {@link getOnScreenKeyboardBand} while `enabled`. Give a fixed element
 * the band's `top` and `height` to keep it above the keyboard.
 */
export function useOnScreenKeyboardBand(
  enabled: boolean,
): VisibleViewportBand | null {
  const [band, setBand] = useState<VisibleViewportBand | null>(null)

  useEffect(() => {
    const viewport = window.visualViewport
    if (!enabled || !viewport) {
      setBand(null)
      return
    }

    const update = () => {
      const next = getOnScreenKeyboardBand({
        layoutHeight: window.innerHeight,
        visualHeight: viewport.height,
        visualOffsetTop: viewport.offsetTop,
        visualScale: viewport.scale,
      })
      setBand((prev) =>
        prev?.top === next?.top && prev?.height === next?.height ? prev : next,
      )
    }

    update()
    // `scroll` fires when the browser pans the visual viewport to the input.
    viewport.addEventListener('resize', update)
    viewport.addEventListener('scroll', update)
    return () => {
      viewport.removeEventListener('resize', update)
      viewport.removeEventListener('scroll', update)
    }
  }, [enabled])

  return enabled ? band : null
}
