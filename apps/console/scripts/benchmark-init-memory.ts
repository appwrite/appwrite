/**
 * Measures JS heap growth on /init over time and across mount/unmount cycles.
 *
 * Usage: bun run scripts/benchmark-init-memory.ts [--url http://localhost:3000]
 */
import { chromium, type CDPSession, type Page } from 'playwright'

const DEFAULT_URL = 'http://localhost:3000'
const BASE_PATH = '/init'

type HeapSample = {
  label: string
  usedMb: number
  totalMb: number
}

async function collectHeap(page: Page, cdp: CDPSession, label: string): Promise<HeapSample> {
  await cdp.send('HeapProfiler.collectGarbage')
  const { usedSize, totalSize } = await page.evaluate(() => {
    const memory = (performance as Performance & { memory?: { usedJSHeapSize: number; totalJSHeapSize: number } }).memory
    return {
      usedSize: memory?.usedJSHeapSize ?? 0,
      totalSize: memory?.totalJSHeapSize ?? 0,
    }
  })

  return {
    label,
    usedMb: usedSize / (1024 * 1024),
    totalMb: totalSize / (1024 * 1024),
  }
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

async function main() {
  const baseUrl = process.argv.find((arg) => arg.startsWith('--url='))?.slice(6) ?? DEFAULT_URL
  const initUrl = `${baseUrl.replace(/\/$/, '')}${BASE_PATH}`

  const browser = await chromium.launch({
    headless: true,
    args: ['--js-flags=--expose-gc'],
  })

  const context = await browser.newContext()
  const page = await context.newPage()
  const cdp = await page.context().newCDPSession(page)
  await cdp.send('HeapProfiler.enable')

  const samples: HeapSample[] = []

  await page.goto(initUrl, { waitUntil: 'networkidle' })
  await sleep(3000)
  samples.push(await collectHeap(page, cdp, 'after-load'))

  for (let minute = 1; minute <= 2; minute++) {
    await sleep(60_000)
    samples.push(await collectHeap(page, cdp, `${minute}m-idle`))
  }

  for (let cycle = 1; cycle <= 3; cycle++) {
    await page.goto(`${baseUrl}/sign-in`, { waitUntil: 'domcontentloaded' })
    await sleep(1500)
    await page.goto(initUrl, { waitUntil: 'networkidle' })
    await sleep(2000)
    samples.push(await collectHeap(page, cdp, `remount-${cycle}`))
  }

  await browser.close()

  const baseline = samples[0]?.usedMb ?? 0
  const final = samples[samples.length - 1]?.usedMb ?? 0
  const growth = final - baseline

  console.log('\nInit page memory benchmark')
  console.log(`URL: ${initUrl}\n`)
  for (const sample of samples) {
    const delta = sample.usedMb - baseline
    console.log(
      `${sample.label.padEnd(14)} ${sample.usedMb.toFixed(1)} MB used (${delta >= 0 ? '+' : ''}${delta.toFixed(1)} MB vs baseline)`,
    )
  }
  console.log(`\nTotal growth (baseline → final): ${growth >= 0 ? '+' : ''}${growth.toFixed(1)} MB`)

  if (growth > 50) {
    console.error('\nFAIL: heap grew more than 50 MB (likely leak)')
    process.exit(1)
  }

  console.log('\nPASS: heap growth within threshold')
}

main().catch((error) => {
  console.error(error)
  process.exit(1)
})
