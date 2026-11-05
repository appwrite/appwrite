import { createFileRoute } from '@tanstack/react-router'
import { handleApiReferenceModelRequest } from '@/server/api-reference/http-handlers'

export const Route = createFileRoute('/_api/references-api/model')({
  server: {
    handlers: {
      GET: ({ request }) => handleApiReferenceModelRequest(request),
    },
  },
})
