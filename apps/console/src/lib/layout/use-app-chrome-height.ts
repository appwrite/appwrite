import { useEffect, useState } from 'react'
import { APP_CHROME_SELECTOR } from '@/lib/layout/app-header-height'

/**
 * Live height of the sticky app chrome for UI portaled outside `ConsoleLayout`
 * (where `--app-header-height` is not inherited). `0` when there is no chrome.
 */
export function useAppChromeHeight(enabled = true): number {
  const [height, setHeight] = useState(0)

  useEffect(() => {
    if (!enabled) return
    const chrome = document.querySelector<HTMLElement>(APP_CHROME_SELECTOR)
    if (!chrome) {
      setHeight(0)
      return
    }
    const sync = () =>
      setHeight(Math.round(chrome.getBoundingClientRect().height))
    sync()
    const observer = new ResizeObserver(sync)
    observer.observe(chrome)
    return () => observer.disconnect()
  }, [enabled])

  return height
}
