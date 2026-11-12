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
    projects: [],
    memberships: [],
    domains: [],
    addons: [],
    projectResources: [],
    resources: {},
    ...overrides,
  }
}

describe('buildDowngradeDeletionSteps', () => {
  test('returns nothing for empty or undefined input', () => {
    expect(buildDowngradeDeletionSteps(undefined)).toEqual([])
    expect(buildDowngradeDeletionSteps(pending())).toEqual([])
  })

  test('orders organization deletes and addons before resource types', () => {
    const steps = buildDowngradeDeletionSteps(
      pending({
        projects: [{ id: 'p-1', name: 'Project one' }],
        memberships: [
          { id: 'm-1', name: 'Ada' },
          { id: 'm-2', name: 'Grace' },
        ],
        domains: [{ id: 'd-1', name: 'example.com' }],
        addons: [
          {
            id: 'addon-1',
            name: 'HIPAA BAA',
            key: 'baa',
            label: 'HIPAA BAA',
            scope: 'organization',
            resourceId: 'org-1',
            addonId: 'addon-1',
          },
        ],
        resources: {
          'p-2': {
            buckets: ['b-1'],
            databases: [{ $id: 'db-1', dbKind: 'tablesdb' }],
          },
        },
      }),
    )

    // The user reads this list top to bottom in the manifest and again in the
    // progress screen, so what is asserted is the grouping they see: what the
    // organization loses, then what each project loses.
    const ids = steps.map(({ id }) => id)
    const lastOrgLevel = Math.max(
      ...['projects', 'members', 'domains', 'addons'].map((id) =>
        ids.indexOf(id),
      ),
    )
    const firstResource = Math.min(
      ...['databases', 'buckets'].map((id) => ids.indexOf(id)),
    )
    expect(lastOrgLevel).toBeLessThan(firstResource)
    expect(steps.every(({ label }) => label.length > 0)).toBe(true)
  })

  test('omits steps with a zero count', () => {
    const steps = buildDowngradeDeletionSteps(
      pending({
        memberships: [{ id: 'm-1', name: 'Ada' }],
        resources: { 'p-1': { functions: [], sites: ['s-1'] } },
      }),
    )

    expect(steps.map(({ id, count }) => ({ id, count }))).toEqual([
      { id: 'members', count: 1 },
      { id: 'sites', count: 1 },
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
