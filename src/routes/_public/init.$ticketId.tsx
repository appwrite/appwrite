import { createFileRoute, notFound, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/init/ticket/View'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  buildInitTicketShareUrl,
  getInitTicketShareRouteMetaTags,
  initTicketStorageFileExists,
} from '@/lib/init/init-ticket-share'
import { getRequestSiteOrigin } from '@/lib/marketing/site-origin'

export const Route = createFileRoute('/_public/init/$ticketId')({
  ssr: true,
  loader: async ({ params }) => {
    if (!getActiveProfileFeatures().init) {
      throw redirect({ to: '/', replace: true })
    }

    const ticketId = params.ticketId.trim()
    if (!ticketId) {
      throw notFound()
    }

    const exists = await initTicketStorageFileExists(ticketId)
    if (!exists) {
      throw notFound()
    }

    const siteOrigin = getRequestSiteOrigin()
    return {
      ticketId,
      canonicalUrl: buildInitTicketShareUrl(ticketId, siteOrigin),
    }
  },
  head: ({ loaderData, params }) => {
    const ticketId = loaderData?.ticketId ?? params.ticketId
    if (!ticketId) return {}

    return {
      meta: getInitTicketShareRouteMetaTags({ ticketId }),
    }
  },
  component: InitTicketSharePage,
})

function InitTicketSharePage() {
  const { ticketId } = Route.useLoaderData()
  return <View ticketId={ticketId} />
}
