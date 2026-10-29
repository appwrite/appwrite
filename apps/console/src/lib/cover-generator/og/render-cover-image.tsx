import { ImageResponse } from '@vercel/og'
import { CoverOgRoot } from '@/lib/cover-generator/og/cover-root'
import { loadCoverOgFonts } from '@/lib/cover-generator/og/fonts'
import { prepareCoverRenderData } from '@/lib/cover-generator/og/prepare-cover-data'
import { encodeCoverImageBuffer } from '@/lib/cover-generator/encode-cover-image-sharp'
import type { CoverRenderData } from '@/lib/cover-generator/types'

export async function renderCoverImageWithOg(
  data: Extract<CoverRenderData, { template: 'screenshot-angled' }>,
): Promise<Uint8Array> {
  const [fonts, prepared] = await Promise.all([
    loadCoverOgFonts(),
    prepareCoverRenderData(data),
  ])

  const response = new ImageResponse(
    <CoverOgRoot data={data} prepared={prepared} />,
    {
      width: data.width,
      height: data.height,
      fonts,
    },
  )

  let bytes = new Uint8Array(await response.arrayBuffer())

  if (data.format !== 'png') {
    bytes = new Uint8Array(
      await encodeCoverImageBuffer(Buffer.from(bytes), data.format),
    )
  }

  return bytes
}
