import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import {
  View,
  type AuthorizeContributorPreviewStatus,
} from '@/components/pages/git/authorize-contributor/View'
import { pageTitle } from '@/lib/utils/page-title'

const STATUS_OPTIONS: {
  value: AuthorizeContributorPreviewStatus
  label: string
}[] = [
  { value: 'awaiting', label: 'Awaiting' },
  { value: 'success', label: 'Approved' },
  { value: 'error', label: 'Failed' },
]

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
  const navigate = Route.useNavigate()
  const status: AuthorizeContributorPreviewStatus = search.status ?? 'awaiting'

  return (
    <div className="relative h-[100dvh] max-h-[100dvh] overflow-hidden bg-background">
      <div className="fixed inset-x-0 top-0 z-50 flex items-center justify-center gap-2 border-b border-border bg-background/95 px-4 py-2 backdrop-blur">
        <p className="mr-2 text-[12px] text-muted-foreground">
          Debug preview. Toggle Git contributor authorization states.
        </p>
        {STATUS_OPTIONS.map((option) => (
          <Button
            key={option.value}
            type="button"
            size="sm"
            variant={status === option.value ? 'default' : 'outline'}
            className="h-8 text-[12px]"
            onClick={() =>
              navigate({
                to: '/debug/authorize-contributor-preview',
                search: { status: option.value },
                replace: true,
              })
            }
          >
            {option.label}
          </Button>
        ))}
      </div>
      <View
        preview
        previewStatus={status}
        projectId="demo-project"
        installationId="demo-installation"
        repositoryId="demo-repository"
        providerPullRequestId="3183"
      />
    </div>
  )
}
