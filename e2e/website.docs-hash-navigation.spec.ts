import { expect, test, type Page } from '@playwright/test'

const installationPath = '/docs/tooling/command-line/installation'
const tablesPath = '/docs/tooling/command-line/tables'
const targetId = 'column-types-and-formats'

async function expectHeadingInDocsViewport(page: Page, id: string) {
  const heading = page.locator(`#${id}`)
  await expect(heading).toBeInViewport()
  await expect
    .poll(() =>
      heading.evaluate((element) => {
        const main = document.getElementById('main-content')!
        const target = element.getBoundingClientRect()
        const viewport = main.getBoundingClientRect()
        return (
          main.contains(element) &&
          main.scrollTop > 0 &&
          target.top >= viewport.top &&
          target.bottom <= viewport.bottom
        )
      }),
    )
    .toBe(true)
  // Hash navigation must not scroll the document behind the console shell.
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0)
}

test.beforeEach(async ({ context, baseURL }) => {
  const origin = new URL(baseURL!).origin
  await context.route('**/*', (route) => {
    if (new URL(route.request().url()).origin === origin)
      return route.continue()
    return route.abort()
  })
})

test('Docs View scrolls the installation cross-link after destination content mounts', async ({
  page,
}, testInfo) => {
  await page.goto(`${installationPath}#initialization`, {
    waitUntil: 'networkidle',
  })
  await expectHeadingInDocsViewport(page, 'initialization')
  const rejectCookies = page.getByRole('button', {
    name: 'Reject non-essential',
    exact: true,
  })
  if (await rejectCookies.isVisible()) await rejectCookies.click()
  const link = page.getByRole('link', {
    name: 'Column types and formats',
    exact: true,
  })
  await expect(link).toHaveAttribute('href', `${tablesPath}#${targetId}`)
  await link.click()
  await expect(page).toHaveURL(new RegExp(`${tablesPath}#${targetId}$`))
  await expectHeadingInDocsViewport(page, targetId)
  await page.screenshot({
    path: testInfo.outputPath('installation-cross-link.png'),
  })
})

test('Docs View honors a direct encoded hash on initial load', async ({
  page,
}, testInfo) => {
  await page.goto(`${tablesPath}#column-types-and-%66ormats`, {
    waitUntil: 'networkidle',
  })
  await expectHeadingInDocsViewport(page, targetId)
  await page.screenshot({ path: testInfo.outputPath('direct-hash.png') })
})

test('Docs View retains same-page anchors and back navigation', async ({
  page,
}) => {
  await page.goto(`${tablesPath}#${targetId}`, { waitUntil: 'networkidle' })
  await expectHeadingInDocsViewport(page, targetId)
  await page
    .getByRole('navigation', { name: 'Table of contents' })
    .getByRole('link', { name: 'Push table', exact: true })
    .click()
  await expect(page).toHaveURL(/#push-table$/)
  await expectHeadingInDocsViewport(page, 'push-table')
  await page.goBack()
  await expect(page).toHaveURL(new RegExp(`#${targetId}$`))
  await expectHeadingInDocsViewport(page, targetId)
})

test('DocsPageShell still resets hashless cross-page navigation to the top', async ({
  page,
}) => {
  await page.goto(`${tablesPath}#${targetId}`, { waitUntil: 'networkidle' })
  await expectHeadingInDocsViewport(page, targetId)
  await page.locator(`a[href="${installationPath}"]`).first().click()
  await expect(page).toHaveURL(new RegExp(`${installationPath}$`))
  await expect(
    page.getByRole('heading', { name: 'Installation_', exact: true }),
  ).toBeInViewport()
  await expect
    .poll(() =>
      page.locator('#main-content').evaluate((main) => main.scrollTop),
    )
    .toBe(0)
})

test('Docs View resets an unresolved cross-page hash in the existing SPA container', async ({
  page,
}) => {
  await page.goto(`${installationPath}#initialization`, {
    waitUntil: 'networkidle',
  })
  await page.waitForFunction(() => Boolean(window.__TSR_ROUTER__))
  await expectHeadingInDocsViewport(page, 'initialization')
  const main = await page.locator('#main-content').elementHandle()
  // Exercise the real router with a stale, malformed fragment without adding
  // a deliberately broken link to the shipped documentation.
  await page.evaluate(
    (to) =>
      window.__TSR_ROUTER__!.navigate({
        to,
        hash: 'removed-section-%',
      }),
    tablesPath,
  )
  await expect(page).toHaveURL(new RegExp(`${tablesPath}#removed-section-%$`))
  await expect(
    page.getByRole('heading', { name: 'Tables_', exact: true }),
  ).toBeInViewport()
  await expect
    .poll(() =>
      page.locator('#main-content').evaluate((element) => element.scrollTop),
    )
    .toBe(0)
  expect(
    await main!.evaluate(
      (element) => element === document.getElementById('main-content'),
    ),
  ).toBe(true)
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0)
})

test('Docs View preserves scroll for unresolved same-page hashes, including after a hashless arrival', async ({
  page,
}) => {
  await page.goto(`${tablesPath}#${targetId}`, { waitUntil: 'networkidle' })
  await expectHeadingInDocsViewport(page, targetId)
  const main = page.locator('#main-content')

  for (const to of [tablesPath, installationPath]) {
    if (to === installationPath) {
      await page.evaluate((to) => window.__TSR_ROUTER__!.navigate({ to }), to)
      await expect(page).toHaveURL(new RegExp(`${installationPath}$`))
      await expect(
        page.getByRole('heading', { name: 'Installation_', exact: true }),
      ).toBeInViewport()
      await expect
        .poll(() => main.evaluate((element) => element.scrollTop))
        .toBe(0)
      // Simulate the reader scrolling after a hashless arrival.
      await main.evaluate((element) => {
        element.scrollTop = 400
      })
    }
    const scrollTop = await main.evaluate((element) => element.scrollTop)
    expect(scrollTop).toBeGreaterThan(0)
    await page.evaluate(
      (to) =>
        window.__TSR_ROUTER__!.navigate({
          to,
          hash: 'removed-section-%',
        }),
      to,
    )
    await expect(page).toHaveURL(new RegExp(`${to}#removed-section-%$`))
    await page.waitForLoadState('networkidle')
    await expect
      .poll(() => main.evaluate((element) => element.scrollTop))
      .toBe(scrollTop)
    await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0)
  }
})

test('DocsPageShell resets invalid article fragments after a sibling route remount', async ({
  page,
}) => {
  await page.goto(`${tablesPath}#${targetId}`, { waitUntil: 'networkidle' })
  await expectHeadingInDocsViewport(page, targetId)
  const main = await page.locator('#main-content').elementHandle()
  await page.evaluate(() =>
    window.__TSR_ROUTER__!.navigate({ to: '/docs/quick-starts' }),
  )
  await expect(
    page.getByRole('heading', { name: 'Quick start_', exact: true }),
  ).toBeInViewport()
  await expect(page.locator(`#${targetId}`)).toHaveCount(0)
  // The sibling route unmounts the article View, not the docs scroll container.
  await main!.evaluate((element) => {
    element.scrollTop = 400
  })
  expect(await main!.evaluate((element) => element.scrollTop)).toBeGreaterThan(
    0,
  )
  await page.evaluate(
    (to) => window.__TSR_ROUTER__!.navigate({ to, hash: 'removed-section-%' }),
    tablesPath,
  )
  await expect(page).toHaveURL(new RegExp(`${tablesPath}#removed-section-%$`))
  await expect(
    page.getByRole('heading', { name: 'Tables_', exact: true }),
  ).toBeInViewport()
  expect(
    await main!.evaluate(
      (element) => element === document.getElementById('main-content'),
    ),
  ).toBe(true)
  await expect
    .poll(() => main!.evaluate((element) => element.scrollTop))
    .toBe(0)
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0)
})
