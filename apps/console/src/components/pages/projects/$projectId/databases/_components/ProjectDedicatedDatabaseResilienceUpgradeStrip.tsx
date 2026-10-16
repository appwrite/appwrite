import { Link } from '@tanstack/react-router'
import { AlertCircle, X } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { isDedicatedDatabaseReady } from '@/lib/databases/dedicated-database-status'
import { dedicatedDatabaseHomeLink } from '@/lib/database-routes'
import type { DatabaseRouteKind } from '@/lib/database-routes'
import { useT } from '@/lib/i18n/translate'
import {
  useDismissConsoleBanner,
  useProjectDedicatedDatabases,
} from '@/lib/react-query/hooks'
import { isConsoleBannerDismissed, type UserPrefs } from '@/lib/user-prefs-keys'
import { useMemo } from 'react'

export function projectDedicatedDatabaseResilienceBannerId(projectId: string) {
  return `dedicated-db-project-resilience-${projectId}`
}

type ProjectDedicatedDatabaseResilienceUpgradeStripProps = {
  projectId: string
  productRouteKindByDedicatedId: Map<string, DatabaseRouteKind>
}

export function ProjectDedicatedDatabaseResilienceUpgradeStrip({
  projectId,
  productRouteKindByDedicatedId,
}: ProjectDedicatedDatabaseResilienceUpgradeStripProps) {
  const t = useT()
  const { account } = useAuth()
  const dismissBanner = useDismissConsoleBanner()
  const bannerId = projectDedicatedDatabaseResilienceBannerId(projectId)
  const dismissed = isConsoleBannerDismissed(
    (account as { prefs?: UserPrefs } | null | undefined)?.prefs,
    bannerId,
  )
  const { databases } = useProjectDedicatedDatabases(projectId)

  const { noReplicaDatabases, noPitrDatabases, firstTargetLink } = useMemo(() => {
    const ready = databases.filter((db) => isDedicatedDatabaseReady(db.status))
    const noReplicaDatabases = ready.filter((db) => (db.replicas ?? 0) === 0)
    const noPitrDatabases = ready.filter((db) => db.pitr !== true)
    const first =
      noReplicaDatabases[0] ??
      noPitrDatabases[0] ??
      null
    const firstTargetLink = first
      ? dedicatedDatabaseHomeLink(
          projectId,
          first,
          productRouteKindByDedicatedId.get(first.$id),
        )
      : null
    return { noReplicaDatabases, noPitrDatabases, firstTargetLink }
  }, [databases, productRouteKindByDedicatedId, projectId])

  const show =
    !dismissed &&
    (noReplicaDatabases.length > 0 || noPitrDatabases.length > 0)

  if (!show) return null

  const summaryParts: string[] = []
  if (noReplicaDatabases.length > 0) {
    summaryParts.push(
      `${noReplicaDatabases.length} ${
        noReplicaDatabases.length === 1
          ? t('dedicated database has no read replicas')
          : t('dedicated databases have no read replicas')
      }`,
    )
  }
  if (noPitrDatabases.length > 0) {
    summaryParts.push(
      `${noPitrDatabases.length} ${
        noPitrDatabases.length === 1
          ? t('dedicated database does not have point-in-time recovery enabled')
          : t('dedicated databases do not have point-in-time recovery enabled')
      }`,
    )
  }

  return (
    <div className="border-b border-border bg-amber-500/5">
      <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
        <Alert variant="default" className="border-amber-500/30 bg-transparent">
          <AlertCircle className="h-4 w-4 text-amber-500" />
          <div className="flex flex-1 items-start justify-between gap-4">
            <div className="min-w-0 flex-1">
              <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                {t('Strengthen dedicated database resilience')}
              </AlertTitle>
              <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                <span className="inline">
                  {summaryParts.join('. ')}.{' '}
                  {firstTargetLink ? (
                    <Link
                      to={firstTargetLink.to}
                      params={firstTargetLink.params}
                      className="font-medium underline hover:no-underline"
                    >
                      {t('Review settings')}
                    </Link>
                  ) : null}{' '}
                  {t(
                    'to add replicas, enable PITR, or create backup policies from the banner on each database.',
                  )}
                </span>
              </AlertDescription>
            </div>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="size-8 shrink-0 text-amber-600 hover:bg-amber-500/10 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300"
              onClick={() => dismissBanner.mutate(bannerId)}
              disabled={dismissBanner.isPending}
              aria-label={t('Dismiss banner')}
            >
              <X className="size-4" />
            </Button>
          </div>
        </Alert>
      </div>
    </div>
  )
}
