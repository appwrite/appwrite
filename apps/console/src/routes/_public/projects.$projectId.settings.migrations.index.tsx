import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/settings/View'
import { pageTitle } from '@/lib/utils/page-title'
import {
  projectQueryOptions,
  projectMigrationsQueryOptions,
} from '@/lib/react-query/hooks'
import type { Models } from '@appwrite.io/console'

export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/migrations/',
)({
  head: () => ({ meta: [{ title: pageTitle('Migrations', 'Settings') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return undefined

    const project = await queryClient
      .ensureQueryData(projectQueryOptions(projectId))
      .catch(() => null)

    // Use same region as useProject() so query key matches: region is (project.region || 'unknown') when project exists
    const region = project ? project.region || 'unknown' : undefined
    const migrationsData = await queryClient
      .ensureQueryData(projectMigrationsQueryOptions(projectId, region))
      .catch(() => undefined)

    return {
      migrationsData: migrationsData as
        | { migrations: Models.Migration[]; total: number }
        | undefined,
    }
  },
  component: SettingsMigrationsPage,
})

function SettingsMigrationsPage() {
  const loaderData = Route.useLoaderData()
  return <View initialMigrationsData={loaderData?.migrationsData} />
}
