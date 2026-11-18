import { isHtmlDarkChrome } from '@/lib/html-theme'
import { holdFactory } from './early'
import { createFactoryEngine, type FactoryEngine } from './engine'

/** Large screens put the copy over the scene; smaller ones stack it above. */
const OVERLAY_QUERY = '(min-width: 1024px)'

/**
 * What the machines print and the displays say, as the stage renders it
 * into `data-factory-labels` (translated on the server, so the factory can
 * start before React hydrates).
 */
export type FactoryLabels = {
  /** Every station's name, then the network gate's sign. */
  names: string[]
  descriptions: string[]
  counterLabel: string
  promptLabel: string
}

/** A factory running on a stage, kept in step with the page around it. */
export type FactoryMount = {
  readonly engine: FactoryEngine
  /**
   * Moves the running factory into `container` (its canvas takes the place
   * of the stage's own, which stays hidden), keeping everything built.
   */
  moveTo(container: HTMLElement): void
  /** Called if the engine fails for good, at once if it already has. */
  onError(listener: () => void): void
  dispose(): void
}

/**
 * Starts the factory on the server-rendered stage before React hydrates the
 * page, and leaves it for the stage to take over (see `early.ts`).
 */
export function bootFactory() {
  const container = document.querySelector<HTMLElement>('[data-factory-stage]')
  const mount = container ? startFactory(container) : null
  if (mount) holdFactory(mount, false)
}

/**
 * Builds the engine on the stage's canvas from what the stage rendered (its
 * labels, and the product icons it holds hidden). Null without WebGL.
 */
export function startFactory(container: HTMLElement): FactoryMount | null {
  const canvas = container.querySelector('canvas')
  const json = container.dataset.factoryLabels
  if (!canvas || !json) return null
  const labels = JSON.parse(json) as FactoryLabels
  const icons = [
    ...container.querySelectorAll('[data-factory-icons] > svg'),
  ].map((icon) => icon.outerHTML)

  let failed = false
  let errorListener: (() => void) | null = null
  let engine: FactoryEngine
  try {
    engine = createFactoryEngine({
      canvas,
      icons,
      ...labels,
      rtl: document.documentElement.dir === 'rtl',
      dark: isHtmlDarkChrome(),
      reducedMotion: window.matchMedia('(prefers-reduced-motion: reduce)')
        .matches,
      onError: () => {
        failed = true
        canvas.hidden = true
        errorListener?.()
      },
    })
  } catch {
    // No WebGL: the stage shows its fallback.
    return null
  }

  let place = container
  let unwire = wire(engine, container)
  return {
    engine,
    moveTo(next) {
      if (next === place) return
      unwire()
      const own = next.querySelector('canvas')
      if (own && own !== canvas) {
        own.hidden = true
        own.before(canvas)
      } else if (!own) {
        next.prepend(canvas)
      }
      place = next
      unwire = wire(engine, next)
    },
    onError(listener) {
      errorListener = listener
      if (failed) listener()
    },
    dispose() {
      unwire()
      engine.dispose()
    },
  }
}

/**
 * Keeps the engine in step with the page around `container`: its size,
 * layout and the copy laid over it, the theme, and whether it is on screen.
 * Returns what undoes it.
 */
function wire(engine: FactoryEngine, container: HTMLElement): () => void {
  let live = true
  const cleanups: (() => void)[] = []
  const overlay = window.matchMedia(OVERLAY_QUERY)
  // Where each line of the hero's title and description sits over the
  // scene (`data-factory-copy`), so unfolding displays can keep clear of
  // the words while rising beside a short line.
  const syncCopyArea = () => {
    if (!overlay.matches) return engine.setCopyLines([])
    const stage = container.getBoundingClientRect()
    const range = document.createRange()
    const copy = [
      ...(container
        .closest('section')
        ?.querySelectorAll('[data-factory-copy]') ?? []),
    ]
    engine.setCopyLines(
      copy.flatMap((element) => {
        range.selectNodeContents(element)
        return [...range.getClientRects()]
          .filter((line) => line.width > 0 && line.height > 0)
          .map((line) => ({
            left: line.left - stage.left,
            top: line.top - stage.top,
            right: line.right - stage.left,
            bottom: line.bottom - stage.top,
          }))
      }),
    )
  }
  const syncComposition = () => {
    engine.setComposition(overlay.matches ? 'overlay' : 'band')
    syncCopyArea()
  }
  syncComposition()
  overlay.addEventListener('change', syncComposition)
  cleanups.push(() => overlay.removeEventListener('change', syncComposition))

  const rect = container.getBoundingClientRect()
  engine.setSize(rect.width, rect.height)
  const resize = new ResizeObserver(([entry]) => {
    if (entry) engine.setSize(entry.contentRect.width, entry.contentRect.height)
    syncCopyArea()
  })
  resize.observe(container)
  cleanups.push(() => resize.disconnect())
  // The lines move once the brand fonts arrive.
  void document.fonts?.ready.then(() => {
    if (live) syncCopyArea()
  })

  // Render only while on screen and the tab is in front.
  let visible = false
  const syncActive = () =>
    engine.setActive(visible && document.visibilityState === 'visible')
  const intersection = new IntersectionObserver(
    ([entry]) => {
      visible = !!entry?.isIntersecting
      syncActive()
    },
    { rootMargin: '80px 0px' },
  )
  intersection.observe(container)
  cleanups.push(() => intersection.disconnect())
  document.addEventListener('visibilitychange', syncActive)
  cleanups.push(() =>
    document.removeEventListener('visibilitychange', syncActive),
  )

  const syncTheme = () => engine.setTheme(isHtmlDarkChrome())
  const themeObserver = new MutationObserver(syncTheme)
  themeObserver.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['class'],
  })
  const media = window.matchMedia('(prefers-color-scheme: dark)')
  media.addEventListener('change', syncTheme)
  cleanups.push(() => {
    themeObserver.disconnect()
    media.removeEventListener('change', syncTheme)
  })

  return () => {
    live = false
    for (const cleanup of cleanups) cleanup()
  }
}
