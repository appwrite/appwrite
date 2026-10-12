import { createFileRoute } from '@tanstack/react-router'
import {
  cliInstallScriptResponse,
  getCliInstallPs1,
} from '@/lib/cli/install-scripts'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { trackServerPageview } from '@/lib/server-analytics'

/**
 * Appwrite CLI install script (PowerShell).
 * @see https://appwrite.io/cli/install.ps1
 */
export const Route = createFileRoute('/cli/install.ps1')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ request }) => {
        trackServerPageview(request, { format: 'text' })
        try {
          const body = await getCliInstallPs1()
          return cliInstallScriptResponse(body)
        } catch {
          return new Response('Failed to load Appwrite CLI install script.\n', {
            status: 502,
            headers: { 'Content-Type': 'text/plain; charset=utf-8' },
          })
        }
      },
    },
  },
})
