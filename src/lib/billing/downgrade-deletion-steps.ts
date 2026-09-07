import type { PendingDowngradeDeletions } from '@/components/pages/organizations/$orgId/billing/change-plan/DowngradeValidation'
import {
  DOWNGRADE_RESOURCE_TYPES,
  type DowngradeResourceType,
} from '@/lib/billing/downgrade-plan-limits'

export type DowngradeDeletionStep = {
  /** Stable key for React and for progress updates. */
  id: string
  /** English source string, translated at render. */
  label: string
  count: number
}

function countResource(
  pending: PendingDowngradeDeletions,
  type: DowngradeResourceType,
): number {
  return Object.values(pending.resources).reduce(
    (total, entry) => total + (entry[type]?.length ?? 0),
    0,
  )
}

/**
 * The single ordered list the confirmation dialog, the deletion runner and the
 * progress screen all read, so the three can never disagree.
 */
export function buildDowngradeDeletionSteps(
  pending: PendingDowngradeDeletions | undefined,
): DowngradeDeletionStep[] {
  if (!pending) return []

  const steps: DowngradeDeletionStep[] = [
    { id: 'projects', label: 'Projects', count: pending.projects.length },
    { id: 'members', label: 'Members', count: pending.memberships.length },
    { id: 'domains', label: 'Domains', count: pending.domains.length },
    ...DOWNGRADE_RESOURCE_TYPES.map(({ id, label }) => ({
      id,
      label,
      count: countResource(pending, id),
    })),
  ]

  return steps.filter(({ count }) => count > 0)
}
