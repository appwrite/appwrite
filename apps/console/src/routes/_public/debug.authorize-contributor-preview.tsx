import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import {
  View,
  type AuthorizeContributorPreviewStatus,
} from '@/components/pages/git/authorize-contributor/View'
import { DEBUG_DEMO_MOCK_EMAIL } from '@/lib/debug-demos/constants'
import { pageTitle } from '@/lib/utils/page-title'

const authorizeContributorPreviewSearchSchema = z.object({
  status: z.enum(['awaiting', 'success', 'error']).optional(),
})

export const Route = createFileRoute(
  '/_public/debug/authorize-contributor-preview',
)({
  validateSearch: authorizeContributorPreviewSearchSchema,
  head: () => ({
    meta: [{ title: pageTitle('Authorize contributor preview') }],
  }),
  component: AuthorizeContributorPreviewPage,
})

function AuthorizeContributorPreviewPage() {
  const search = Route.useSearch()
  const status: AuthorizeContributorPreviewStatus = search.status ?? 'awaiting'

  return (
    <div className="relative h-[100dvh] max-h-[100dvh] overflow-hidden bg-background">
      <View
        preview
        previewStatus={status}
        projectId="demo-project"
        installationId="demo-installation"
        repositoryId="demo-repository"
        providerPullRequestId="3183"
        accountLabel={DEBUG_DEMO_MOCK_EMAIL}
      />
    </div>
  )
}
