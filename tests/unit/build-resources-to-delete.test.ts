/**
 * Downgrade deletes must only include IDs the user marked.
 */

import { describe, expect, test } from 'bun:test'
import { buildResourcesToDelete } from '@/lib/billing/delete-downgrade-resources'
import {
  countResourcesToDeleteForProject,
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
})
