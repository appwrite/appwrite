import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Query, type Models } from '@appwrite.io/console'
import {
  getBackupPolicyLossRows,
  getDedicatedDatabaseLossRows,
  targetPlanDisablesBackups,
  targetPlanSpinsDownDedicatedDatabases,
  type DowngradeBackupPolicyProject,
  type DowngradeDedicatedDatabaseProject,
  type DowngradePlanLossRow,
} from '@/lib/billing/downgrade-plan-losses'
import { sdk } from '@/lib/appwrite/sdk'
import {
  fetchProjectDatabasesByIds,
  fetchProjectDedicatedDatabases,
} from '@/lib/react-query/hooks/databases'
import { formatProjectNameForDisplay } from '@/lib/react-query/hooks/projects'
import { useT } from '@/lib/i18n/translate'

const BACKUP_POLICY_LIST_LIMIT = 100

type LossProject = { id: string; name: string }

async function fetchBackupPolicyProjects(
  projects: LossProject[],
): Promise<DowngradeBackupPolicyProject[]> {
  return Promise.all(
    projects.map(async (project) => {
      const { policies } = await sdk
        .forProject(project.id)
        .backups.listPolicies({
          queries: [Query.limit(BACKUP_POLICY_LIST_LIMIT)],
        })

      const databaseIds = [
        ...new Set(
          (policies ?? [])
            .map((policy) => policy.resourceId)
            .filter((id): id is string => !!id),
        ),
      ]
      // Names are cosmetic here; the rows fall back to the database id.
      const named = await fetchProjectDatabasesByIds(
        project.id,
        databaseIds,
      ).catch(() => ({ databases: [] as Models.Database[] }))

      return {
        projectId: project.id,
        projectName: project.name,
        policies: policies ?? [],
        databaseNames: Object.fromEntries(
          named.databases.map((database) => [database.$id, database.name]),
        ),
      }
    }),
  )
}

async function fetchDedicatedDatabaseProjects(
  projects: LossProject[],
): Promise<DowngradeDedicatedDatabaseProject[]> {
  return Promise.all(
    projects.map(async (project) => ({
      projectId: project.id,
      projectName: project.name,
      databases: (await fetchProjectDedicatedDatabases(project.id)).databases,
    })),
  )
}

function LossSection({
  title,
  note,
  rows,
}: {
  title: string
  note: string
  rows: DowngradePlanLossRow[]
}) {
  return (
    <div className="space-y-2">
      <p className="text-[13px] font-medium text-foreground">{title}</p>

      <ul className="space-y-1.5">
        {rows.map((row) => (
          <li
            key={row.projectId}
            className="flex items-start justify-between gap-3 text-[13px] leading-normal"
          >
            <span className="text-foreground">{row.projectName}</span>
            {row.databases.length > 0 ? (
              <span className="shrink-0 text-muted-foreground">
                {row.databases.map((database) => database.name).join(', ')}
              </span>
            ) : null}
          </li>
        ))}
      </ul>

      <p className="text-[13px] text-muted-foreground">{note}</p>
    </div>
  )
}

interface DowngradePlanLossWarningProps {
  organizationId: string
  /** Projects that survive the downgrade. */
  projects: Models.Project[]
  targetPlan: Models.BillingPlan | null | undefined
}

export function DowngradePlanLossWarning({
  organizationId,
  projects,
  targetPlan,
}: DowngradePlanLossWarningProps) {
  const t = useT()

  const lossProjects = useMemo<LossProject[]>(
    () =>
      projects.map((project) => ({
        id: project.$id,
        name: formatProjectNameForDisplay(project.name),
      })),
    [projects],
  )
  const projectKey = useMemo(
    () =>
      lossProjects
        .map((project) => project.id)
        .sort()
        .join(','),
    [lossProjects],
  )

  const backupsApply =
    targetPlanDisablesBackups(targetPlan) && lossProjects.length > 0
  const dedicatedApply =
    targetPlanSpinsDownDedicatedDatabases(targetPlan) && lossProjects.length > 0

  const backupsQuery = useQuery({
    queryKey: ['downgrade-plan-losses', 'backups', organizationId, projectKey],
    queryFn: () => fetchBackupPolicyProjects(lossProjects),
    enabled: backupsApply,
    staleTime: 30_000,
  })

  const dedicatedQuery = useQuery({
    queryKey: [
      'downgrade-plan-losses',
      'dedicated-databases',
      organizationId,
      projectKey,
    ],
    queryFn: () => fetchDedicatedDatabaseProjects(lossProjects),
    enabled: dedicatedApply,
    staleTime: 30_000,
  })

  const backupRows = useMemo(
    () => getBackupPolicyLossRows(backupsQuery.data, targetPlan),
    [backupsQuery.data, targetPlan],
  )
  const dedicatedRows = useMemo(
    () => getDedicatedDatabaseLossRows(dedicatedQuery.data, targetPlan),
    [dedicatedQuery.data, targetPlan],
  )

  const backupsFailed = backupsApply && !!backupsQuery.error
  const dedicatedFailed = dedicatedApply && !!dedicatedQuery.error

  if (backupsQuery.isLoading || dedicatedQuery.isLoading) return null
  if (
    backupRows.length === 0 &&
    dedicatedRows.length === 0 &&
    !backupsFailed &&
    !dedicatedFailed
  ) {
    return null
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('What changes in the projects you are keeping')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'These changes happen on their own when your plan changes. There is nothing to select or clean up first.',
          )}
        </p>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4 space-y-4">
        {backupsFailed ? (
          <p className="text-[13px] text-muted-foreground">
            {t(
              'Could not load which backup policies stop running when your plan changes.',
            )}
          </p>
        ) : backupRows.length > 0 ? (
          <LossSection
            title={t('Backups stop running')}
            note={t(
              'At the end of your current billing cycle these backup policies are turned off and stop creating backups. The policies are not deleted, but the selected plan has no Backups screen, so you will not be able to see or manage them.',
            )}
            rows={backupRows}
          />
        ) : null}

        {dedicatedFailed ? (
          <p className="text-[13px] text-muted-foreground">
            {t(
              'Could not load which dedicated databases are spun down when your plan changes.',
            )}
          </p>
        ) : dedicatedRows.length > 0 ? (
          <LossSection
            title={t('Dedicated databases are spun down')}
            // Spinning down is one-directional server-side: there is no resume.
            note={t(
              'The selected plan does not include dedicated databases, so these are spun down when your plan changes. Your data is preserved, but the database stops serving requests and cannot be started again, even if you move back to a plan that includes them.',
            )}
            rows={dedicatedRows}
          />
        ) : null}
      </div>
    </div>
  )
}
