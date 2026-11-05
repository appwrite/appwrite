import { View } from '@/components/pages/organizations/$orgId/marketplace/$appId/View'
import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { organizationAppQueryOptions } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/marketplace/$appId/',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.app?.name ?? 'App', 'Marketplace'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined

    const { orgId, appId } = params
    const { queryClient } = context
    if (!orgId || !appId) return undefined

    // The public detail page is reachable for every app, own-org apps and
    // unlabeled ones included — no redirect to settings here.
    const app = await queryClient.fetchQuery(organizationAppQueryOptions(appId))

    return { app }
  },
  component: MarketplaceCatalogAppPage,
})

function MarketplaceCatalogAppPage() {
  const { appId } = Route.useParams()
  const loaderData = Route.useLoaderData()

  return (
    <View
      key={`marketplace-catalog-app-${appId}`}
      initialData={loaderData ? { app: loaderData.app } : undefined}
    />
  )
}
