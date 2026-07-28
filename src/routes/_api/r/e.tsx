import { createFileRoute } from '@tanstack/react-router'
import {
  proxyPlausibleEvent,
  resolvePlausibleEventUrl,
} from '@/lib/plausible-proxy'
import { getRuntimeConfig } from '@/lib/runtime-config'

export const Route = createFileRoute('/_api/r/e')({
  server: {
    handlers: {
      POST: async ({ request }) => {
        const scriptSrc = getRuntimeConfig().plausibleScriptSrc
        if (!scriptSrc) {
          return new Response('Not found', { status: 404 })
        }
        return proxyPlausibleEvent(request, resolvePlausibleEventUrl(scriptSrc))
      },
    },
  },
})
