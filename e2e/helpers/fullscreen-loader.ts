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
 * Console routes paint the overlay after hydration; wait briefly for attach,
 * then wait for detach. Do not wait seconds when the loader never appears.
 */
export async function waitForFullscreenLoaderHidden(
  page: Page,
  timeout = 30_000,
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
    .waitFor({ state: 'attached', timeout: 250 })
    .then(() => true)
    .catch(() => false)

  if (!attached) return

  try {
    await loader.waitFor({ state: 'detached', timeout })
  } catch {
    await page.reload({ waitUntil: 'domcontentloaded', timeout: 45_000 })
    const stillAttached = await loader
      .waitFor({ state: 'attached', timeout: 250 })
      .then(() => true)
      .catch(() => false)
    if (!stillAttached) return
    await loader.waitFor({ state: 'detached', timeout })
  }
}
