import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { AuthFlowAccountSwitcher } from '@/components/global/auth/AuthFlowAccountSwitcher'
import { AuthFlowAccountSwitcherStatic } from '@/components/global/auth/AuthFlowAccountSwitcherStatic'
import {
  AuthFlowDescription,
  AuthFlowNarrowCard,
  AuthFlowTitle,
} from '@/components/global/auth/AuthFlowCard'
import { AuthFlowHeaderIcon } from '@/components/global/auth/AuthFlowHeaderIcon'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import { useAuthAccountSwitch } from '@/components/global/auth/useAuthAccountSwitch'
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
  GenericGitIcon,
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
  accountLabel?: string
}

export function View({
  projectId,
  installationId,
  repositoryId,
  providerPullRequestId,
  preview = false,
  previewStatus,
  accountLabel: accountLabelProp,
}: ViewProps) {
  const t = useT()
  const { track } = useAnalytics()
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [approved, setApproved] = useState(false)
  const [isSwitchingAccount, setIsSwitchingAccount] = useState(false)
  const defaultSwitchAccount = useAuthAccountSwitch({ preview })
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
  const accountLabel = preview
    ? (accountLabelProp || 'dev@appwrite.io')
    : (accountLabelProp ?? '')

  const handleApprove = () => {
    if (preview || isApproving || isApproved || isSwitchingAccount) return
    setErrorMessage(null)
    approveMutation.mutate()
  }

  const handleSwitchAccount = async () => {
    if (preview || isApproving || isSwitchingAccount) return
    setIsSwitchingAccount(true)
    try {
      await defaultSwitchAccount()
    } finally {
      setIsSwitchingAccount(false)
    }
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
        className="size-4 shrink-0 text-muted-foreground"
      />
      <span className="text-[12px] text-muted-foreground">{requestLabel}</span>
      <span dir="ltr" className="font-mono text-[12px] text-foreground">
        {requestRef}
      </span>
    </>
  )
  const chipAriaLabel = `${providerMeta.label} ${requestLabel} ${requestRef}`

  const accountSwitcher = accountLabel ? (
    preview ? (
      <AuthFlowAccountSwitcherStatic
        accountLabel={accountLabel}
        preview
        disabled={isApproving || isSwitchingAccount}
      />
    ) : (
      <AuthFlowAccountSwitcher
        accountLabel={accountLabel}
        onSwitchAccount={handleSwitchAccount}
        disabled={isApproving || isSwitchingAccount}
      />
    )
  ) : null

  return (
    <main id="main-content" className="h-[100dvh] max-h-[100dvh]">
      <AuthFlowShell
        width="narrow"
        showLegal={false}
        accountSwitcher={accountSwitcher}
      >
        <AuthFlowNarrowCard>
          <div className="space-y-6">
            <div className="flex flex-col items-center gap-4 text-center">
              <AuthFlowHeaderIcon icon={GenericGitIcon} />
              {statusBadge}
              <div className="space-y-1">
                <AuthFlowTitle>
                  {isApproved
                    ? t('Git deployment authorized')
                    : t('Authorize Git deployment')}
                </AuthFlowTitle>
                <AuthFlowDescription>
                  {isApproved
                    ? t('The build will start shortly.')
                    : t(
                        'A contributor opened this pull request. Approve it to start the Git deployment.',
                      )}
                </AuthFlowDescription>
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
                </a>
              ) : (
                <div className={chipClassName}>{chipInner}</div>
              )}
            </div>

            {status === 'error' && errorMessage ? (
              <AuthFlowDescription className="text-center">
                {errorMessage}
              </AuthFlowDescription>
            ) : null}

            {preview && status === 'error' ? (
              <AuthFlowDescription className="text-center">
                {t('Failed to approve deployment')}
              </AuthFlowDescription>
            ) : null}

            <Button
              type="button"
              variant="brandCta"
              className="w-full"
              disabled={isApproving || isApproved || isSwitchingAccount}
              onClick={handleApprove}
              {...analyticsAttrs('approve-git-deployment')}
            >
              {t('Approve deployment')}
            </Button>
          </div>
        </AuthFlowNarrowCard>
      </AuthFlowShell>
    </main>
  )
}
