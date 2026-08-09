/**
 * The plan change estimation endpoint reports only the resources a project
 * *exceeds*, and it reports resource types the console has no selection UI for
 * (`collections`) alongside ones it does. Getting that split wrong either hides
 * a blocker from the user or blocks a downgrade the selection UI could resolve,
 * so pin the reading of the payload here.
 */

import { describe, expect, test } from 'bun:test'
import {
  getComplianceErrors,
  getNonCompliantProjectIds,
  getServerResourceLimits,
  getUnresolvableResources,
  type PlanChangeLimits,
} from '@/lib/billing/plan-change-compliance'

function resource(type: string, currentUsage: number, limit: number) {
  return {
    type,
    currentUsage,
    limit,
    status: 'over_limit',
    excess: currentUsage - limit,
    resolutionHint: `Delete or migrate ${currentUsage - limit} ${type}.`,
  }
}

const limits: PlanChangeLimits = {
  totalProjects: 3,
  nonCompliantProjects: 2,
  canChangePlan: false,
  unsupportedAddons: [],
  projects: [
    { $id: 'compliant', name: 'Compliant', isCompliant: true, resources: [] },
    {
      $id: 'over',
      name: 'Over limit',
      isCompliant: false,
      resources: [resource('databases', 3, 1), resource('collections', 40, 10)],
    },
    {
      $id: 'unreachable',
      name: 'Unreachable',
      isCompliant: false,
      resources: [],
      error: 'Unknown region: xyz',
    },
  ],
}

describe('getServerResourceLimits', () => {
  test('returns the limit only for flagged, selectable resource types', () => {
    // `collections` has no selection UI, so it must not become a selection limit.
    expect(getServerResourceLimits(limits, 'over')).toEqual({ databases: 1 })
  })

  test('returns nothing for a compliant project', () => {
    expect(getServerResourceLimits(limits, 'compliant')).toEqual({})
  })

  test('returns nothing for an unknown project or absent limits', () => {
    expect(getServerResourceLimits(limits, 'missing')).toEqual({})
    expect(getServerResourceLimits(null, 'over')).toEqual({})
  })
})

describe('getUnresolvableResources', () => {
  test('surfaces over-limit types the console cannot resolve', () => {
    const unresolvable = getUnresolvableResources(limits)

    expect(unresolvable).toHaveLength(1)
    expect(unresolvable[0].projectId).toBe('over')
    expect(unresolvable[0].resource.type).toBe('collections')
    expect(unresolvable[0].resource.resolutionHint).toBe(
      'Delete or migrate 30 collections.',
    )
  })
})

describe('getComplianceErrors', () => {
  test('reports projects the server could not evaluate', () => {
    expect(getComplianceErrors(limits)).toEqual([
      {
        projectId: 'unreachable',
        projectName: 'Unreachable',
        error: 'Unknown region: xyz',
      },
    ])
  })
})

describe('getNonCompliantProjectIds', () => {
  test('includes projects blocked by an evaluation failure', () => {
    expect(getNonCompliantProjectIds(limits)).toEqual(['over', 'unreachable'])
  })
})
