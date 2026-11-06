import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import {
  View,
  type SitesAuthPreviewStatus,
} from '@/components/pages/auth/preview/View'
import { DEBUG_DEMO_MOCK_EMAIL } from '@/lib/debug-demos/constants'
import { pageTitle } from '@/lib/utils/page-title'

const sitesAuthPreviewSearchSchema = z.object({
  status: z.enum(['checking', 'denied', 'error', 'invalid']).optional(),
})

export const Route = createFileRoute('/_public/debug/sites-auth-preview')({
  validateSearch: sitesAuthPreviewSearchSchema,
  head: () => ({ meta: [{ title: pageTitle('Sites auth preview') }] }),
  component: SitesAuthPreviewPage,
})

function SitesAuthPreviewPage() {
  const { status: statusParam } = Route.useSearch()
  const status: SitesAuthPreviewStatus = statusParam ?? 'checking'

  return (
    <View
      preview
      previewStatus={status}
      projectId="preview-project"
      origin="https://example.com"
      path="/"
      accountLabel={DEBUG_DEMO_MOCK_EMAIL}
    />
  )
}
