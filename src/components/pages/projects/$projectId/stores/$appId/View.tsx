import { useParams, useNavigate } from '@tanstack/react-router'
import { ArrowLeft, Package } from 'lucide-react'
import { ServiceHeader } from '../../shared/ServiceHeader'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  useDistributionApp,
  useDistributionBuilds,
  useDistributionSubmissions,
} from '@/lib/react-query/hooks'
import type { DistributionApp } from '@/lib/react-query/hooks'
import {
  BuildStatusBadge,
  SubmissionStatusBadge,
} from '../_components/status-badges'
import {
  PlatformIcon,
  PlatformIcons,
  frameworkLabel,
  platformLabel,
  providerLabel,
} from '../_components/platform'
import { useT } from '@/lib/i18n/translate'

type AppDetailInitialData = {
  app: DistributionApp
}

interface ViewProps {
  initialData?: AppDetailInitialData
}

function formatDuration(seconds: number) {
  if (!seconds) return '-'
  if (seconds < 60) return `${seconds}s`
  const minutes = Math.floor(seconds / 60)
  const rest = seconds % 60
  return rest ? `${minutes}m ${rest}s` : `${minutes}m`
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const { projectId, appId } = useParams({ strict: false })
  const navigate = useNavigate()

  const { data: appFromHook, isLoading: appLoading } = useDistributionApp(
    projectId,
    appId,
  )
  const app = appFromHook ?? initialData?.app

  const { builds, isLoading: buildsLoading } = useDistributionBuilds(
    projectId,
    appId,
  )
  const { submissions, isLoading: submissionsLoading } =
    useDistributionSubmissions(projectId, appId)

  const handleBack = () =>
    navigate({
      to: '/projects/$projectId/stores',
      params: { projectId: projectId! },
    })

  if (!app && !appLoading) {
    return (
      <div className="flex flex-col">
        <ServiceHeader title={t('Distribution')} fullWidthBorder />
        <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
          <EmptyState
            icon={Package}
            title={t('App not found')}
            description={t(
              'This distribution app does not exist or has been removed.',
            )}
            variant="card"
            action={
              <Button variant="outline" size="sm" onClick={handleBack}>
                {t('Back to Distribution')}
              </Button>
            }
          />
        </div>
      </div>
    )
  }

  return (
    <div className="flex h-full min-h-0 flex-1 flex-col">
      <ServiceHeader
        title={
          <span className="flex min-w-0 items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              onClick={handleBack}
              aria-label={t('Back to Distribution')}
              className="h-8 w-8 shrink-0 p-0 text-muted-foreground hover:text-foreground"
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <span className="truncate">{app?.name ?? t('App')}</span>
            {app ? (
              <PlatformIcons platforms={app.platforms} className="shrink-0" />
            ) : null}
            {app && !app.enabled ? (
              <Badge variant="inactive" className="text-[10px] shrink-0">
                {t('Disabled')}
              </Badge>
            ) : null}
          </span>
        }
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 overflow-y-auto px-4 pb-4 pt-6 sm:px-6 sm:pb-6">
        {app ? (
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Configuration')}
              </h3>
            </div>
            <div className="border-t border-border" />
            <div className="grid gap-4 px-6 py-4 sm:grid-cols-2 lg:grid-cols-3">
              <Detail label={t('Framework')}>
                {frameworkLabel(app.framework)}
              </Detail>
              <Detail label={t('Platforms')}>
                <span className="flex items-center gap-2">
                  {app.platforms.map((platform) => (
                    <span
                      key={platform}
                      className="flex items-center gap-1.5 text-[13px] text-foreground"
                    >
                      <PlatformIcon platform={platform} />
                      {platformLabel(platform)}
                    </span>
                  ))}
                </span>
              </Detail>
              <Detail label={t('Default track')}>
                {app.defaultTrack || '-'}
              </Detail>
              <Detail label={t('Identifier')}>
                {app.applicationId ||
                  app.bundleId ||
                  app.packageIdentity ||
                  '-'}
              </Detail>
              <Detail label={t('Auto submit')}>
                {app.autoSubmit ? t('Enabled') : t('Disabled')}
              </Detail>
            </div>
          </div>
        ) : null}

        <section className="mt-6">
          <h2 className="mb-3 text-[15px] font-semibold text-foreground">
            {t('Builds')}
          </h2>
          {buildsLoading && builds.length === 0 ? (
            <div className="rounded-lg border border-border bg-card py-12 text-center">
              <p className="text-[13px] text-muted-foreground">
                {t('Loading builds...')}
              </p>
            </div>
          ) : builds.length === 0 ? (
            <EmptyState
              icon={Package}
              title={t('No builds yet')}
              description={t(
                'Trigger a build to create an artifact for the stores.',
              )}
              variant="card"
            />
          ) : (
            <div className="rounded-lg border border-border bg-card overflow-hidden">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Platform')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Version')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Status')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Duration')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                      {t('Created')}
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {builds.map((build) => (
                    <TableRow
                      key={build.$id}
                      className="border-b border-border/50"
                    >
                      <TableCell className="px-4 py-3">
                        <span className="flex items-center gap-2 text-[13px] text-foreground">
                          <PlatformIcon platform={build.platform} />
                          {platformLabel(build.platform)}
                          <span className="text-[11px] uppercase text-muted-foreground">
                            {build.artifactType}
                          </span>
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <span className="text-[13px] text-foreground">
                          {build.versionName}
                        </span>
                        <span className="ms-1 text-[12px] text-muted-foreground">
                          ({build.versionCode})
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <BuildStatusBadge status={build.status} />
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <span className="text-[12px] text-muted-foreground font-mono">
                          {formatDuration(build.buildDuration)}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        <DateTooltip
                          date={build.$createdAt}
                          className="text-[12px] text-muted-foreground font-mono"
                        />
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </section>

        <section className="mt-6">
          <h2 className="mb-3 text-[15px] font-semibold text-foreground">
            {t('Submissions')}
          </h2>
          {submissionsLoading && submissions.length === 0 ? (
            <div className="rounded-lg border border-border bg-card py-12 text-center">
              <p className="text-[13px] text-muted-foreground">
                {t('Loading submissions...')}
              </p>
            </div>
          ) : submissions.length === 0 ? (
            <EmptyState
              icon={Package}
              title={t('No submissions yet')}
              description={t(
                'Submit a ready build to a store to track its review status here.',
              )}
              variant="card"
            />
          ) : (
            <div className="rounded-lg border border-border bg-card overflow-hidden">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Provider')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Track')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Status')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Release')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                      {t('Submitted')}
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {submissions.map((submission) => (
                    <TableRow
                      key={submission.$id}
                      className="border-b border-border/50"
                    >
                      <TableCell className="px-4 py-3">
                        <span className="text-[13px] text-foreground">
                          {providerLabel(submission.provider)}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <span className="text-[13px] text-muted-foreground">
                          {submission.track}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <SubmissionStatusBadge status={submission.status} />
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <span className="text-[12px] text-muted-foreground font-mono">
                          {submission.storeReleaseId || '-'}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        <DateTooltip
                          date={submission.$createdAt}
                          className="text-[12px] text-muted-foreground font-mono"
                        />
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </section>
      </div>
    </div>
  )
}

function Detail({
  label,
  children,
}: {
  label: string
  children: React.ReactNode
}) {
  return (
    <div className="min-w-0">
      <p className="text-[12px] font-medium uppercase tracking-wider text-muted-foreground">
        {label}
      </p>
      <div className="mt-1 text-[13px] text-foreground">{children}</div>
    </div>
  )
}
