import { describe, expect, test } from 'bun:test'
import {
  PROJECT_ENVIRONMENT_COPY_GROUPS,
  defaultProjectEnvironmentCopySelection,
  migrationProgress,
  projectEnvironmentCopyResources,
} from '@/lib/project-environments'

describe('project environment copy', () => {
  test('the default selection copies every structure and no data', () => {
    const resources = projectEnvironmentCopyResources(
      defaultProjectEnvironmentCopySelection(),
    )

    expect(resources).toContain('database')
    expect(resources).toContain('table')
    expect(resources).toContain('bucket')
    expect(resources).toContain('team')
    expect(resources).toContain('function')
    expect(resources).toContain('platform')
    for (const data of [
      'row',
      'document',
      'file',
      'user',
      'membership',
      'deployment',
      'site-deployment',
    ]) {
      expect(resources).not.toContain(data)
    }
  })

  test('data follows its group', () => {
    const selection = defaultProjectEnvironmentCopySelection()
    selection.databases.data = true
    selection.storage = { structure: false, data: true }

    const resources = projectEnvironmentCopyResources(selection)

    expect(resources).toContain('row')
    expect(resources).toContain('document')
    expect(resources).not.toContain('bucket')
    expect(resources).not.toContain('file')
  })

  test('nothing selected sends nothing', () => {
    const selection = defaultProjectEnvironmentCopySelection()
    for (const group of PROJECT_ENVIRONMENT_COPY_GROUPS) {
      selection[group.id] = { structure: false, data: false }
    }

    expect(projectEnvironmentCopyResources(selection)).toEqual([])
  })

  test('every group names resource types once', () => {
    const resources = projectEnvironmentCopyResources(
      Object.fromEntries(
        PROJECT_ENVIRONMENT_COPY_GROUPS.map((group) => [
          group.id,
          { structure: true, data: true },
        ]),
      ) as ReturnType<typeof defaultProjectEnvironmentCopySelection>,
    )

    expect(new Set(resources).size).toBe(resources.length)
  })

  test('progress counts everything but pending and processing as done', () => {
    expect(migrationProgress(undefined)).toBe(0)
    expect(migrationProgress({})).toBe(0)
    expect(
      migrationProgress({
        database: { pending: 0, success: 1, error: 0, skip: 0, processing: 0 },
        row: { pending: 50, success: 49, error: 0, skip: 1, processing: 0 },
      }),
    ).toBe(50)
    expect(
      migrationProgress({ user: { pending: 0, success: 90, skip: 2 } }),
    ).toBe(100)
  })
})
