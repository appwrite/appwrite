/**
 * Downgrade deletes must only include IDs the user marked.
 */

import { describe, expect, test } from 'bun:test'
import {
  buildResourcesToDelete,
  narrowResourcesToType,
  type ResourcesToDelete,
} from '@/lib/billing/delete-downgrade-resources'
import {
  countResourcesToDeleteForProject,
  countStagedResourcesForProject,
  projectHasResourceViolations,
  type DowngradeResourceLimits,
  type ProjectDowngradeResources,
} from '@/lib/billing/downgrade-plan-limits'

function item(id: string) {
  return { $id: id, name: id }
}

function emptyGroup() {
  return { items: [] as { $id: string; name: string }[], total: 0 }
}

function resources(
  overrides: Partial<ProjectDowngradeResources>,
): ProjectDowngradeResources {
  return {
    databases: emptyGroup(),
    buckets: emptyGroup(),
    functions: emptyGroup(),
    sites: emptyGroup(),
    teams: emptyGroup(),
    topics: emptyGroup(),
    platforms: emptyGroup(),
    webhooks: emptyGroup(),
    wafRules: emptyGroup(),
    ...overrides,
  }
}

const limits: DowngradeResourceLimits = {
  databases: 5,
  buckets: 3,
  functions: 1,
  sites: null,
  teams: null,
  topics: null,
  platforms: null,
  webhooks: null,
  wafRules: null,
}

describe('buildResourcesToDelete', () => {
  test('deletes nothing when nothing was marked', () => {
    expect(
      buildResourcesToDelete(
        resources({
          functions: {
            items: [item('fn-1'), item('fn-2')],
            total: 2,
          },
        }),
        {},
      ),
    ).toEqual({})
  })

  test('deletes only marked items that are in the fetched list', () => {
    const toDelete = buildResourcesToDelete(
      resources({
        functions: {
          items: [item('fn-1'), item('fn-2'), item('fn-3')],
          total: 3,
        },
      }),
      { functions: new Set(['fn-2', 'missing']) },
    )

    expect(toDelete.functions).toEqual(['fn-2'])
    expect(toDelete.databases).toBeUndefined()
  })
})

describe('narrowResourcesToType', () => {
  const payload: ResourcesToDelete = {
    'p-1': { buckets: ['b-1', 'b-2'], functions: ['fn-1'] },
    'p-2': { buckets: [], databases: [{ $id: 'db-1', dbKind: 'tablesdb' }] },
  }

  test('keeps only the requested type across projects', () => {
    expect(narrowResourcesToType(payload, 'buckets')).toEqual({
      'p-1': { buckets: ['b-1', 'b-2'] },
    })
  })

  test('keeps database refs intact', () => {
    expect(narrowResourcesToType(payload, 'databases')).toEqual({
      'p-2': { databases: [{ $id: 'db-1', dbKind: 'tablesdb' }] },
    })
  })

  test('returns nothing when no project has that type', () => {
    expect(narrowResourcesToType(payload, 'topics')).toEqual({})
  })
})

describe('countResourcesToDeleteForProject', () => {
  test('reports remaining overage against the target plan', () => {
    expect(
      countResourcesToDeleteForProject(
        resources({
          databases: { items: [item('db-1')], total: 1 },
          functions: {
            items: [item('fn-1'), item('fn-2'), item('fn-3')],
            total: 3,
          },
        }),
        limits,
      ),
    ).toEqual({ functions: 2 })
  })

  test('subtracts staged deletions from the overage', () => {
    expect(
      countResourcesToDeleteForProject(
        resources({
          functions: {
            items: [item('fn-1'), item('fn-2'), item('fn-3')],
            total: 3,
          },
        }),
        limits,
        { functions: new Set(['fn-2']) },
      ),
    ).toEqual({ functions: 1 })
  })

  test('ignores staged ids that already left the list', () => {
    expect(
      countResourcesToDeleteForProject(
        resources({
          functions: {
            items: [item('fn-1'), item('fn-2')],
            total: 2,
          },
        }),
        limits,
        { functions: new Set(['fn-2', 'fn-gone']) },
      ),
    ).toEqual({})
  })
})

describe('projectHasResourceViolations', () => {
  const overLimit = resources({
    functions: {
      items: [item('fn-1'), item('fn-2'), item('fn-3')],
      total: 3,
    },
  })

  test('is true while the staged deletions do not cover the overage', () => {
    expect(
      projectHasResourceViolations(overLimit, limits, {
        functions: new Set(['fn-3']),
      }),
    ).toBe(true)
  })

  test('is false once the staged deletions bring usage within the limit', () => {
    expect(
      projectHasResourceViolations(overLimit, limits, {
        functions: new Set(['fn-2', 'fn-3']),
      }),
    ).toBe(false)
  })
})

describe('countStagedResourcesForProject', () => {
  test('counts only staged ids still present in the list', () => {
    expect(
      countStagedResourcesForProject(
        resources({
          buckets: { items: [item('b-1'), item('b-2')], total: 2 },
          functions: { items: [item('fn-1')], total: 1 },
        }),
        { buckets: new Set(['b-1', 'b-gone']), functions: new Set() },
      ),
    ).toEqual({ buckets: 1 })
  })
})
