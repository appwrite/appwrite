/**
 * Interactive init memory probe: scroll, hover hero, remount cycles.
 */
import { chromium, type CDPSession, type Page } from 'playwright'

async function heapMb(page: Page, cdp: CDPSession): Promise<number> {
  await cdp.send('HeapProfiler.collectGarbage')
  const metrics = await cdp.send('Performance.getMetrics')
  const jsUsed = metrics.metrics.find((m) => m.name === 'JSHeapUsedSize')?.value ?? 0
  if (jsUsed > 0) return jsUsed / (1024 * 1024)

  return page.evaluate(() => {
    const m = (performance as Performance & { memory?: { usedJSHeapSize: number } }).memory
    return (m?.usedJSHeapSize ?? 0) / (1024 * 1024)
  })
}

async function countParticles(page: Page): Promise<number> {
  return page.evaluate(() => document.querySelectorAll('.init-hero-particle').length)
}

async function interact(page: Page) {
  await page.mouse.move(400, 300)
  await page.mouse.move(500, 350, { steps: 10 })
  for (let y = 0; y < 8000; y += 400) {
    await page.evaluate((scrollY) => {
      const main = document.getElementById('main-content')
      if (main) main.scrollTop = scrollY
    }, y)
    await page.waitForTimeout(200)
  }
  await page.evaluate(() => {
    const main = document.getElementById('main-content')
    if (main) main.scrollTop = 0
  })
}

async function main() {
  const initUrl = 'http://localhost:3000/init'
  const browser = await chromium.launch({
    headless: true,
    args: ['--js-flags=--expose-gc'],
  })
  const context = await browser.newContext({
    reducedMotion: 'no-preference',
    viewport: { width: 1280, height: 800 },
  })
  const page = await context.newPage()
  const cdp = await page.context().newCDPSession(page)
  await cdp.send('HeapProfiler.enable')
  await cdp.send('Performance.enable')

  await page.goto(initUrl, { waitUntil: 'networkidle' })
  await page.waitForTimeout(3000)

  const baseline = await heapMb(page, cdp)
  const particles0 = await countParticles(page)
  console.log(`baseline: ${baseline.toFixed(1)} MB, particles: ${particles0}`)

  await interact(page)
  const afterScroll = await heapMb(page, cdp)
  console.log(`after scroll: ${afterScroll.toFixed(1)} MB (+${(afterScroll - baseline).toFixed(1)})`)

  for (let i = 1; i <= 12; i++) {
    await page.waitForTimeout(5000)
    await page.mouse.move(300 + (i % 5) * 40, 200 + (i % 3) * 30, { steps: 5 })
    const mb = await heapMb(page, cdp)
    const particles = await countParticles(page)
    console.log(`${i * 5}s active: ${mb.toFixed(1)} MB (+${(mb - baseline).toFixed(1)}), particles: ${particles}`)
  }

  for (let c = 1; c <= 5; c++) {
    await page.goto('http://localhost:3000/sign-in', { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(800)
    await page.goto(initUrl, { waitUntil: 'networkidle' })
    await page.waitForTimeout(2000)
    const mb = await heapMb(page, cdp)
    const particles = await countParticles(page)
    console.log(`remount ${c}: ${mb.toFixed(1)} MB (+${(mb - baseline).toFixed(1)}), particles: ${particles}`)
  }

  await browser.close()
}

main()
