import { createFileRoute } from '@tanstack/react-router'
import {
  Layout,
  type LayoutInitialData,
} from '@/components/pages/projects/$projectId/distribution/$appId/Layout'
import {
  distributionAppQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/distribution/$appId',
)({
  head: ({ loaderData }) => ({
    meta: [{ title: pageTitle(appName(loaderData), 'Distribution') }],
  }),
  loader: async ({
    params,
    context,
  }): Promise<LayoutInitialData | undefined> => {
    if (typeof window === 'undefined') return undefined
    const { projectId, appId } = params
    const { queryClient } = context
    try {
      await queryClient.ensureQueryData(projectQueryOptions(projectId))
      const app = await queryClient.ensureQueryData(
        distributionAppQueryOptions(projectId, appId),
      )
      return { app }
    } catch (error) {
      console.warn('Failed to load the distribution app:', error)
      return undefined
    }
  },
  component: AppLayoutPage,
})

function AppLayoutPage() {
  const { appId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  return <Layout key={`distribution-app-${appId}`} initialData={loaderData} />
}

function appName(loaderData: LayoutInitialData | undefined): string {
  return loaderData?.app.name ?? 'App'
}
