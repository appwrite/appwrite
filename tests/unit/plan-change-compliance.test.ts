/**
 * The plan change estimation endpoint reports *every* plan-limited resource,
 * flagging the ones over limit, and it reports resource types the console has
 * no selection UI for (`platforms`, `webhooks`) alongside ones it does. Reading
 * that payload wrong goes badly in both directions: treat within-limit entries
 * as violations and every downgrade wedges; ignore the unselectable ones and a
 * real blocker is hidden. Pin both here.
 */

import { describe, expect, test } from 'bun:test'
import {
  getComplianceErrors,
  getNonCompliantProjectIds,
  getOrganizationLimits,
  getOrganizationViolations,
  getServerResourceLimits,
  getUnresolvableResources,
  type PlanChangeLimits,
} from '@/lib/billing/plan-change-compliance'

function overLimit(type: string, currentUsage: number, limit: number) {
  return {
    type,
    currentUsage,
    limit,
    status: 'over_limit',
    excess: currentUsage - limit,
    resolutionHint: `Delete or migrate ${currentUsage - limit} ${type}.`,
  }
}

function withinLimit(type: string, currentUsage: number, limit: number) {
  return {
    type,
    currentUsage,
    limit,
    status: 'within_limit',
    excess: 0,
    resolutionHint: '',
  }
}

const limits: PlanChangeLimits = {
  totalProjects: 3,
  nonCompliantProjects: 2,
  canChangePlan: false,
  unsupportedAddons: [],
  organization: {
    isCompliant: false,
    resources: [
      withinLimit('projects', 1, 2),
      overLimit('members', 4, 1),
      withinLimit('domains', 0, 1),
    ],
  },
  projects: [
    {
      $id: 'compliant',
      name: 'Compliant',
      isCompliant: true,
      resources: [withinLimit('databases', 1, 1), withinLimit('buckets', 0, 1)],
    },
    {
      $id: 'over',
      name: 'Over limit',
      isCompliant: false,
      resources: [
        overLimit('databases', 3, 1),
        withinLimit('buckets', 1, 1),
        overLimit('platforms', 9, 3),
      ],
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
  test('returns limits for selectable types whether or not they are over limit', () => {
    // buckets is within limit but still needs its limit for the usage display;
    // platforms has no selection UI, so it must not become a selection limit.
    expect(getServerResourceLimits(limits, 'over')).toEqual({
      databases: 1,
      buckets: 1,
    })
  })

  test('returns limits for a compliant project too', () => {
    expect(getServerResourceLimits(limits, 'compliant')).toEqual({
      databases: 1,
      buckets: 1,
    })
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
    expect(unresolvable[0].resource.type).toBe('platforms')
    expect(unresolvable[0].resource.resolutionHint).toBe(
      'Delete or migrate 6 platforms.',
    )
  })

  test('ignores resources that are within limits', () => {
    // Regression: the server reports within-limit resources too. Treating them
    // as blockers would block every downgrade.
    const allWithinLimit: PlanChangeLimits = {
      ...limits,
      projects: [
        {
          $id: 'fine',
          name: 'Fine',
          isCompliant: true,
          resources: [withinLimit('platforms', 1, 3)],
        },
      ],
    }

    expect(getUnresolvableResources(allWithinLimit)).toEqual([])
  })
})

describe('getOrganizationLimits', () => {
  test('reads org-level caps from the server', () => {
    expect(getOrganizationLimits(limits)).toEqual({
      projects: 2,
      members: 1,
      domains: 1,
    })
  })

  test('returns null when the server did not report them', () => {
    // Upgrades, and a console running ahead of cloud, have no organization
    // block - callers must fall back to the plan-config derivation.
    const { organization: _organization, ...withoutOrg } = limits
    expect(getOrganizationLimits(withoutOrg)).toBeNull()
    expect(getOrganizationLimits(null)).toBeNull()
  })
})

describe('getOrganizationViolations', () => {
  test('returns only the org resources over limit', () => {
    const violations = getOrganizationViolations(limits)

    expect(violations).toHaveLength(1)
    expect(violations[0].type).toBe('members')
    expect(violations[0].excess).toBe(3)
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
