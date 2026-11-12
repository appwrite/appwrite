import { createFileRoute } from '@tanstack/react-router'
import { handleReferenceOpenApiSpecRequest } from '@/server/api-reference/http-handlers'

export const Route = createFileRoute('/_api/openapi.json')({
  server: {
    handlers: {
      GET: ({ request }) => handleReferenceOpenApiSpecRequest(request),
    },
  },
})
