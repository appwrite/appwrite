import { createFileRoute } from '@tanstack/react-router'
import { buildSchemamap } from '@/lib/seo/schemamap'

export const Route = createFileRoute('/_api/schemamap.xml')({
  server: {
    handlers: {
      GET: async () =>
        new Response(buildSchemamap(), {
          headers: {
            'Content-Type': 'application/xml; charset=utf-8',
            'Cache-Control': 'public, max-age=86400',
          },
        }),
    },
  },
})
