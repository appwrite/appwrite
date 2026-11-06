/**
 * Create Site Index Route
 *
 * Combined entry point showing templates and repositories side by side.
 */

import { createFileRoute } from '@tanstack/react-router'
import { CreateSiteView } from '@/components/pages/projects/$projectId/sites/create/CreateSiteView'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/create/',
)({
  head: () => ({ meta: [{ title: pageTitle('Create', 'Sites') }] }),
  component: CreateSitePage,
})

function CreateSitePage() {
  return <CreateSiteView />
}
