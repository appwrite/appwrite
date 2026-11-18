import type { FactoryMount } from './boot'

/**
 * The home hero's factory, started as early as the page allows and kept
 * running across remounts of its stage.
 *
 * The client entry imports this before anything else, and it imports
 * nothing at run time itself, so it runs as soon as the client bundle is
 * parsed: on a page with the factory stage, the engine downloads and builds
 * on the server-rendered canvas while the rest of the bundle runs and React
 * hydrates (see `bootFactory`), and the stage takes it over.
 *
 * A stage that unmounts leaves its factory here for a moment instead of
 * disposing it: the page's providers remount everything once right after
 * hydration, and the new stage takes the running factory straight back,
 * canvas and all, before the page paints.
 */

let held: FactoryMount | null = null
let expiry: ReturnType<typeof setTimeout> | undefined

/**
 * Leaves a running factory for the next stage to take: the early one until
 * a stage does, or (`expire`) one a stage left as it unmounted, disposed
 * unless another takes it right away.
 */
export function holdFactory(mount: FactoryMount, expire: boolean) {
  clearTimeout(expiry)
  if (held && held !== mount) held.dispose()
  held = mount
  if (!expire) return
  expiry = setTimeout(() => {
    if (held !== mount) return
    held = null
    mount.dispose()
  }, 0)
}

/** The factory left for this stage, if any. */
export function takeFactory(): FactoryMount | null {
  clearTimeout(expiry)
  const mount = held
  held = null
  return mount
}

/** Settles once the early start has run (at once on a page without the stage). */
export const factoryBoot: Promise<void> =
  typeof document !== 'undefined' &&
  document.querySelector('[data-factory-stage]')
    ? import('./boot')
        .then(({ bootFactory }) => bootFactory())
        .catch(() => {
          // The stage starts its own.
        })
    : Promise.resolve()
