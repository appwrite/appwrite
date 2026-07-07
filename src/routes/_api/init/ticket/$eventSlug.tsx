import { createFileRoute } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { getLaunchEventBySlug } from '@/lib/init/events'

export const Route = createFileRoute('/_api/init/ticket/$eventSlug')({
  server: {
    handlers: {
      GET: async ({ params, request }) => {
        if (!getActiveProfileFeatures().init) {
          return new Response('Not found', { status: 404 })
        }

        const event = getLaunchEventBySlug(params.eventSlug)
        if (!event) {
          return new Response('Not found', { status: 404 })
        }

        const searchParams = new URL(request.url).searchParams
        const siteOrigin = new URL(request.url).origin
        const { buildInitTicketImageRenderData, renderInitTicketImagePng } =
          await import('@/lib/init/ticket-image')
        const { runWithCoverRenderContext } = await import(
          '@/lib/cover-generator/render-context'
        )
        const ticket = buildInitTicketImageRenderData(event, searchParams)
        const image = await runWithCoverRenderContext(siteOrigin, () =>
          renderInitTicketImagePng(ticket),
        )
        const body = image.buffer.slice(
          image.byteOffset,
          image.byteOffset + image.byteLength,
        ) as ArrayBuffer
        const filename = `${event.slug}-ticket.png`

        return new Response(body, {
          headers: {
            'Content-Type': 'image/png',
            'Content-Disposition': `inline; filename="${filename}"`,
            'Cache-Control': 'public, max-age=300',
          },
        })
      },
    },
  },
})
