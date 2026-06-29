#!/usr/bin/env node

/**
 * Generates apple-touch-icon.png and favicon.ico from public/logo.svg.
 * Run: bun run generate:icons
 */

import { readFile, writeFile } from 'fs/promises'
import { fileURLToPath } from 'url'
import { dirname, join } from 'path'

const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)
const rootDir = join(__dirname, '..')

const TRANSPARENT = { r: 255, g: 255, b: 255, alpha: 0 }

async function pngFromLogoSvg(sharp, svgBuffer, size) {
  return sharp(svgBuffer)
    .resize(size, size, {
      fit: 'contain',
      background: TRANSPARENT,
    })
    .png({
      compressionLevel: 9,
      adaptiveFiltering: true,
    })
    .toBuffer()
}

async function generateIcons() {
  try {
    let sharp
    try {
      sharp = (await import('sharp')).default
    } catch (_e) {
      console.error('\n❌ Error: sharp is not installed.')
      console.error('\nTo fix this, install sharp as a dev dependency:')
      console.error('  bun add -d sharp')
      console.error('  or')
      console.error('  npm install --save-dev sharp')
      console.error('\nThen run this script again:')
      console.error('  bun run generate:icons')
      console.error('  or')
      console.error('  npm run generate:icons\n')
      process.exit(1)
    }

    let pngToIco
    try {
      pngToIco = (await import('png-to-ico')).default
    } catch (_e) {
      console.error('\n❌ Error: png-to-ico is not installed.')
      console.error('  bun add -d png-to-ico')
      process.exit(1)
    }

    const svgPath = join(rootDir, 'public', 'logo.svg')
    const svgBuffer = await readFile(svgPath)

    const applePath = join(rootDir, 'public', 'apple-touch-icon.png')
    await sharp(svgBuffer)
      .resize(180, 180, {
        fit: 'contain',
        background: TRANSPARENT,
      })
      .png({
        compressionLevel: 9,
        adaptiveFiltering: true,
      })
      .toFile(applePath)

    console.log('✅ Successfully generated apple-touch-icon.png (180x180)')
    console.log(`   Output: ${applePath}`)

    const faviconSizes = [16, 24, 32, 48]
    const pngBuffers = await Promise.all(
      faviconSizes.map((s) => pngFromLogoSvg(sharp, svgBuffer, s)),
    )
    const icoBuffer = await pngToIco(pngBuffers)
    const faviconPath = join(rootDir, 'public', 'favicon.ico')
    await writeFile(faviconPath, icoBuffer)

    console.log('✅ Successfully generated favicon.ico')
    console.log(`   Output: ${faviconPath}`)
  } catch (error) {
    console.error('❌ Error generating icons:', error.message)
    process.exit(1)
  }
}

generateIcons()
