import { createFileRoute } from '@tanstack/react-router'
import { getCoverImageExtension, getCoverImageMimeType } from '@/lib/cover-generator/cover-image-format'
import { runWithCoverRenderContext } from '@/lib/cover-generator/render-context'
import { parseOgImageRenderData } from '@/lib/seo/og-image'

export const Route = createFileRoute('/_api/og/image.png')({
  server: {
    handlers: {
      GET: async ({ request }) => {
        const searchParams = new URL(request.url).searchParams
        const data = parseOgImageRenderData(searchParams)

        try {
          const siteOrigin = new URL(request.url).origin
          const { renderCoverImage } = await import('@/lib/cover-generator/render-cover')
          const image = await runWithCoverRenderContext(siteOrigin, () =>
            renderCoverImage(data),
          )
          const body = image.buffer.slice(
            image.byteOffset,
            image.byteOffset + image.byteLength,
          ) as ArrayBuffer
          const extension = getCoverImageExtension(data.format)
          const contentType = getCoverImageMimeType(data.format)

          return new Response(body, {
            headers: {
              'Content-Type': contentType,
              'Content-Disposition': `inline; filename="og.${extension}"`,
              'Cache-Control': 'public, max-age=86400, stale-while-revalidate=604800',
            },
          })
        } catch {
          return new Response('Failed to render Open Graph image', { status: 500 })
        }
      },
    },
  },
})
