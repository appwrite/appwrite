/**
 * What a downgrade silently does to a kept project: backup policies stop
 * running, dedicated databases spin down. Both are decided from the plan alone.
 */

import { describe, expect, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import {
  getBackupPolicyLossRows,
  getDedicatedDatabaseLossRows,
  targetPlanDisablesBackups,
  targetPlanSpinsDownDedicatedDatabases,
  type DowngradeBackupPolicyProject,
  type DowngradeDedicatedDatabaseProject,
} from '@/lib/billing/downgrade-plan-losses'

function policy(overrides: Partial<Models.BackupPolicy> = {}) {
  return {
    $id: 'policy-1',
    name: 'Daily',
    $createdAt: '',
    $updatedAt: '',
    services: ['databases'],
    resources: [],
    resourceId: 'db-1',
    resourceType: 'database',
    retention: 7,
    schedule: '0 0 * * *',
    type: 'full',
    enabled: true,
    ...overrides,
  } as Models.BackupPolicy
}

function dedicated(overrides: Partial<Models.DedicatedDatabase> = {}) {
  return {
    $id: 'dedicated-1',
    $createdAt: '',
    $updatedAt: '',
    projectId: 'p-1',
    name: 'Primary',
    api: 'postgresql',
    engine: 'postgresql',
    ...overrides,
  } as Models.DedicatedDatabase
}

function backupProject(
  overrides: Partial<DowngradeBackupPolicyProject> = {},
): DowngradeBackupPolicyProject {
  return {
    projectId: 'p-1',
    projectName: 'Alpha',
    policies: [],
    ...overrides,
  }
}

function dedicatedProject(
  overrides: Partial<DowngradeDedicatedDatabaseProject> = {},
): DowngradeDedicatedDatabaseProject {
  return {
    projectId: 'p-1',
    projectName: 'Alpha',
    databases: [],
    ...overrides,
  }
}

const freePlan = { backupsEnabled: false, supportsDedicatedDatabases: false }

describe('targetPlanDisablesBackups', () => {
  test('detects a plan with backups turned off', () => {
    expect(targetPlanDisablesBackups({ backupsEnabled: false })).toBe(true)
  })

  test('leaves plans that still support backups alone', () => {
    expect(targetPlanDisablesBackups({ backupsEnabled: true })).toBe(false)
    expect(targetPlanDisablesBackups({})).toBe(false)
    expect(targetPlanDisablesBackups(null)).toBe(false)
    expect(targetPlanDisablesBackups(undefined)).toBe(false)
  })

  test('ignores the numeric policy cap, including the -1 sentinel', () => {
    expect(
      targetPlanDisablesBackups({
        backupsEnabled: true,
        backupPolicies: -1,
      } as never),
    ).toBe(false)
    expect(
      targetPlanDisablesBackups({
        backupsEnabled: false,
        backupPolicies: 40,
      } as never),
    ).toBe(true)
  })
})

describe('targetPlanSpinsDownDedicatedDatabases', () => {
  test('detects a plan without dedicated database support', () => {
    expect(
      targetPlanSpinsDownDedicatedDatabases({
        supportsDedicatedDatabases: false,
      }),
    ).toBe(true)
    expect(targetPlanSpinsDownDedicatedDatabases({})).toBe(true)
  })

  test('leaves supporting plans, and an unloaded plan, alone', () => {
    expect(
      targetPlanSpinsDownDedicatedDatabases({
        supportsDedicatedDatabases: true,
      }),
    ).toBe(false)
    expect(targetPlanSpinsDownDedicatedDatabases(null)).toBe(false)
    expect(targetPlanSpinsDownDedicatedDatabases(undefined)).toBe(false)
  })
})

describe('getBackupPolicyLossRows', () => {
  test('returns nothing without projects or without a plan', () => {
    expect(getBackupPolicyLossRows(undefined, freePlan)).toEqual([])
    expect(getBackupPolicyLossRows([], freePlan)).toEqual([])
    expect(
      getBackupPolicyLossRows([backupProject({ policies: [policy()] })], null),
    ).toEqual([])
  })

  test('names the databases the policies point at, grouped by project', () => {
    const rows = getBackupPolicyLossRows(
      [
        backupProject({
          policies: [
            policy({ $id: 'pol-1', resourceId: 'db-1' }),
            policy({ $id: 'pol-2', resourceId: 'db-1' }),
            policy({ $id: 'pol-3', resourceId: 'db-2' }),
          ],
          databaseNames: { 'db-1': 'Orders', 'db-2': 'Analytics' },
        }),
        backupProject({ projectId: 'p-2', projectName: 'Beta' }),
      ],
      freePlan,
    )

    expect(rows).toEqual([
      {
        projectId: 'p-1',
        projectName: 'Alpha',
        databases: [
          { id: 'db-1', name: 'Orders' },
          { id: 'db-2', name: 'Analytics' },
        ],
      },
    ])
  })

  test('falls back to the database id when the name is unknown', () => {
    const rows = getBackupPolicyLossRows(
      [backupProject({ policies: [policy({ resourceId: 'db-9' })] })],
      freePlan,
    )

    expect(rows[0].databases).toEqual([{ id: 'db-9', name: 'db-9' }])
  })

  test('keeps a project whose policies target no single database', () => {
    const rows = getBackupPolicyLossRows(
      [backupProject({ policies: [policy({ resourceId: undefined })] })],
      freePlan,
    )

    expect(rows).toEqual([
      { projectId: 'p-1', projectName: 'Alpha', databases: [] },
    ])
  })

  test('says nothing when the target plan still supports backups', () => {
    const projects = [backupProject({ policies: [policy()] })]

    expect(getBackupPolicyLossRows(projects, { backupsEnabled: true })).toEqual(
      [],
    )
  })

  test('says nothing about a merely reduced policy cap', () => {
    const projects = [
      backupProject({
        policies: Array.from({ length: 40 }, (_, index) =>
          policy({ $id: `pol-${index}` }),
        ),
      }),
    ]

    expect(
      getBackupPolicyLossRows(projects, {
        backupsEnabled: true,
        backupPolicies: 1,
      } as never),
    ).toEqual([])
  })
})

describe('getDedicatedDatabaseLossRows', () => {
  test('returns nothing without projects or without a plan', () => {
    expect(getDedicatedDatabaseLossRows(undefined, freePlan)).toEqual([])
    expect(getDedicatedDatabaseLossRows([], freePlan)).toEqual([])
    expect(
      getDedicatedDatabaseLossRows(
        [dedicatedProject({ databases: [dedicated()] })],
        null,
      ),
    ).toEqual([])
  })

  test('lists the dedicated databases of every affected project', () => {
    const rows = getDedicatedDatabaseLossRows(
      [
        dedicatedProject({
          databases: [
            dedicated({ $id: 'ded-1', name: 'Primary' }),
            dedicated({ $id: 'ded-2', name: '' }),
          ],
        }),
        dedicatedProject({ projectId: 'p-2', projectName: 'Beta' }),
      ],
      freePlan,
    )

    expect(rows).toEqual([
      {
        projectId: 'p-1',
        projectName: 'Alpha',
        databases: [
          { id: 'ded-1', name: 'Primary' },
          { id: 'ded-2', name: 'ded-2' },
        ],
      },
    ])
  })

  test('says nothing when the target plan still supports dedicated databases', () => {
    const rows = getDedicatedDatabaseLossRows(
      [dedicatedProject({ databases: [dedicated()] })],
      { supportsDedicatedDatabases: true },
    )

    expect(rows).toEqual([])
  })
})
