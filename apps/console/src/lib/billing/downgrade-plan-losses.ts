import type { Models } from '@appwrite.io/console'

/** The target-plan fields these two automatic consequences hang off. */
export type DowngradeLossTargetPlan = {
  backupsEnabled?: boolean
  supportsDedicatedDatabases?: boolean
}

export type DowngradePlanLossDatabase = { id: string; name: string }

export type DowngradePlanLossRow = {
  projectId: string
  projectName: string
  databases: DowngradePlanLossDatabase[]
}

export type DowngradeBackupPolicyProject = {
  projectId: string
  projectName: string
  policies: Models.BackupPolicy[]
  /** Database id → name, for the databases the policies point at. */
  databaseNames?: Record<string, string>
}

export type DowngradeDedicatedDatabaseProject = {
  projectId: string
  projectName: string
  databases: Models.DedicatedDatabase[]
}

/**
 * Only the boolean says anything: `backupPolicies` is `-1` when backups are off,
 * and a lower positive cap is not enforced against existing policies anyway.
 */
export function targetPlanDisablesBackups(
  targetPlan: DowngradeLossTargetPlan | null | undefined,
): boolean {
  return targetPlan?.backupsEnabled === false
}

export function targetPlanSpinsDownDedicatedDatabases(
  targetPlan: DowngradeLossTargetPlan | null | undefined,
): boolean {
  if (!targetPlan) return false
  return targetPlan.supportsDedicatedDatabases !== true
}

/** Kept projects whose backup policies the plan change stops, by database. */
export function getBackupPolicyLossRows(
  projects: DowngradeBackupPolicyProject[] | null | undefined,
  targetPlan: DowngradeLossTargetPlan | null | undefined,
): DowngradePlanLossRow[] {
  if (!projects || !targetPlanDisablesBackups(targetPlan)) return []

  const rows: DowngradePlanLossRow[] = []

  for (const project of projects) {
    if (project.policies.length === 0) continue

    const databases: DowngradePlanLossDatabase[] = []
    const seen = new Set<string>()
    for (const policy of project.policies) {
      const id = policy.resourceId
      if (!id || seen.has(id)) continue
      seen.add(id)
      databases.push({ id, name: project.databaseNames?.[id] ?? id })
    }

    rows.push({
      projectId: project.projectId,
      projectName: project.projectName,
      databases,
    })
  }

  return rows
}

/** Kept projects whose dedicated databases the plan change spins down. */
export function getDedicatedDatabaseLossRows(
  projects: DowngradeDedicatedDatabaseProject[] | null | undefined,
  targetPlan: DowngradeLossTargetPlan | null | undefined,
): DowngradePlanLossRow[] {
  if (!projects || !targetPlanSpinsDownDedicatedDatabases(targetPlan)) return []

  const rows: DowngradePlanLossRow[] = []

  for (const project of projects) {
    if (project.databases.length === 0) continue
    rows.push({
      projectId: project.projectId,
      projectName: project.projectName,
      databases: project.databases.map((database) => ({
        id: database.$id,
        name: database.name || database.$id,
      })),
    })
  }

  return rows
}
