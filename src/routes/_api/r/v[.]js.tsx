import { createFileRoute } from '@tanstack/react-router'
import { proxyPlausibleScript } from '@/lib/plausible-proxy'
import { getRuntimeConfig } from '@/lib/runtime-config'

export const Route = createFileRoute('/_api/r/v.js')({
  server: {
    handlers: {
      GET: async () => {
        const scriptSrc = getRuntimeConfig().plausibleScriptSrc
        return proxyPlausibleScript(scriptSrc)
      },
    },
  },
})
