import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { z } from 'zod'
import { Loader2 } from 'lucide-react'
import { useEffect } from 'react'
import type { Models } from '@appwrite.io/console'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { View } from '@/components/pages/git/authorize-contributor/View'
import { pageTitle } from '@/lib/utils/page-title'

function accountDisplayLabel(account: unknown): string {
  if (!account || typeof account !== 'object') return ''
  const user = account as Models.User<Models.Preferences>
  return user.email || user.name || ''
}

const authorizeContributorSearchSchema = z.object({
  projectId: z.string().optional(),
  installationId: z.string().optional(),
  repositoryId: z.string().optional(),
  providerPullRequestId: z.string().optional(),
})

export const Route = createFileRoute('/_public/git/authorize-contributor')({
  validateSearch: authorizeContributorSearchSchema,
  head: () => ({ meta: [{ title: pageTitle('Approve deployment') }] }),
  component: AuthorizeContributorPage,
})

function AuthorizeContributorPage() {
  const search = Route.useSearch()
  const navigate = useNavigate()
  const { projectId, installationId, repositoryId, providerPullRequestId } =
    search
  const hasRequiredParams = Boolean(
    projectId && installationId && repositoryId && providerPullRequestId,
  )

  useEffect(() => {
    if (hasRequiredParams) return
    navigate({ to: '/', replace: true })
  }, [hasRequiredParams, navigate])

  if (!hasRequiredParams) {
    return (
      <div className="flex h-[100dvh] items-center justify-center bg-background">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <RequireAuth
      loadingComponent={
        <div className="flex h-[100dvh] items-center justify-center bg-background">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      }
    >
      {({ account }) => (
        <View
          projectId={projectId!}
          installationId={installationId!}
          repositoryId={repositoryId!}
          providerPullRequestId={providerPullRequestId!}
          accountLabel={accountDisplayLabel(account)}
        />
      )}
    </RequireAuth>
  )
}
