import { View } from '@/components/pages/organizations/$orgId/marketplace/$appId/View'
import { createFileRoute, redirect } from '@tanstack/react-router'
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

    const app = await queryClient.fetchQuery(organizationAppQueryOptions(appId))

    if (app.teamId === orgId) {
      throw redirect({
        to: '/organizations/$orgId/apps/$appId',
        params: { orgId, appId },
        replace: true,
      })
    }

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
