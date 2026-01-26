/**
 * Create Site Index Route
 *
 * Combined entry point showing templates and repositories side by side.
 */

import { createFileRoute } from '@tanstack/react-router'
import { CreateSiteView } from '@/components/pages/projects/$projectId/sites/create/CreateSiteView'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/create/',
)({
  component: CreateSitePage,
})

function CreateSitePage() {
  return <CreateSiteView />
}
