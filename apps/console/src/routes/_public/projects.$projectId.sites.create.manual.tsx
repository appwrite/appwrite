/**
 * Manual Upload Screen
 *
 * Upload a tar.gz file containing site source code.
 */

import { createFileRoute } from '@tanstack/react-router'
import { ManualUploadView } from '@/components/pages/projects/$projectId/sites/create/ManualUploadView'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/create/manual',
)({
  head: () => ({ meta: [{ title: pageTitle('Create manually', 'Sites') }] }),
  component: ManualUploadPage,
})

function ManualUploadPage() {
  return <ManualUploadView />
}
