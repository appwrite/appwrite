import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { ExternalLink } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useAnalytics } from '@/hooks/use-analytics'
import { useT } from '@/lib/i18n/translate'
import {
  approveExternalDeployments,
  useInstallation,
  useRepository,
} from '@/lib/react-query/hooks/vcs'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  getProviderPullRequestUrl,
  getVcsProvider,
  VcsIcon,
} from '@/lib/vcs/providers'

export type AuthorizeContributorPreviewStatus = 'awaiting' | 'success' | 'error'

type ViewProps = {
  projectId: string
  installationId: string
  repositoryId: string
  providerPullRequestId: string
  preview?: boolean
  previewStatus?: AuthorizeContributorPreviewStatus
}

export function View({
  projectId,
  installationId,
  repositoryId,
  providerPullRequestId,
  preview = false,
  previewStatus,
}: ViewProps) {
  const t = useT()
  const { track } = useAnalytics()
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [approved, setApproved] = useState(false)
  const { data: installation } = useInstallation(
    preview ? null : projectId,
    preview ? null : installationId,
  )
  const { data: repository } = useRepository(
    preview ? null : projectId,
    preview ? null : installationId,
    preview ? null : repositoryId,
  )

  const providerMeta = getVcsProvider(
    preview ? 'github' : (repository?.provider ?? installation?.provider),
  )
  const isMergeRequest = providerMeta.id === 'gitlab'
  const requestLabel = isMergeRequest ? t('Merge request') : t('Pull request')
  const requestRef = isMergeRequest
    ? `!${providerPullRequestId}`
    : `#${providerPullRequestId}`
  const pullRequestUrl = getProviderPullRequestUrl({
    provider: preview
      ? 'github'
      : (repository?.provider ?? installation?.provider),
    organization: preview
      ? 'appwrite'
      : (repository?.organization ?? installation?.organization),
    repositoryName: preview ? 'console' : repository?.name,
    pullRequestId: providerPullRequestId,
  })

  const approveMutation = useMutation({
    mutationFn: () =>
      approveExternalDeployments(
        projectId,
        installationId,
        repositoryId,
        providerPullRequestId,
      ),
    onSuccess: () => {
      setErrorMessage(null)
      setApproved(true)
      track('Resource Created', { resource: 'git-external-deployment' })
    },
    onError: (error) => {
      setApproved(false)
      setErrorMessage(
        getErrorMessage(error, t('Failed to approve deployment')),
      )
      track('Resource Creation Failed', { resource: 'git-external-deployment' })
    },
  })

  const status: AuthorizeContributorPreviewStatus = preview
    ? (previewStatus ?? 'awaiting')
    : approved
      ? 'success'
      : errorMessage
        ? 'error'
        : 'awaiting'

  const isApproving = !preview && approveMutation.isPending
  const isApproved = status === 'success'

  const handleApprove = () => {
    if (preview || isApproving || isApproved) return
    setErrorMessage(null)
    approveMutation.mutate()
  }

  const statusBadge =
    status === 'success' ? (
      <Badge variant="success" className="text-[10px] shrink-0">
        {t('Approved')}
      </Badge>
    ) : status === 'error' ? (
      <Badge variant="error" className="text-[10px] shrink-0">
        {t('Failed')}
      </Badge>
    ) : (
      <Badge variant="pending" className="text-[10px] shrink-0">
        {t('Pending')}
      </Badge>
    )

  const chipClassName =
    'inline-flex items-center gap-1.5 rounded-md border border-border bg-muted/50 px-2 py-1'
  const chipInner = (
    <>
      <VcsIcon
        type={providerMeta.id}
        className="size-3.5 shrink-0 text-muted-foreground"
      />
      <span className="text-[12px] text-muted-foreground">{requestLabel}</span>
      <span dir="ltr" className="font-mono text-[12px] text-foreground">
        {requestRef}
      </span>
    </>
  )
  const chipAriaLabel = `${providerMeta.label} ${requestLabel} ${requestRef}`

  return (
    <div className="bg-background relative flex h-[100dvh] max-h-[100dvh] flex-col items-center justify-center overflow-hidden p-6 md:p-10">
      <div
        className={
          preview
            ? 'w-full max-w-md pt-10'
            : 'w-full max-w-md'
        }
      >
        <Card className="overflow-hidden p-6 md:p-8">
          <div className="space-y-6">
            <div className="flex flex-col items-center gap-4 text-center">
              {statusBadge}
              <div className="space-y-1">
                <h1 className="text-2xl font-semibold tracking-tight">
                  {isApproved
                    ? t('Git deployment authorized')
                    : t('Authorize Git deployment')}
                </h1>
                <p className="text-muted-foreground text-[13px] leading-relaxed">
                  {isApproved
                    ? t('The build will start shortly.')
                    : t(
                        'A contributor opened this pull request. Approve it to start the Git deployment.',
                      )}
                </p>
              </div>
              {pullRequestUrl ? (
                <a
                  href={pullRequestUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className={`${chipClassName} hover:bg-muted transition-colors`}
                  aria-label={`${t('Open in new window')}: ${chipAriaLabel}`}
                >
                  {chipInner}
                  <ExternalLink className="size-3 shrink-0 text-muted-foreground" />
                </a>
              ) : (
                <div className={chipClassName}>{chipInner}</div>
              )}
            </div>

            {status === 'error' && errorMessage ? (
              <p className="text-muted-foreground text-center text-[13px] leading-relaxed">
                {errorMessage}
              </p>
            ) : null}

            {preview && status === 'error' ? (
              <p className="text-muted-foreground text-center text-[13px] leading-relaxed">
                {t('Failed to approve deployment')}
              </p>
            ) : null}

            <Button
              type="button"
              className="w-full"
              disabled={isApproving || isApproved}
              onClick={handleApprove}
              {...analyticsAttrs('approve-git-deployment')}
            >
              {t('Approve deployment')}
            </Button>
          </div>
        </Card>
        <div className="mt-10 md:mt-16 flex justify-center">
          <AppwriteLogo className="h-6 w-auto" />
        </div>
      </div>
    </div>
  )
}

