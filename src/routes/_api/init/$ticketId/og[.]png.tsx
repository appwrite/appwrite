import { createFileRoute } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { initTicketStorageFileExists } from '@/lib/init/init-ticket-share'
import { getInitTicketStorageFileViewUrl } from '@/lib/init/init-ticket-storage-config'

export const Route = createFileRoute('/_api/init/$ticketId/og.png')({
  server: {
    handlers: {
      GET: async ({ params }) => {
        if (!getActiveProfileFeatures().init) {
          return new Response('Not found', { status: 404 })
        }

        const ticketId = params.ticketId.trim()
        if (!ticketId) {
          return new Response('Not found', { status: 404 })
        }

        const exists = await initTicketStorageFileExists(ticketId)
        if (!exists) {
          return new Response('Not found', { status: 404 })
        }

        try {
          const viewUrl = getInitTicketStorageFileViewUrl(ticketId)
          const response = await fetch(viewUrl)
          if (!response.ok) {
            return new Response('Not found', { status: 404 })
          }

          const body = await response.arrayBuffer()
          return new Response(body, {
            headers: {
              'Content-Type': 'image/png',
              'Content-Disposition': `inline; filename="init-ticket-${ticketId}.png"`,
              'Cache-Control': 'public, max-age=86400, stale-while-revalidate=604800',
            },
          })
        } catch {
          return new Response('Failed to load ticket image', { status: 502 })
        }
      },
    },
  },
})
