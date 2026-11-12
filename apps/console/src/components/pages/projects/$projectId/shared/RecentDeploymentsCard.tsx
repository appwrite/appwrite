import { forwardRef, type ComponentPropsWithoutRef } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { CheckCircle2, GitBranch, GitCommit } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { formatDecimalBytes } from '@/lib/utils/byte-display-unit'
import {
  getDeploymentStatusBadge,
  isDeploymentInProgress,
  isDeploymentTimeout,
} from '@/lib/utils/deployment-status'
import {
  getDeploymentRepositoryWebUrl,
  getVcsProviderKind,
} from '@/lib/utils/deployment-repository-url'
import { getVcsProvider } from '@/lib/vcs/providers'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export const OVERVIEW_DEPLOYMENTS_LIMIT = 5

function formatDuration(seconds: number): string {
  if (seconds < 60) return `${seconds}s`
  const minutes = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${minutes}m ${secs}s`
}

function deploymentDuration(deployment: Models.Deployment) {
  if (
    isDeploymentInProgress(deployment.status) &&
    !isDeploymentTimeout(deployment.status, deployment.$createdAt)
  ) {
    return formatDuration(
      Math.max(
        0,
        Math.floor(
          (Date.now() - new Date(deployment.$createdAt).getTime()) / 1000,
        ),
      ),
    )
  }
  if (
    deployment.buildDuration &&
    !isDeploymentTimeout(deployment.status, deployment.$createdAt)
  ) {
    return formatDuration(deployment.buildDuration)
  }
  return '—'
}

type RecentDeploymentsCardProps = {
  kind: 'site' | 'function'
  projectId: string
  resourceId: string
  deployments: Models.Deployment[]
  total: number
  activeDeploymentId?: string
}

const DeploymentsLink = forwardRef<
  HTMLAnchorElement,
  {
    kind: 'site' | 'function'
    projectId: string
    resourceId: string
  } & ComponentPropsWithoutRef<'a'>
>(function DeploymentsLink(
  { kind, projectId, resourceId, children, ...props },
  ref,
) {
  if (kind === 'site') {
    return (
      <Link
        ref={ref}
        to="/projects/$projectId/sites/$siteId/deployments"
        params={{ projectId, siteId: resourceId }}
        {...props}
      >
        {children}
      </Link>
    )
  }
  return (
    <Link
      ref={ref}
      to="/projects/$projectId/functions/$functionId/deployments"
      params={{ projectId, functionId: resourceId }}
      {...props}
    >
      {children}
    </Link>
  )
})

function DeploymentSource({
  deployment,
  translate,
}: {
  deployment: Models.Deployment
  translate: (value: string) => string
}) {
  const providerKind = getVcsProviderKind(deployment)
  const owner = deployment.providerRepositoryOwner?.trim()
  const name = deployment.providerRepositoryName?.trim()
  const hasRepository = Boolean(owner && name)

  if (providerKind || hasRepository) {
    const { label: providerName, Icon } = providerKind
      ? getVcsProvider(providerKind)
      : { label: 'Git', Icon: GitBranch }

    if (hasRepository) {
      const repoUrl = getDeploymentRepositoryWebUrl(deployment)
      const label = `${owner}/${name}`
      return (
        <Badge
          variant="outline"
          className="h-6 max-w-full gap-1.5 px-2.5 text-[11px]"
        >
          <Icon className="h-4 w-4 shrink-0" />
          {repoUrl ? (
            <a
              href={repoUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="truncate no-underline hover:text-foreground"
              onClick={(event) => event.stopPropagation()}
            >
              {label}
            </a>
          ) : (
            <span className="truncate">{label}</span>
          )}
        </Badge>
      )
    }

    return (
      <div className="flex items-center gap-1.5 text-[12px] text-foreground">
        <Icon className="h-4 w-4 shrink-0" />
        <span>{providerName}</span>
      </div>
    )
  }

  const typeLabel =
    deployment.type === 'cli'
      ? 'CLI'
      : deployment.type === 'manual'
        ? translate('Manual')
        : translate('Deployment')

  return (
    <div className="flex items-center gap-1.5 text-[12px] text-foreground">
      {deployment.type === 'cli' ? (
        <GitBranch className="h-3.5 w-3.5 shrink-0" />
      ) : null}
      <span>{typeLabel}</span>
    </div>
  )
}

function DeploymentCommitter({
  deployment,
}: {
  deployment: Models.Deployment
}) {
  const author = deployment.providerCommitAuthor?.trim()
  const authorUrl = deployment.providerCommitAuthorUrl?.trim()
  const branch = deployment.providerBranch?.trim()
  const hash = deployment.providerCommitHash?.trim().slice(0, 7)

  if (!author && !branch && !hash) {
    return <span className="text-[12px] text-muted-foreground">—</span>
  }

  return (
    <div className="min-w-0 space-y-1">
      {author ? (
        authorUrl ? (
          <a
            href={authorUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="link-neutral block truncate text-[12px] text-foreground"
            onClick={(event) => event.stopPropagation()}
            title={author}
          >
            {author}
          </a>
        ) : (
          <p className="truncate text-[12px] text-foreground">{author}</p>
        )
      ) : null}
      {branch || hash ? (
        <div className="flex min-w-0 items-center gap-1.5 text-[11px] text-muted-foreground">
          {branch ? (
            <span className="inline-flex min-w-0 items-center gap-1">
              <GitBranch className="h-3 w-3 shrink-0" />
              <span className="truncate font-mono">{branch}</span>
            </span>
          ) : null}
          {branch && hash ? <span aria-hidden>•</span> : null}
          {hash ? (
            <span className="inline-flex items-center gap-1">
              <GitCommit className="h-3 w-3 shrink-0" />
              <span className="font-mono">{hash}</span>
            </span>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}

export function RecentDeploymentsCard({
  kind,
  projectId,
  resourceId,
  deployments,
  total,
  activeDeploymentId,
}: RecentDeploymentsCardProps) {
  const t = useT()
  const navigate = useNavigate()
  const visible = deployments.slice(0, OVERVIEW_DEPLOYMENTS_LIMIT)

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="flex h-16 items-center justify-between gap-3 px-6">
        <div className="min-w-0">
          <h3 className="text-[15px] font-semibold leading-5 text-foreground">
            {t('Recent deployments')}
          </h3>
          <p className="h-4 truncate text-[12px] leading-4 text-muted-foreground">
            {total > 0
              ? `${total.toLocaleString()} ${t('deployments')}`
              : t('No deployments yet')}
          </p>
        </div>
        <Button size="sm" variant="outline" className="h-9 text-[13px]" asChild>
          <DeploymentsLink
            kind={kind}
            projectId={projectId}
            resourceId={resourceId}
          >
            {t('View all')}
          </DeploymentsLink>
        </Button>
      </div>
      <div className="border-t border-border" />
      {visible.length > 0 ? (
        <Table withScrollContainer={false}>
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              <TableHead className="px-6 py-2.5 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Status')}
              </TableHead>
              <TableHead className="px-4 py-2.5 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Source')}
              </TableHead>
              <TableHead className="px-4 py-2.5 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Committer')}
              </TableHead>
              <TableHead className="px-4 py-2.5 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Size')}
              </TableHead>
              <TableHead className="px-4 py-2.5 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Duration')}
              </TableHead>
              <TableHead className="px-6 py-2.5 text-end text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Deployed')}
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {visible.map((deployment) => {
              const statusBadge = getDeploymentStatusBadge(
                deployment.status || 'unknown',
                deployment.$createdAt,
              )
              const isActive = deployment.$id === activeDeploymentId
              const StatusIcon = statusBadge.icon

              return (
                <TableRow
                  key={deployment.$id}
                  className={cn(
                    'cursor-pointer border-border',
                    isActive && 'bg-muted/40 dark:bg-muted/35',
                  )}
                  onClick={() => {
                    if (kind === 'site') {
                      void navigate({
                        to: '/projects/$projectId/sites/$siteId/deployments/$deploymentId',
                        params: {
                          projectId,
                          siteId: resourceId,
                          deploymentId: deployment.$id,
                        },
                      })
                      return
                    }
                    void navigate({
                      to: '/projects/$projectId/functions/$functionId/deployments/$deploymentId',
                      params: {
                        projectId,
                        functionId: resourceId,
                        deploymentId: deployment.$id,
                      },
                    })
                  }}
                >
                  <TableCell className="px-6 py-3">
                    {isActive ? (
                      <Badge
                        variant="active"
                        className="gap-1.5 text-[11px] font-medium"
                      >
                        <CheckCircle2 className="h-3 w-3" />
                        {t('Active')}
                      </Badge>
                    ) : (
                      <Badge
                        variant={statusBadge.badgeVariant}
                        className="gap-1.5 text-[11px] font-medium"
                      >
                        <StatusIcon className="h-3 w-3" />
                        {t(statusBadge.label)}
                      </Badge>
                    )}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <DeploymentSource deployment={deployment} translate={t} />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <DeploymentCommitter deployment={deployment} />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <code className="text-[12px] font-mono text-muted-foreground">
                      {formatDecimalBytes(
                        (deployment.buildSize || 0) +
                          (deployment.sourceSize || 0),
                      )}
                    </code>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <code className="text-[12px] font-mono text-muted-foreground">
                      {deploymentDuration(deployment)}
                    </code>
                  </TableCell>
                  <TableCell className="px-6 py-3 text-end">
                    <DateTooltip
                      date={deployment.$createdAt}
                      className="text-[12px] text-muted-foreground"
                    />
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      ) : (
        <div className="px-6 py-10">
          <EmptyState
            title={t('No deployments yet')}
            description={t('Deployments will appear here when available')}
            isEmpty
          />
        </div>
      )}
    </div>
  )
}
