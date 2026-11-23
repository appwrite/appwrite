import { createFileRoute } from '@tanstack/react-router'
import { handleReferenceNavCountsRequest } from '@/server/api-reference/http-handlers'

export const Route = createFileRoute('/_api/references-api/nav-counts')({
  server: {
    handlers: {
      GET: ({ request }) => handleReferenceNavCountsRequest(request),
    },
  },
})
