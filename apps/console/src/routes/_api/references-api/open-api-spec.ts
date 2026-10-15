import { createFileRoute } from '@tanstack/react-router'
import { handleReferenceOpenApiSpecRequest } from '@/server/api-reference/http-handlers'

export const Route = createFileRoute('/_api/references-api/open-api-spec')({
  server: {
    handlers: {
      GET: ({ request }) => handleReferenceOpenApiSpecRequest(request),
    },
  },
})
