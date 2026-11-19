import { createFileRoute } from '@tanstack/react-router'
import { proxyGoogleRequest } from '@/lib/fcm/google-proxy'

/**
 * Relay for the Google Cloud calls behind the FCM quick setup. The splat is
 * `<service>/<google-path>` (see `GOOGLE_RELAY_HOSTS`); the relay forwards the
 * caller's bearer token to that host and never persists it.
 */
export const Route = createFileRoute('/_api/google/$')({
  server: {
    handlers: {
      GET: async ({ request, params }) =>
        proxyGoogleRequest(request, params._splat ?? ''),
      POST: async ({ request, params }) =>
        proxyGoogleRequest(request, params._splat ?? ''),
      DELETE: async ({ request, params }) =>
        proxyGoogleRequest(request, params._splat ?? ''),
    },
  },
})
