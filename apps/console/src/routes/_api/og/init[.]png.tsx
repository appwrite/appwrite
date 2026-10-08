import { createFileRoute } from '@tanstack/react-router'
import { getCoverImageExtension, getCoverImageMimeType } from '@/lib/cover-generator/cover-image-format'
import { runWithCoverRenderContext } from '@/lib/cover-generator/render-context'
import { INIT_PAGE_OG_IMAGE_PARAMS } from '@/lib/init/init-seo'
import { parseOgImageRenderData } from '@/lib/seo/og-image'

export const Route = createFileRoute('/_api/og/init.png')({
  server: {
    handlers: {
      GET: async ({ request }) => {
        const searchParams = new URLSearchParams()
        searchParams.set('title', INIT_PAGE_OG_IMAGE_PARAMS.title)
        searchParams.set('subtitle', INIT_PAGE_OG_IMAGE_PARAMS.subtitle)
        searchParams.set('eyebrow', INIT_PAGE_OG_IMAGE_PARAMS.eyebrow)
        searchParams.set('cta', INIT_PAGE_OG_IMAGE_PARAMS.cta)

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
              'Content-Disposition': `inline; filename="init-og.${extension}"`,
              'Cache-Control': 'public, max-age=86400, stale-while-revalidate=604800',
            },
          })
        } catch {
          return new Response('Failed to render Init Open Graph image', { status: 500 })
        }
      },
    },
  },
})
