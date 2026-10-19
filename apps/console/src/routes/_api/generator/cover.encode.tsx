import { createFileRoute } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { COVER_IMAGE_FORMATS, type CoverImageFormat } from '@/lib/cover-generator/constants'
import { getCoverImageMimeType } from '@/lib/cover-generator/cover-image-format'
import { encodeCoverImageBuffer } from '@/lib/cover-generator/encode-cover-image-sharp'

function parseEncodeFormat(value: string | null): CoverImageFormat | null {
  const normalized = value?.trim().toLowerCase()
  if (!normalized || normalized === 'png') return null
  if ((COVER_IMAGE_FORMATS as readonly string[]).includes(normalized)) {
    return normalized as CoverImageFormat
  }
  return null
}

export const Route = createFileRoute('/_api/generator/cover/encode')({
  server: {
    handlers: {
      POST: async ({ request }) => {
        if (!getActiveProfileFeatures().marketing) {
          return new Response('Not found', { status: 404 })
        }

        const format = parseEncodeFormat(new URL(request.url).searchParams.get('format'))
        if (!format) {
          return new Response('Unsupported format', { status: 400 })
        }

        try {
          const input = Buffer.from(await request.arrayBuffer())
          const output = await encodeCoverImageBuffer(input, format)

          return new Response(output, {
            headers: {
              'Content-Type': getCoverImageMimeType(format),
              'Cache-Control': 'no-store',
            },
          })
        } catch {
          return new Response('Failed to encode cover image', { status: 500 })
        }
      },
    },
  },
})
