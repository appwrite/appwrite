/**
 * The plan change estimation endpoint reports *every* plan-limited resource,
 * flagging the ones over limit. Reading that payload wrong goes badly in both
 * directions: treat within-limit entries as violations and every downgrade
 * wedges; drop a type the console cannot resolve and a real blocker is hidden.
 * Pin both here.
 *
 * The console now has a picker for every type the server reports, so the
 * unresolvable path is a forward-compat guard rather than a live case - it has
 * to keep holding for whatever type the server adds next.
 */

import { describe, expect, test } from 'bun:test'
import {
  getComplianceErrors,
  getNonCompliantProjectIds,
  getOrganizationLimits,
  getOrganizationViolations,
  LIMIT_UNLIMITED,
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
  nonCompliantProjects: 2,
  canChangePlan: false,
  unsupportedAddons: [],
  projects: withinLimit('projects', 1, 2),
  members: overLimit('members', 4, 1),
  domains: withinLimit('domains', 0, 1),
  projectCompliance: [
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
    // buckets is within limit but still needs its limit for the usage display.
    expect(getServerResourceLimits(limits, 'over')).toEqual({
      databases: 1,
      buckets: 1,
      platforms: 3,
    })
  })

  test('ignores resource types the console has no selection UI for', () => {
    // `collections` is reported by no current plan, but the guard has to hold
    // for any type the server adds before the console grows a picker for it.
    const withUnknownType: PlanChangeLimits = {
      ...limits,
      projectCompliance: [
        {
          $id: 'over',
          name: 'Over limit',
          isCompliant: false,
          resources: [overLimit('collections', 40, 10)],
        },
      ],
    }

    expect(getServerResourceLimits(withUnknownType, 'over')).toEqual({})
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
    // Every type the server reports today has a picker, so this is the
    // forward-compat guard: a type the console does not know must surface as a
    // blocker with the server's hint rather than be silently dropped.
    const withUnknownType: PlanChangeLimits = {
      ...limits,
      projectCompliance: [
        {
          $id: 'over',
          name: 'Over limit',
          isCompliant: false,
          resources: [
            overLimit('databases', 3, 1),
            overLimit('collections', 40, 10),
          ],
        },
      ],
    }

    const unresolvable = getUnresolvableResources(withUnknownType)

    expect(unresolvable).toHaveLength(1)
    expect(unresolvable[0].projectId).toBe('over')
    expect(unresolvable[0].resource.type).toBe('collections')
    expect(unresolvable[0].resource.resolutionHint).toBe(
      'Delete or migrate 30 collections.',
    )
  })

  test('returns nothing when every over-limit type is selectable', () => {
    // platforms/webhooks/wafRules/teams/topics all have pickers now, so an
    // ordinary over-limit project must not hard-block the downgrade.
    expect(getUnresolvableResources(limits)).toEqual([])
  })

  test('ignores resources that are within limits', () => {
    // Regression: the server reports within-limit resources too. Treating them
    // as blockers would block every downgrade.
    const allWithinLimit: PlanChangeLimits = {
      ...limits,
      projectCompliance: [
        {
          $id: 'fine',
          name: 'Fine',
          isCompliant: true,
          resources: [withinLimit('collections', 1, 10)],
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

  test('omits uncapped resources rather than leaking the sentinel', () => {
    // -1 means "no cap". Passing it through would read as a cap of minus one
    // and flag every organization as over limit.
    const uncapped: PlanChangeLimits = {
      ...limits,
      projects: { ...withinLimit('projects', 9, 0), limit: LIMIT_UNLIMITED },
    }

    const result = getOrganizationLimits(uncapped)
    expect(result).not.toBeNull()
    expect(result).not.toHaveProperty('projects')
    expect(result?.members).toBe(1)
  })

  test('returns null when the server reported none', () => {
    // A console running ahead of cloud gets the older shape - callers must fall
    // back to the plan-config derivation.
    const { projects: _p, members: _m, domains: _d, ...withoutOrg } = limits
    expect(
      getOrganizationLimits(withoutOrg as unknown as PlanChangeLimits),
    ).toBeNull()
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
