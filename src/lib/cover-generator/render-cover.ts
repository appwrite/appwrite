import type { CoverRenderData } from '@/lib/cover-generator/types'
import { renderCoverImageWithOg } from '@/lib/cover-generator/og/render-cover-image'
import { renderCoverImageWithSharp } from '@/lib/cover-generator/render-cover-sharp'
import { renderScreenshotCoverPng } from '@/lib/cover-generator/templates/screenshot'
import { renderScreenshotSideCoverPng } from '@/lib/cover-generator/templates/screenshot-side'

/**
 * Flat screenshot uses Sharp (SVG shell + raster composite) so the image layer
 * stays aligned on non-OG aspect ratios. Angled screenshot uses @vercel/og;
 * angled downloads can also use DOM capture in the browser.
 * Other templates use Sharp/SVG (gradient text, logos, hero background).
 */
export async function renderCoverImage(data: CoverRenderData): Promise<Uint8Array> {
  if (data.template === 'screenshot') {
    return renderScreenshotCoverPng(data)
  }
  if (data.template === 'screenshot-side') {
    return renderScreenshotSideCoverPng(data)
  }
  if (data.template === 'screenshot-angled') {
    return renderCoverImageWithOg(data)
  }
  if (data.template === 'cards-angled') {
    throw new Error(
      'The cards-angled template uses browser 3D rendering. Download from the generator UI.',
    )
  }
  return renderCoverImageWithSharp(data)
}
