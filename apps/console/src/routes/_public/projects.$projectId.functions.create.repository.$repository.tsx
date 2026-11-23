/**
 * Function Repository Configuration Route
 *
 * Configure and create a function from a selected Git repository.
 */

import { createFileRoute } from '@tanstack/react-router'
import { RepositoryConfigView } from '@/components/pages/projects/$projectId/functions/create/RepositoryConfigView'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/create/repository/$repository',
)({
  head: () => ({
    meta: [{ title: pageTitle('Create from repository', 'Functions') }],
  }),
  component: RepositoryConfigPage,
})

function RepositoryConfigPage() {
  const { repository } = Route.useParams()
  const search = Route.useSearch({ strict: false })
  return (
    <RepositoryConfigView
      repositoryParam={repository}
      installationIdFromSearch={
        (search as { installationId?: string })?.installationId
      }
      providerRepositoryIdFromSearch={
        (search as { providerRepositoryId?: string })?.providerRepositoryId
      }
    />
  )
}
