import { GitBranch, GitCommit } from 'lucide-react'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { getDeploymentStatusBadge } from '@/lib/utils/deployment-status'
import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'

interface DeploymentInfoProps {
  deployment: Models.Deployment
  showStatus?: boolean
  compact?: boolean
}

export function DeploymentInfo({
  deployment,
  showStatus = false,
  compact = false,
}: DeploymentInfoProps) {
  const t = useT()
  const statusBadge = showStatus
    ? getDeploymentStatusBadge(deployment.status, deployment.$createdAt)
    : null

  if (compact) {
    return (
      <div className="rounded-lg border border-border bg-muted/30 p-3 space-y-2">
        <div className="flex items-center gap-2 flex-wrap">
          <CopyableId id={deployment.$id} size="xs" />
          {deployment.providerCommitHash && (
            <span className="text-[11px] text-muted-foreground font-mono">
              {deployment.providerCommitHash.slice(0, 7)}
            </span>
          )}
          {statusBadge && (
            <Badge
              variant={statusBadge.badgeVariant}
              className="gap-1.5 text-[11px] font-medium h-5 px-2"
            >
              {(() => {
                const StatusIcon = statusBadge.icon
                return <StatusIcon className="h-3 w-3" />
              })()}
              {t(statusBadge.label)}
            </Badge>
          )}
        </div>
        {(deployment.providerBranch ||
          deployment.providerCommitAuthor ||
          deployment.providerCommitMessage ||
          deployment.$createdAt) && (
          <div className="flex flex-col gap-1.5 text-[11px] text-muted-foreground">
            {deployment.providerBranch && (
              <div className="flex items-center gap-1.5">
                <GitBranch className="h-3 w-3 shrink-0" />
                <span className="truncate">{deployment.providerBranch}</span>
              </div>
            )}
            {deployment.providerCommitAuthor && (
              <div className="flex items-center gap-1.5 min-w-0">
                <span className="shrink-0">{t('Committer')}</span>
                <span className="truncate font-medium text-foreground">
                  {deployment.providerCommitAuthor}
                </span>
              </div>
            )}
            {deployment.providerCommitMessage && (
              <div className="flex items-start gap-1.5">
                <GitCommit className="h-3 w-3 shrink-0 mt-0.5" />
                <span className="line-clamp-2">
                  {deployment.providerCommitMessage}
                </span>
              </div>
            )}
            {deployment.$createdAt && (
              <div className="flex items-center gap-1.5">
                <span>{t('Deployed')}</span>
                <DateTooltip date={deployment.$createdAt} />
              </div>
            )}
          </div>
        )}
      </div>
    )
  }

  return (
    <div className="rounded-lg border border-border bg-muted/30 p-4 space-y-3">
      <div className="flex items-center gap-2 flex-wrap">
        <CopyableId id={deployment.$id} size="xs" />
        {deployment.providerCommitHash && (
          <span className="text-[11px] text-muted-foreground font-mono">
            {deployment.providerCommitHash.slice(0, 7)}
          </span>
        )}
        {statusBadge && (
          <Badge
            variant={statusBadge.badgeVariant}
            className="gap-1.5 text-[11px] font-medium h-5 px-2"
          >
            {(() => {
              const StatusIcon = statusBadge.icon
              return <StatusIcon className="h-3 w-3" />
            })()}
            {t(statusBadge.label)}
          </Badge>
        )}
      </div>

      {(deployment.providerBranch ||
        deployment.providerCommitAuthor ||
        deployment.providerCommitMessage ||
        deployment.$createdAt) && (
        <div className="space-y-2 text-[12px]">
          {deployment.providerBranch && (
            <div className="flex items-center gap-2">
              <GitBranch className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
              <span className="text-muted-foreground">{t('Branch:')}</span>
              <span className="font-medium text-foreground">
                {deployment.providerBranch}
              </span>
            </div>
          )}
          {deployment.providerCommitAuthor && (
            <div className="flex items-center gap-2 min-w-0">
              <span className="text-muted-foreground shrink-0">
                {t('Committer:')}
              </span>
              <span className="font-medium text-foreground truncate">
                {deployment.providerCommitAuthor}
              </span>
            </div>
          )}
          {deployment.providerCommitMessage && (
            <div className="flex items-start gap-2">
              <GitCommit className="h-3.5 w-3.5 text-muted-foreground shrink-0 mt-0.5" />
              <div className="flex-1 min-w-0">
                <span className="text-muted-foreground">{t('Commit:')}</span>
                <span className="ms-1.5 text-foreground whitespace-pre-wrap break-words">
                  {deployment.providerCommitMessage}
                </span>
              </div>
            </div>
          )}
          {deployment.$createdAt && (
            <div className="flex items-center gap-2">
              <span className="text-muted-foreground">{t('Deployed:')}</span>
              <DateTooltip date={deployment.$createdAt} />
            </div>
          )}
        </div>
      )}
    </div>
  )
}
