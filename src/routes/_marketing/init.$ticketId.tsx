import { createFileRoute, notFound, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/init/ticket/View'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  buildInitTicketShareUrl,
  getInitTicketShareImageSrc,
  getInitTicketShareRouteMetaTags,
  initTicketStorageFileExists,
} from '@/lib/init/init-ticket-share'
import { getRequestSiteOrigin } from '@/lib/marketing/site-origin'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'

export const Route = createFileRoute('/_marketing/init/$ticketId')({
  staticData: {
    ...MARKETING_PAGE_ROUTE_STATIC_DATA,
    showFooter: false,
  },
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
    const imageSrc = getInitTicketShareImageSrc(ticketId)
    return {
      ticketId,
      imageSrc,
      canonicalUrl: buildInitTicketShareUrl(ticketId, siteOrigin),
    }
  },
  head: ({ loaderData, params }) => {
    const ticketId = loaderData?.ticketId ?? params.ticketId
    if (!ticketId) return {}

    const imageSrc =
      loaderData?.imageSrc ?? getInitTicketShareImageSrc(ticketId)

    return {
      meta: getInitTicketShareRouteMetaTags({ ticketId }),
      links: [
        {
          rel: 'preload',
          as: 'image',
          href: imageSrc,
        },
      ],
    }
  },
  component: InitTicketSharePage,
})

function InitTicketSharePage() {
  const { ticketId, imageSrc } = Route.useLoaderData()
  return <View ticketId={ticketId} imageSrc={imageSrc} />
}
