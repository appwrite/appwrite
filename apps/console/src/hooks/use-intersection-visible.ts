import { useEffect, useState, type RefObject } from 'react'

type UseIntersectionVisibleOptions = {
  rootMargin?: string
  threshold?: number
  /** When true, `hasBeenVisible` latches on first intersection. */
  once?: boolean
}

/**
 * Tracks whether a node is in (or near) the viewport.
 * Use `hasBeenVisible` with `once: true` for lazy mount; `isVisible` to pause heavy work.
 */
export function useIntersectionVisible(
  ref: RefObject<Element | null>,
  {
    rootMargin = '200px 0px',
    threshold = 0,
    once = false,
  }: UseIntersectionVisibleOptions = {},
) {
  const [isVisible, setIsVisible] = useState(false)
  const [hasBeenVisible, setHasBeenVisible] = useState(false)

  useEffect(() => {
    const node = ref.current
    if (!node) return

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (!entry) return

        const intersecting = entry.isIntersecting
        setIsVisible(intersecting)

        if (intersecting) {
          setHasBeenVisible(true)
        } else if (!once) {
          setIsVisible(false)
        }
      },
      { rootMargin, threshold },
    )

    observer.observe(node)
    return () => observer.disconnect()
  }, [ref, rootMargin, threshold, once])

  return { isVisible, hasBeenVisible }
}
