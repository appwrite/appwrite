import { useEffect, useRef, useState } from 'react'

type RevealState = 'pending' | 'in' | undefined

/**
 * Returns a ref and a `data-reveal` value. While `pending`, entrance animations inside the
 * element are paused (see `[data-reveal='pending']` in styles.css) until it scrolls into view.
 * Stays undefined during SSR and without IntersectionObserver, so animations simply play.
 */
export function useRevealOnScroll<T extends HTMLElement>() {
  const ref = useRef<T>(null)
  const [reveal, setReveal] = useState<RevealState>(undefined)

  useEffect(() => {
    const element = ref.current
    if (!element || typeof IntersectionObserver === 'undefined') return

    setReveal('pending')
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (!entry?.isIntersecting) return
        setReveal('in')
        observer.disconnect()
      },
      { rootMargin: '0px 0px -20% 0px' },
    )
    observer.observe(element)
    return () => observer.disconnect()
  }, [])

  return { ref, reveal }
}
