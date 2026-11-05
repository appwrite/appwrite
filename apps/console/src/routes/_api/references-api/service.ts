import { createFileRoute } from '@tanstack/react-router'
import { handleApiReferenceServiceRequest } from '@/server/api-reference/http-handlers'

export const Route = createFileRoute('/_api/references-api/service')({
  server: {
    handlers: {
      GET: ({ request }) => handleApiReferenceServiceRequest(request),
    },
  },
})
