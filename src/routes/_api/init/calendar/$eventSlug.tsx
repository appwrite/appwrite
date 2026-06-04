import { createFileRoute } from '@tanstack/react-router'
import { LAUNCH_EVENTS } from '@/lib/init/events'
import { buildInitEventCalendarIcs } from '@/lib/init/init-calendar'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

export const Route = createFileRoute('/_api/init/calendar/$eventSlug')({
  server: {
    handlers: {
      GET: async ({ params, request }) => {
        if (!getActiveProfileFeatures().init) {
          return new Response('Not found', { status: 404 })
        }

        const event = LAUNCH_EVENTS.find(
          (entry) => entry.slug === params.eventSlug,
        )
        if (!event || event.days.length === 0) {
          return new Response('Not found', { status: 404 })
        }

        const download =
          new URL(request.url).searchParams.get('download') === '1'
        const ics = buildInitEventCalendarIcs(event, { now: new Date() })

        return new Response(ics, {
          headers: {
            'Content-Type': 'text/calendar; charset=utf-8',
            'Content-Disposition': download
              ? `attachment; filename="${event.slug}.ics"`
              : `inline; filename="${event.slug}.ics"`,
            'Cache-Control': 'public, max-age=300',
          },
        })
      },
    },
  },
})
