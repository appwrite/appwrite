/**
 * Function Repository Configuration Route
 *
 * Configure and create a function from a selected Git repository.
 */

import { createFileRoute } from '@tanstack/react-router'
import { RepositoryConfigView } from '@/components/pages/projects/$projectId/functions/create/RepositoryConfigView'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/create/repository/$repository',
)({
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
