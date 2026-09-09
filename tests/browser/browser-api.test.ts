import { afterAll, beforeAll, describe, expect, test } from 'bun:test'
import { chromium, type Browser } from '@playwright/test'
import {
  readRuntimeConfigFromEnv,
  serializeRuntimeConfig,
} from '../../src/lib/runtime-config-shared'

let browser: Browser
let server: ReturnType<typeof Bun.serve>
const bundles = new Map<string, string>()

beforeAll(async () => {
  for (const mode of ['development', 'production']) {
    const result = await Bun.build({
      entrypoints: ['./src/lib/browser-api.ts'],
      target: 'browser',
      define: {
        'import.meta.env': JSON.stringify({ DEV: mode === 'development' }),
      },
    })
    if (!result.success) throw new AggregateError(result.logs)
    bundles.set(mode, await result.outputs[0].text())
  }
  server = Bun.serve({
    port: 0,
    fetch(request) {
      const url = new URL(request.url)
      if (url.pathname.endsWith('.js')) {
        return new Response(bundles.get(url.pathname.slice(1, -3)), {
          headers: { 'Content-Type': 'text/javascript' },
        })
      }
      const config = readRuntimeConfigFromEnv({
        VITE_CONSOLE_BROWSER_API: url.searchParams.get('exposure') ?? undefined,
        VITE_CONSOLE_PROFILE: url.searchParams.get('profile') ?? 'cloud',
        VITE_CONSOLE_COOKIE_BANNER: 'false',
      })
      const mode = url.searchParams.get('mode') ?? 'production'
      return new Response(
        `<script>window.__APP_CONFIG__=${serializeRuntimeConfig(config)}</script>
         <script type="module">
           import { installBrowserApi } from '/${mode}.js';
           window.disposeApi = installBrowserApi();
           window.ready = true;
         </script>`,
        { headers: { 'Content-Type': 'text/html' } },
      )
    },
  })
  browser = await chromium.launch()
})

afterAll(async () => {
  await browser?.close()
  server?.stop(true)
})

describe('browser API exposure', () => {
  for (const mode of ['development', 'production']) {
    for (const exposure of ['', 'false', 'true', 'invalid']) {
      test(`${mode}, exposure=${exposure || 'unset'}`, async () => {
        const page = await browser.newPage()
        try {
          await page.goto(`${server.url}?mode=${mode}&exposure=${exposure}`)
          await page.waitForFunction(() => 'ready' in window)
          expect(await page.evaluate(() => '__vibes' in window)).toBe(
            mode === 'development' || exposure === 'true',
          )
        } finally {
          await page.close()
        }
      })
    }
  }
})

test('set, persist, validate, reset and preserve unrelated settings', async () => {
  const page = await browser.newPage()
  try {
    await page.goto(`${server.url}?exposure=true`)
    await page.waitForFunction(() => !!window.__vibes)
    const initial = await page.evaluate(() => window.__vibes!.flags.list())
    expect(initial.agent.override).toBeNull()
    expect(initial.cookieBanner.default).toBe(false)
    const changed = await page.evaluate(() => {
      localStorage.setItem('debug:language', 'ja')
      return window.__vibes!.flags.set({ agent: true, showActivityChart: true })
    })
    expect(changed.agent.effective).toBe(true)
    expect(changed.showActivityChart.effective).toBe(true)
    await page.reload()
    await page.waitForFunction(() => !!window.__vibes)
    expect(await page.evaluate(() => window.__vibes!.flags.list())).toEqual(
      changed,
    )
    const errors = await page.evaluate(() => {
      const api = window.__vibes!.flags
      return [{ agent: false, typo: true }, { agent: 'false' }, null, []].map(
        (input) => {
          try {
            api.set(input as Parameters<typeof api.set>[0])
            return false
          } catch (error) {
            return error instanceof TypeError
          }
        },
      )
    })
    expect(errors).toEqual([true, true, true, true])
    expect(await page.evaluate(() => window.__vibes!.flags.list())).toEqual(
      changed,
    )
    const disabled = await page.evaluate(() =>
      window.__vibes!.flags.set({ agent: false }),
    )
    expect(disabled.agent.override).toBe(false)
    expect(disabled.agent.effective).toBe(false)
    const reset = await page.evaluate(() =>
      window.__vibes!.flags.reset('agent'),
    )
    expect(reset.agent).toEqual(initial.agent)
    expect(reset.showActivityChart.override).toBe(true)
    const all = await page.evaluate(() => window.__vibes!.flags.resetAll())
    expect(all).toEqual(initial)
    expect(
      await page.evaluate(() => localStorage.getItem('debug:language')),
    ).toBe('ja')
    expect(
      (await page.context().cookies()).some(
        (cookie) => cookie.name === 'debug_console_profile',
      ),
    ).toBe(true)
  } finally {
    await page.close()
  }
})

const devUrl = process.env.BROWSER_API_DEV_URL
test.skipIf(!devUrl)(
  'running app updates its UI and keeps overrides after reload',
  async () => {
    const page = await browser.newPage()
    try {
      await page.goto(new URL('/sign-in', devUrl).href)
      await page.waitForFunction(() => !!window.__vibes)
      const stripe = page.locator('div[style*="repeating-linear-gradient"]')
      await page.evaluate(() =>
        window.__vibes!.flags.set({ showConstruction: true, agent: true }),
      )
      await stripe.waitFor({ state: 'visible' })
      await page.evaluate(() =>
        window.__vibes!.flags.set({ showConstruction: false, agent: false }),
      )
      await stripe.waitFor({ state: 'detached' })
      await page.reload()
      await page.waitForFunction(() => !!window.__vibes)
      const persisted = await page.evaluate(() => window.__vibes!.flags.list())
      expect(persisted.agent.override).toBe(false)
      expect(persisted.showConstruction.effective).toBe(false)
      await stripe.waitFor({ state: 'detached' })
      const reset = await page.evaluate(() => window.__vibes!.flags.resetAll())
      expect(reset.agent.override).toBeNull()
      expect(reset.showConstruction.override).toBeNull()
      if (reset.showConstruction.default)
        await stripe.waitFor({ state: 'visible' })
    } finally {
      await page.close()
    }
  },
  120_000,
)
