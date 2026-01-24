/**
 * Repository Configuration Screen
 *
 * Configuration screen for deploying a site from a selected repository.
 * Handles site name, framework, build settings, environment variables, and domain.
 */

import { createFileRoute } from '@tanstack/react-router'
import { RepositoryConfigView } from '@/components/pages/projects/$projectId/sites/create-site/RepositoryConfigView'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/create-site/repositories/$repository',
)({
  component: RepositoryConfigPage,
})

function RepositoryConfigPage() {
  const { repository } = Route.useParams()
  return <RepositoryConfigView repositoryParam={repository} />
}
