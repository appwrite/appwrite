import { useLayoutEffect } from 'react'

export function useHashScroll(
  enabled: boolean,
  hash?: string,
  options: ScrollIntoViewOptions = { behavior: 'smooth', block: 'start' },
) {
  useLayoutEffect(() => {
    if (!enabled || !hash) return
    const id = hash.replace('#', '')
    if (!id) return
    const el = document.getElementById(id)
    if (!el) return

    const handle = requestAnimationFrame(() => {
      el.scrollIntoView(options)
    })

    return () => cancelAnimationFrame(handle)
  }, [enabled, hash, options])
}
