import { createFileRoute } from '@tanstack/react-router'
import { buildChangelogFeed } from '@/lib/changelog/feed'

/** The latest changelog entries as JSON, for clients that render them natively. */
export const Route = createFileRoute('/_api/changelog.json')({
  server: {
    handlers: {
      GET: async () =>
        new Response(JSON.stringify(buildChangelogFeed()), {
          headers: {
            'Content-Type': 'application/json; charset=utf-8',
            'Cache-Control':
              'public, max-age=3600, stale-while-revalidate=86400',
          },
        }),
    },
  },
})
