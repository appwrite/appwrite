import { createFileRoute } from '@tanstack/react-router'
import { encodeCoverImageBuffer } from '@/lib/cover-generator/encode-cover-image-sharp'
import { readCoverPublicAssetBuffer } from '@/lib/cover-generator/public-assets'
import { runWithCoverRenderContext } from '@/lib/cover-generator/render-context'
import { getCoverImageDimensions } from '@/lib/seo/cover-dimensions'

export const Route = createFileRoute('/_api/og/cover.jpg')({
  server: {
    handlers: {
      GET: async ({ request }) => {
        const url = new URL(request.url)
        const path = url.searchParams.get('path') ?? ''

        // Only covers listed in the manifest, so this never transcodes arbitrary files.
        if (!getCoverImageDimensions(path)) {
          return new Response('Cover not found', { status: 404 })
        }

        try {
          const source = await runWithCoverRenderContext(url.origin, () =>
            readCoverPublicAssetBuffer(path),
          )
          if (!source) {
            return new Response('Cover not found', { status: 404 })
          }

          const image = await encodeCoverImageBuffer(source, 'jpeg')
          const body = image.buffer.slice(
            image.byteOffset,
            image.byteOffset + image.byteLength,
          ) as ArrayBuffer

          return new Response(body, {
            headers: {
              'Content-Type': 'image/jpeg',
              'Content-Disposition': 'inline; filename="cover.jpg"',
              'Cache-Control':
                'public, max-age=86400, stale-while-revalidate=604800',
            },
          })
        } catch {
          return new Response('Failed to encode cover image', { status: 500 })
        }
      },
    },
  },
})
