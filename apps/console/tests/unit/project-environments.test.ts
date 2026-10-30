import { describe, expect, test } from 'bun:test'
import {
  PROJECT_ENVIRONMENT_DOT_CLASS,
  PROJECT_ENVIRONMENT_DOT_CLASS_PRODUCTION,
  projectEnvironmentDotClass,
  sortProjectEnvironments,
} from '@/lib/project-environments'

describe('project environment dot colors', () => {
  test('production is green and every environment is purple', () => {
    expect(projectEnvironmentDotClass({ isDefault: true })).toBe(
      PROJECT_ENVIRONMENT_DOT_CLASS_PRODUCTION,
    )
    expect(PROJECT_ENVIRONMENT_DOT_CLASS_PRODUCTION).toContain('emerald')

    // Nothing but isDefault is read, so no id can change an environment's color
    expect(projectEnvironmentDotClass({ isDefault: false })).toBe(
      PROJECT_ENVIRONMENT_DOT_CLASS,
    )
    expect(PROJECT_ENVIRONMENT_DOT_CLASS).toContain('purple')
  })
})

describe('project environment order', () => {
  const environments = [
    { $id: 'qa', name: 'QA' },
    { $id: 'dev', name: 'Development' },
    { $id: 'staging', name: 'Staging' },
  ]

  test('an empty order keeps the list as given', () => {
    expect(sortProjectEnvironments(environments, []).map((e) => e.$id)).toEqual(
      ['qa', 'dev', 'staging'],
    )
  })

  test('a saved order is applied', () => {
    expect(
      sortProjectEnvironments(environments, ['staging', 'dev', 'qa']).map(
        (e) => e.$id,
      ),
    ).toEqual(['staging', 'dev', 'qa'])
  })

  test('environments the saved order does not know follow the known ones in their own order', () => {
    expect(
      sortProjectEnvironments(environments, ['staging']).map((e) => e.$id),
    ).toEqual(['staging', 'qa', 'dev'])
  })

  test('ids the saved order has but the list lacks are ignored', () => {
    expect(
      sortProjectEnvironments(environments, ['deleted', 'dev', 'qa']).map(
        (e) => e.$id,
      ),
    ).toEqual(['dev', 'qa', 'staging'])
  })

  test('the input is not mutated', () => {
    const copy = [...environments]
    sortProjectEnvironments(environments, ['staging'])
    expect(environments).toEqual(copy)
  })
})
