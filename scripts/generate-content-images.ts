/**
 * Converts non-AVIF raster images under public/images/{blog,docs,changelog} to AVIF
 * and updates matching paths in markdoc content.
 *
 * Run: bun run generate:content-images
 */
import { convertImagesToAvif } from './lib/convert-images-to-avif.ts'

async function main() {
  const { converted, skippedExisting, referencesUpdated } =
    await convertImagesToAvif()

  console.log(
    `Done. Converted ${converted} file(s), ${skippedExisting} already AVIF, updated ${referencesUpdated} content file(s).`,
  )
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})
