import {
  Layout,
  type OrgAppLayoutInitialData,
} from '@/components/pages/organizations/$orgId/apps/$appId/Layout'
import { createFileRoute, redirect } from '@tanstack/react-router'
import { NotFoundView } from '@/components/error/NotFound'
import { pageTitle } from '@/lib/utils/page-title'
import {
  organizationAppQueryOptions,
  organizationAppSecretsQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/apps/$appId',
)({
  // App Layout already provides ConsoleLayout; avoid a nested shell.
  notFoundComponent: NotFoundView,
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.app?.name ?? 'App', 'Apps'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined

    const { orgId, appId } = params
    const { queryClient } = context
    if (!orgId || !appId) return undefined

    const app = await queryClient.fetchQuery(organizationAppQueryOptions(appId))

    if (app.teamId !== orgId) {
      throw redirect({
        to: '/organizations/$orgId/marketplace/$appId',
        params: { orgId, appId },
        replace: true,
      })
    }

    if (app.type !== 'public') {
      await queryClient
        .ensureQueryData(organizationAppSecretsQueryOptions(appId))
        .catch(() => undefined)
    }

    return { app }
  },
  component: OrgAppLayoutRoute,
})

function OrgAppLayoutRoute() {
  const loaderData = Route.useLoaderData()
  const { appId } = Route.useParams()

  return (
    <Layout
      key={`org-app-layout-${appId}`}
      initialData={
        loaderData ? { app: loaderData.app } satisfies OrgAppLayoutInitialData : undefined
      }
    />
  )
}
