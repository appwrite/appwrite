/**
 * Manual Function Creation Route
 *
 * Create a function and upload a .tar.gz file as the first deployment.
 */

import { createFileRoute } from '@tanstack/react-router'
import { ManualCreateView } from '@/components/pages/projects/$projectId/functions/create/ManualCreateView'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/create/manual',
)({
  head: () => ({
    meta: [{ title: pageTitle('Create manually', 'Functions') }],
  }),
  component: ManualCreatePage,
})

function ManualCreatePage() {
  const search = Route.useSearch({ strict: false }) as { runtime?: string }
  return <ManualCreateView runtimeFromSearch={search?.runtime} />
}
