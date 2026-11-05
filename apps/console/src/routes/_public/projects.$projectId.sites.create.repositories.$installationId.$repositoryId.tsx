/**
 * Repository Configuration Screen
 *
 * Configuration screen for deploying a site from a selected repository.
 * URL carries installationId and provider repository ID so state survives refresh.
 */

import { createFileRoute } from '@tanstack/react-router'
import {
  repositoryBranchesQueryOptions,
} from '@/lib/react-query/hooks'
import { RepositoryConfigView } from '@/components/pages/projects/$projectId/sites/create/RepositoryConfigView'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/create/repositories/$installationId/$repositoryId',
)({
  head: () => ({
    meta: [{ title: pageTitle('Create from repository', 'Sites') }],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId, installationId, repositoryId } = params
    const { queryClient } = context
    if (projectId && installationId && repositoryId) {
      await queryClient.ensureQueryData(
        repositoryBranchesQueryOptions(projectId, installationId, repositoryId),
      )
    }
  },
  component: RepositoryConfigPage,
})

function RepositoryConfigPage() {
  const { installationId, repositoryId } = Route.useParams()
  return (
    <RepositoryConfigView
      installationId={installationId}
      providerRepositoryId={repositoryId}
    />
  )
}
