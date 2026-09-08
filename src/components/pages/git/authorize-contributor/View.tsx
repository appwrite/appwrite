import { assetUrl } from '@/lib/asset-url'
import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeftRight, Check, ChevronDown } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useAnalytics } from '@/hooks/use-analytics'
import { useT } from '@/lib/i18n/translate'
import { performConsoleSignOut } from '@/lib/react-query/hooks/auth'
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
  const queryClient = useQueryClient()
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [approved, setApproved] = useState(false)
  const [isSwitchingAccount, setIsSwitchingAccount] = useState(false)
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
    ? (accountLabelProp || 'demo@appwrite.io')
    : (accountLabelProp ?? '')
  const accountInitial = (accountLabel.trim()[0] ?? '?').toUpperCase()

  const handleApprove = () => {
    if (preview || isApproving || isApproved || isSwitchingAccount) return
    setErrorMessage(null)
    approveMutation.mutate()
  }

  const handleSwitchAccount = () => {
    if (preview || isApproving || isSwitchingAccount) return
    setIsSwitchingAccount(true)
    const redirect =
      typeof window === 'undefined'
        ? undefined
        : `${window.location.pathname}${window.location.search}`
    void performConsoleSignOut(queryClient, { redirect })
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
                  href={assetUrl(pullRequestUrl)}
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
              disabled={isApproving || isApproved || isSwitchingAccount}
              onClick={handleApprove}
              {...analyticsAttrs('approve-git-deployment')}
            >
              {t('Approve deployment')}
            </Button>
          </div>
        </Card>
        {accountLabel ? (
          <div className="mt-6 mb-16 flex justify-center md:mt-8 md:mb-20">
            <DropdownMenu>
              <DropdownMenuTrigger
                asChild
                disabled={isApproving || isSwitchingAccount}
              >
                <button
                  type="button"
                  className="cursor-pointer text-muted-foreground hover:text-foreground border-border hover:bg-muted/50 flex max-w-full items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-[12px] transition disabled:opacity-60"
                  aria-label={`${t('Signed in as')} ${accountLabel}`}
                >
                  <span className="bg-muted text-muted-foreground flex size-5 items-center justify-center rounded-md text-[10px] font-semibold">
                    {accountInitial}
                  </span>
                  <span dir="ltr" className="truncate">
                    {accountLabel}
                  </span>
                  <ChevronDown className="size-3.5 shrink-0" />
                </button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="center" className="w-72">
                <div className="flex items-center gap-2 px-2 py-1.5">
                  <span className="bg-muted text-muted-foreground flex size-7 shrink-0 items-center justify-center rounded-md text-xs font-semibold">
                    {accountInitial}
                  </span>
                  <span
                    dir="ltr"
                    className="min-w-0 flex-1 truncate text-start text-[13px]"
                  >
                    {accountLabel}
                  </span>
                  <Check className="text-muted-foreground size-4 shrink-0" />
                </div>
                <DropdownMenuItem
                  disabled={isApproving || isSwitchingAccount}
                  onSelect={handleSwitchAccount}
                >
                  <MenuItemContent icon={ArrowLeftRight}>
                    {t('Use a different account')}
                  </MenuItemContent>
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        ) : null}
        <div
          className={
            accountLabel
              ? 'flex justify-center'
              : 'mt-10 flex justify-center md:mt-16'
          }
        >
          <AppwriteLogo className="h-6 w-auto" />
        </div>
      </div>
    </div>
  )
}

