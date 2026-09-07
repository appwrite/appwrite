/**
 * Which active addons a downgrade has to turn off. `premiumGeoDB` only ever
 * exists at project scope, so the project fan-out is part of the diff.
 */

import { describe, expect, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import {
  getUnsupportedAddonRemovals,
  isDisableableDowngradeAddon,
  type DowngradeAddonSnapshot,
} from '@/lib/billing/downgrade-addons'

function addon(overrides: Partial<Models.Addon> = {}): Models.Addon {
  return {
    $id: 'addon-1',
    $createdAt: '',
    $updatedAt: '',
    $permissions: [],
    key: 'baa',
    resourceType: 'organization',
    resourceId: 'org-1',
    status: 'active',
    currentValue: 1,
    ...overrides,
  }
}

function snapshot(
  overrides: Partial<DowngradeAddonSnapshot> = {},
): DowngradeAddonSnapshot {
  return {
    organizationId: 'org-1',
    organizationAddons: [],
    projects: [],
    ...overrides,
  }
}

const freePlan = {
  supportedAddons: { baa: false, premiumGeoDB: false, premiumGeoDBOrg: false },
}

describe('getUnsupportedAddonRemovals', () => {
  test('returns nothing without a snapshot or without plan addon support', () => {
    expect(getUnsupportedAddonRemovals(undefined, freePlan)).toEqual([])
    expect(getUnsupportedAddonRemovals(snapshot(), null)).toEqual([])
    expect(getUnsupportedAddonRemovals(snapshot(), {})).toEqual([])
  })

  test('reports organization addons the target plan does not support', () => {
    const removals = getUnsupportedAddonRemovals(
      snapshot({
        organizationAddons: [
          addon({ $id: 'a-baa', key: 'baa' }),
          addon({ $id: 'a-geo', key: 'premiumGeoDBOrg' }),
        ],
      }),
      freePlan,
    )

    expect(removals).toEqual([
      {
        key: 'baa',
        label: 'HIPAA BAA',
        scope: 'organization',
        resourceId: 'org-1',
        addonId: 'a-baa',
      },
      {
        key: 'premiumGeoDBOrg',
        label: 'Premium Geo DB',
        scope: 'organization',
        resourceId: 'org-1',
        addonId: 'a-geo',
      },
    ])
  })

  test('keeps addons the target plan still supports', () => {
    const removals = getUnsupportedAddonRemovals(
      snapshot({
        organizationAddons: [addon({ $id: 'a-baa', key: 'baa' })],
      }),
      {
        supportedAddons: {
          baa: true,
          premiumGeoDB: false,
          premiumGeoDBOrg: false,
        },
      },
    )

    expect(removals).toEqual([])
  })

  test('excludes addons already scheduled for removal', () => {
    const removals = getUnsupportedAddonRemovals(
      snapshot({
        organizationAddons: [
          addon({ $id: 'a-baa', key: 'baa', nextValue: 0 }),
          addon({ $id: 'a-geo', key: 'premiumGeoDBOrg', nextValue: 1 }),
        ],
      }),
      freePlan,
    )

    expect(removals.map(({ addonId }) => addonId)).toEqual(['a-geo'])
  })

  test('ignores addons that are neither active nor pending', () => {
    const removals = getUnsupportedAddonRemovals(
      snapshot({
        organizationAddons: [
          addon({ $id: 'a-baa', key: 'baa', status: 'cancelled' }),
        ],
      }),
      freePlan,
    )

    expect(removals).toEqual([])
  })

  test('names the project for project-scoped addons', () => {
    const removals = getUnsupportedAddonRemovals(
      snapshot({
        projects: [
          {
            projectId: 'p-1',
            projectName: 'Alpha',
            addons: [
              addon({
                $id: 'a-1',
                key: 'premiumGeoDB',
                resourceType: 'project',
                resourceId: 'p-1',
              }),
            ],
          },
          {
            projectId: 'p-2',
            projectName: 'Beta',
            addons: [
              addon({
                $id: 'a-2',
                key: 'premiumGeoDB',
                resourceType: 'project',
                resourceId: 'p-2',
                nextValue: 0,
              }),
            ],
          },
          { projectId: 'p-3', projectName: 'Gamma', addons: [] },
        ],
      }),
      freePlan,
    )

    expect(removals).toEqual([
      {
        key: 'premiumGeoDB',
        label: 'Premium Geo DB',
        scope: 'project',
        resourceId: 'p-1',
        addonId: 'a-1',
        projectName: 'Alpha',
      },
    ])
  })

  test('reports both scopes, organization first per addon key', () => {
    const removals = getUnsupportedAddonRemovals(
      snapshot({
        organizationAddons: [
          addon({ $id: 'a-baa', key: 'baa' }),
          addon({ $id: 'a-geo-org', key: 'premiumGeoDBOrg' }),
        ],
        projects: [
          {
            projectId: 'p-1',
            projectName: 'Alpha',
            addons: [
              addon({
                $id: 'a-geo',
                key: 'premiumGeoDB',
                resourceType: 'project',
                resourceId: 'p-1',
              }),
            ],
          },
        ],
      }),
      freePlan,
    )

    expect(removals.map(({ addonId, scope }) => [addonId, scope])).toEqual([
      ['a-baa', 'organization'],
      ['a-geo', 'project'],
      ['a-geo-org', 'organization'],
    ])
  })
})

describe('isDisableableDowngradeAddon', () => {
  test('covers every addon key a plan can declare support for', () => {
    expect(isDisableableDowngradeAddon('baa')).toBe(true)
    expect(isDisableableDowngradeAddon('premiumGeoDB')).toBe(true)
    expect(isDisableableDowngradeAddon('premiumGeoDBOrg')).toBe(true)
  })

  test('leaves keys with no disable path alone', () => {
    expect(isDisableableDowngradeAddon('backup_recovery')).toBe(false)
    expect(isDisableableDowngradeAddon('')).toBe(false)
  })
})
