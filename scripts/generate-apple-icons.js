#!/usr/bin/env node

/**
 * Script to generate Apple Touch Icons from SVG logo
 * This generates PNG files required for iOS devices
 */

import { readFile } from 'fs/promises'
import { fileURLToPath } from 'url'
import { dirname, join } from 'path'

const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)
const rootDir = join(__dirname, '..')

async function generateAppleIcons() {
  try {
    // Try to use sharp if available
    let sharp
    try {
      sharp = (await import('sharp')).default
    } catch (e) {
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

    // Read the SVG file
    const svgPath = join(rootDir, 'public', 'logo.svg')
    const svgBuffer = await readFile(svgPath)

    // Generate 180x180 PNG (standard iOS size)
    const outputPath = join(rootDir, 'public', 'apple-touch-icon.png')

    await sharp(svgBuffer)
      .resize(180, 180, {
        fit: 'contain',
        background: { r: 255, g: 255, b: 255, alpha: 0 }, // Transparent background
      })
      .png({
        compressionLevel: 9,
        adaptiveFiltering: true,
      })
      .toFile(outputPath)

    console.log('✅ Successfully generated apple-touch-icon.png (180x180)')
    console.log(`   Output: ${outputPath}`)
  } catch (error) {
    console.error('❌ Error generating Apple icons:', error.message)
    process.exit(1)
  }
}

generateAppleIcons()
