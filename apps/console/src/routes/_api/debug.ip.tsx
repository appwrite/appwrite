import { createFileRoute } from '@tanstack/react-router'
import { getClientIpSnapshotFromRequest } from '@/lib/client-ip'

export const Route = createFileRoute('/_api/debug/ip')({
  server: {
    handlers: {
      GET: async ({ request }) => {
        return Response.json(getClientIpSnapshotFromRequest(request), {
          headers: {
            'Cache-Control': 'no-store',
          },
        })
      },
    },
  },
})
