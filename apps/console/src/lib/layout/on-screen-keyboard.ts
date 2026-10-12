import { useEffect, useState, type RefObject } from 'react'

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
  /**
   * `document.documentElement.clientHeight`: a stable layout viewport baseline
   * for keyboard detection. Unlike `window.innerHeight` on iOS, pinch zoom
   * leaves it alone.
   */
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
 * Tracks {@link getOnScreenKeyboardBand} while `enabled` and focus is inside
 * `containerRef`. Give a fixed element the band's `top` and `height` to keep it
 * above the keyboard.
 */
export function useOnScreenKeyboardBand(
  containerRef: RefObject<HTMLElement | null>,
  enabled: boolean,
): VisibleViewportBand | null {
  const [band, setBand] = useState<VisibleViewportBand | null>(null)

  useEffect(() => {
    const container = containerRef.current
    const viewport = window.visualViewport
    if (!enabled || !container || !viewport) {
      setBand(null)
      return
    }

    const update = () => {
      // Read the live active element: WebKit fires no focusout when a focused
      // element is removed, so tracked focus state can go stale.
      const next = container.contains(document.activeElement)
        ? getOnScreenKeyboardBand({
            layoutHeight: document.documentElement.clientHeight,
            visualHeight: viewport.height,
            visualOffsetTop: viewport.offsetTop,
            visualScale: viewport.scale,
          })
        : null
      setBand((prev) =>
        prev?.top === next?.top && prev?.height === next?.height ? prev : next,
      )
    }
    const handleFocusOut = (event: FocusEvent) => {
      // Focus moving to another element is handled by the focusin that follows.
      if (!event.relatedTarget) update()
    }

    update()
    // `scroll` fires when the browser pans the visual viewport to the input.
    // Focus can also enter or leave while the keyboard stays open.
    viewport.addEventListener('resize', update)
    viewport.addEventListener('scroll', update)
    document.addEventListener('focusin', update)
    document.addEventListener('focusout', handleFocusOut)
    return () => {
      viewport.removeEventListener('resize', update)
      viewport.removeEventListener('scroll', update)
      document.removeEventListener('focusin', update)
      document.removeEventListener('focusout', handleFocusOut)
    }
  }, [containerRef, enabled])

  return enabled ? band : null
}
