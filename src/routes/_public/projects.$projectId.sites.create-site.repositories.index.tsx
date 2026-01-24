/**
 * Repository Selection Screen
 *
 * First screen of the repository path in the site creation wizard.
 * Shows connected GitHub installations and allows users to select a repository.
 */

import { createFileRoute } from '@tanstack/react-router'
import { RepositoriesView } from '@/components/pages/projects/$projectId/sites/create-site/RepositoriesView'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/create-site/repositories/',
)({
  component: RepositoriesPage,
})

function RepositoriesPage() {
  return <RepositoriesView />
}
