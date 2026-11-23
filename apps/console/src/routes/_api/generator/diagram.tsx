import { createFileRoute } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  getCoverImageExtension,
  getCoverImageMimeType,
} from '@/lib/cover-generator/cover-image-format'
import { parseDiagramDocumentFromJson } from '@/lib/diagram-generator/parse-diagram-document'
import { renderDiagramImage } from '@/lib/diagram-generator/render-diagram-image'

function resolveDiagramDisposition(
  request: Request,
  fallback: 'inline' | 'attachment',
): 'inline' | 'attachment' {
  const value = new URL(request.url).searchParams.get('disposition')?.trim().toLowerCase()
  if (value === 'attachment' || value === 'inline') return value
  return fallback
}

function buildDiagramFilename(document: Parameters<typeof renderDiagramImage>[0]): string {
  const slug =
    document.title.trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') ||
    'diagram'
  const extension = getCoverImageExtension(document.format)
  return `${slug}-${document.width}x${document.height}.${extension}`
}

export const Route = createFileRoute('/_api/generator/diagram')({
  server: {
    handlers: {
      POST: async ({ request }) => {
        if (!getActiveProfileFeatures().marketing) {
          return new Response('Not found', { status: 404 })
        }

        try {
          const payload = await request.json()
          const document = parseDiagramDocumentFromJson(payload)
          const image = await renderDiagramImage(document)
          const body = image.buffer.slice(
            image.byteOffset,
            image.byteOffset + image.byteLength,
          ) as ArrayBuffer
          const contentType = getCoverImageMimeType(document.format)
          const disposition = resolveDiagramDisposition(request, 'inline')

          return new Response(body, {
            headers: {
              'Content-Type': contentType,
              'Content-Disposition': `${disposition}; filename="${buildDiagramFilename(document)}"`,
              'Cache-Control': 'public, max-age=300',
            },
          })
        } catch {
          return new Response('Failed to render diagram', { status: 500 })
        }
      },
    },
  },
})
