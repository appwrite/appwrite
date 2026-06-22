import { createFileRoute } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  getCoverImageExtension,
  getCoverImageMimeType,
} from '@/lib/cover-generator/cover-image-format'
import { parseCoverRenderData } from '@/lib/cover-generator/parse-params'
import { runWithCoverRenderContext } from '@/lib/cover-generator/render-context'
import type { CoverRenderData } from '@/lib/cover-generator/types'

function parseCoverRenderDataFromJson(body: unknown): CoverRenderData {
  if (!body || typeof body !== 'object') {
    throw new Error('Invalid cover payload')
  }

  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(body as Record<string, unknown>)) {
    if (value == null || value === '') continue
    params.set(key, String(value))
  }

  return parseCoverRenderData(params)
}

function resolveCoverDisposition(
  request: Request,
  fallback: 'inline' | 'attachment',
): 'inline' | 'attachment' {
  const value = new URL(request.url).searchParams.get('disposition')?.trim().toLowerCase()
  if (value === 'attachment' || value === 'inline') return value
  return fallback
}

async function renderCoverResponse(
  request: Request,
  data: CoverRenderData,
  disposition: 'inline' | 'attachment',
): Promise<Response> {
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
      'Content-Disposition': `${disposition}; filename="cover-${data.template}.${extension}"`,
      'Cache-Control': 'public, max-age=300',
    },
  })
}

export const Route = createFileRoute('/_api/generator/cover')({
  server: {
    handlers: {
      GET: async ({ request }) => {
        if (!getActiveProfileFeatures().marketing) {
          return new Response('Not found', { status: 404 })
        }

        const searchParams = new URL(request.url).searchParams
        const data = parseCoverRenderData(searchParams)

        try {
          return await renderCoverResponse(
            request,
            data,
            resolveCoverDisposition(request, 'inline'),
          )
        } catch {
          return new Response('Failed to render cover', { status: 500 })
        }
      },
      POST: async ({ request }) => {
        if (!getActiveProfileFeatures().marketing) {
          return new Response('Not found', { status: 404 })
        }

        try {
          const payload = await request.json()
          const data = parseCoverRenderDataFromJson(payload)
          return await renderCoverResponse(
            request,
            data,
            resolveCoverDisposition(request, 'inline'),
          )
        } catch {
          return new Response('Failed to render cover', { status: 500 })
        }
      },
    },
  },
})
