/**
 * The downgrade deletion step list is the single source of truth for the
 * confirmation dialog, the deletion runner and the progress screen.
 */

import { describe, expect, test } from 'bun:test'
import { buildDowngradeDeletionSteps } from '@/lib/billing/downgrade-deletion-steps'
import type { PendingDowngradeDeletions } from '@/components/pages/organizations/$orgId/billing/change-plan/DowngradeValidation'

function pending(
  overrides: Partial<PendingDowngradeDeletions> = {},
): PendingDowngradeDeletions {
  return {
    projectIds: [],
    membershipIds: [],
    domainIds: [],
    resources: {},
    ...overrides,
  }
}

describe('buildDowngradeDeletionSteps', () => {
  test('returns nothing for empty or undefined input', () => {
    expect(buildDowngradeDeletionSteps(undefined)).toEqual([])
    expect(buildDowngradeDeletionSteps(pending())).toEqual([])
  })

  test('orders projects, members and domains before resource types', () => {
    const steps = buildDowngradeDeletionSteps(
      pending({
        projectIds: ['p-1'],
        membershipIds: ['m-1', 'm-2'],
        domainIds: ['d-1'],
        resources: {
          'p-2': {
            buckets: ['b-1'],
            databases: [{ $id: 'db-1', dbKind: 'tablesdb' }],
          },
        },
      }),
    )

    expect(steps.map(({ id }) => id)).toEqual([
      'projects',
      'members',
      'domains',
      'databases',
      'buckets',
    ])
    expect(steps.map(({ label }) => label)).toEqual([
      'Projects',
      'Members',
      'Domains',
      'Databases',
      'Buckets',
    ])
  })

  test('omits steps with a zero count', () => {
    const steps = buildDowngradeDeletionSteps(
      pending({
        membershipIds: ['m-1'],
        resources: { 'p-1': { functions: [], sites: ['s-1'] } },
      }),
    )

    expect(steps).toEqual([
      { id: 'members', label: 'Members', count: 1 },
      { id: 'sites', label: 'Sites', count: 1 },
    ])
  })

  test('sums resource counts across projects', () => {
    const steps = buildDowngradeDeletionSteps(
      pending({
        resources: {
          'p-1': { webhooks: ['w-1', 'w-2'] },
          'p-2': { webhooks: ['w-3'] },
          'p-3': { teams: ['t-1'] },
        },
      }),
    )

    expect(steps).toEqual([
      { id: 'teams', label: 'Teams', count: 1 },
      { id: 'webhooks', label: 'Webhooks', count: 3 },
    ])
  })
})
