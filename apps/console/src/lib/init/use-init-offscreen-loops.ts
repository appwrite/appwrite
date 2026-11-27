import { useEffect } from 'react'

/**
 * Elements whose CSS animations loop forever: the Init 5 comet frames and trails.
 * Their conic gradients, masks and drop-shadows repaint every frame, and Chrome
 * keeps painting well outside the viewport.
 */
const LOOP_SELECTOR = [
  '.init5-light-frame',
  '.init5-globe-orbit',
  '.init5-wordmark-trail',
  '.init5-online-edge',
  '.init-browse-five-trail',
].join(', ')

/** Read by `styles.css`, which pauses the element's animations and its pseudo-elements'. */
export const INIT_OFFSCREEN_ATTRIBUTE = 'data-init-offscreen'

/** Resume a little before a loop scrolls into view, so it is already moving. */
const ROOT_MARGIN = '200px'

/**
 * Pauses the page's looping light animations while they are off screen. Each one
 * resumes where it stopped, which reads the same as a comet that kept going.
 */
export function useInitOffscreenLoops() {
  useEffect(() => {
    if (typeof IntersectionObserver === 'undefined') return

    const intersection = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          entry.target.toggleAttribute(
            INIT_OFFSCREEN_ATTRIBUTE,
            !entry.isIntersecting,
          )
        }
      },
      { rootMargin: ROOT_MARGIN },
    )

    const observed = new Set<Element>()
    const scan = () => {
      for (const element of document.querySelectorAll(LOOP_SELECTOR)) {
        if (observed.has(element)) continue
        observed.add(element)
        intersection.observe(element)
      }
      for (const element of observed) {
        if (element.isConnected) continue
        observed.delete(element)
        intersection.unobserve(element)
      }
    }

    // Sections mount late (globe, browse, revealed days): rescan once per frame of changes.
    let frame = 0
    const mutations = new MutationObserver(() => {
      if (frame) return
      frame = requestAnimationFrame(() => {
        frame = 0
        scan()
      })
    })

    scan()
    mutations.observe(document.body, { childList: true, subtree: true })

    return () => {
      cancelAnimationFrame(frame)
      mutations.disconnect()
      intersection.disconnect()
      for (const element of observed)
        element.removeAttribute(INIT_OFFSCREEN_ATTRIBUTE)
    }
  }, [])
}
