/**
 * Keeps toasts clear of the bottom-end progress panel. Sonner and the panel are
 * anchored to the same corner on separate fixed layers, so a toast otherwise
 * paints over an upload, environment copy or CSV card. The panel reports its
 * height through a CSS variable the Toaster adds to its bottom offset; an empty
 * panel reports nothing, and the variable goes with the panel.
 */

import { useEffect, type RefObject } from 'react'

export const PROGRESS_PANEL_OFFSET_VAR = '--console-progress-panel-offset'

/** Space between the top card of the panel and the lowest toast; matches gap-2. */
const PANEL_GAP_PX = 8

export function useProgressPanelToastOffset(
  ref: RefObject<HTMLElement | null>,
) {
  useEffect(() => {
    const element = ref.current
    if (!element || typeof ResizeObserver === 'undefined') return

    const root = document.documentElement
    const apply = () => {
      const height = element.getBoundingClientRect().height
      root.style.setProperty(
        PROGRESS_PANEL_OFFSET_VAR,
        height > 0 ? `${Math.round(height) + PANEL_GAP_PX}px` : '0px',
      )
    }

    apply()
    const observer = new ResizeObserver(apply)
    observer.observe(element)

    return () => {
      observer.disconnect()
      root.style.removeProperty(PROGRESS_PANEL_OFFSET_VAR)
    }
  }, [ref])
}
