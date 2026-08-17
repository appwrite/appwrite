import type { Page } from '@playwright/test'

function pathnameUsesFullscreenLoader(pathname: string): boolean {
  return (
    pathname === '/' ||
    pathname.startsWith('/account') ||
    pathname.startsWith('/organizations') ||
    pathname.startsWith('/projects') ||
    pathname.startsWith('/console') ||
    pathname.startsWith('/generator')
  )
}

/**
 * Wait until the root Appwrite fullscreen loader is gone.
 *
 * Console routes paint the overlay after hydration. A single `count === 0`
 * check right after `domcontentloaded` races that mount, so wait briefly for
 * attach, then wait for detach.
 */
export async function waitForFullscreenLoaderHidden(
  page: Page,
  timeout = 45_000,
): Promise<void> {
  let pathname = '/'
  try {
    pathname = new URL(page.url()).pathname
  } catch {
    return
  }

  if (!pathnameUsesFullscreenLoader(pathname)) return

  const loader = page.locator('[data-fullscreen-loader]')
  const attached = await loader
    .waitFor({ state: 'attached', timeout: 2_500 })
    .then(() => true)
    .catch(() => false)

  if (!attached) return

  await loader.waitFor({ state: 'detached', timeout })
}
