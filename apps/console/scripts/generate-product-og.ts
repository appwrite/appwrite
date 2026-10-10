/**
 * Capture a focused product OG frame (name, title, visual) to
 * public/images/products/<id>/og.avif.
 *
 * Requires the console dev server. Override the origin with PRODUCT_OG_ORIGIN.
 *
 * Run: bun run generate:product-og
 */
import { mkdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'
import sharp from 'sharp'
import {
  PRODUCT_OG_IMAGE_HEIGHT,
  PRODUCT_OG_IMAGE_WIDTH,
} from '../src/lib/products/og-image.ts'
import { PRODUCT_IDS } from '../src/lib/products/registry.ts'

const __dirname = dirname(fileURLToPath(import.meta.url))
const ROOT = join(__dirname, '..')
const ORIGIN = process.env.PRODUCT_OG_ORIGIN ?? 'http://localhost:3000'

async function toOgAvif(png: Buffer): Promise<Buffer> {
  return sharp(png)
    .resize({
      width: PRODUCT_OG_IMAGE_WIDTH,
      height: PRODUCT_OG_IMAGE_HEIGHT,
      fit: 'cover',
      position: 'top',
    })
    .avif({ quality: 82, effort: 4, chromaSubsampling: '4:4:4' })
    .toBuffer()
}

async function main() {
  const browser = await chromium.launch()
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 2,
    colorScheme: 'dark',
    reducedMotion: 'reduce',
  })
  const page = await context.newPage()

  await page.addInitScript(() => {
    try {
      localStorage.setItem('screenshot:modeOpen', 'true')
      localStorage.setItem('theme', 'dark')
    } catch {
      // localStorage unavailable
    }
    document.documentElement?.classList.add('dark')
  })

  for (const productId of PRODUCT_IDS) {
    const url = `${ORIGIN}/products/${productId}`
    console.log(`Capturing ${url}`)
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 90_000 })
    await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' })
    await page.evaluate(() => {
      document.documentElement.classList.add('dark')
    })
    const frame = page.locator('[data-product-og-frame]')
    await frame.waitFor({ state: 'attached', timeout: 60_000 })
    await page.evaluate(() => {
      const node = document.querySelector('[data-product-og-frame]')
      if (!(node instanceof HTMLElement)) {
        throw new Error('Product OG frame is missing')
      }
      node.style.insetInlineStart = '0'
      node.style.top = '0'
      node.style.zIndex = '9999'
      document.documentElement.classList.add('dark')
    })
    await page.addStyleTag({
      content: `
        header,
        footer,
        [data-cookie-consent],
        [data-radix-portal],
        [role="dialog"] {
          display: none !important;
        }
      `,
    })
    await page.evaluate(() => document.fonts.ready)
    await new Promise((resolve) => setTimeout(resolve, 250))
    const png = await frame.screenshot({ type: 'png', animations: 'disabled' })
    const outputPath = join(ROOT, 'public/images/products', productId, 'og.avif')
    mkdirSync(dirname(outputPath), { recursive: true })
    await Bun.write(outputPath, await toOgAvif(png))
    console.log(`Wrote ${outputPath.replace(`${ROOT}/`, '')}`)
  }

  await browser.close()
}

main().catch((error) => {
  console.error(error)
  process.exit(1)
})
