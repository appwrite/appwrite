/**
 * Manual Upload Screen
 *
 * Upload a tar.gz file containing site source code.
 */

import { createFileRoute } from '@tanstack/react-router'
import { ManualUploadView } from '@/components/pages/projects/$projectId/sites/create-site/ManualUploadView'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/create-site/manual',
)({
  component: ManualUploadPage,
})

function ManualUploadPage() {
  return <ManualUploadView />
}
