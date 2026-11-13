import { describe, expect, test } from 'bun:test'
import {
  PROJECT_ENVIRONMENT_DOT_CLASS,
  PROJECT_ENVIRONMENT_DOT_CLASS_PRODUCTION,
  projectEnvironmentDotClass,
  projectEnvironmentsAllowance,
  sortProjectEnvironments,
  suggestProjectEnvironmentId,
  suggestProjectEnvironmentName,
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

describe('project environment name suggestion', () => {
  test('the first unused name is Stage, then Dev, then QA', () => {
    expect(suggestProjectEnvironmentName([])).toBe('Stage')
    expect(suggestProjectEnvironmentName(['Stage'])).toBe('Dev')
    expect(suggestProjectEnvironmentName(['staging'])).toBe('Dev')
    expect(suggestProjectEnvironmentName(['STAGE', 'dev'])).toBe('QA')
  })

  test('aliases of a suggestion count as taken', () => {
    expect(suggestProjectEnvironmentName(['Development'])).toBe('Stage')
    expect(suggestProjectEnvironmentName(['Stage', 'development'])).toBe('QA')
    expect(suggestProjectEnvironmentName(['testing'])).toBe('Stage')
  })

  test('the project name is treated as taken', () => {
    expect(suggestProjectEnvironmentName(['My App'])).toBe('Stage')
    expect(suggestProjectEnvironmentName(['Stage'])).toBe('Dev')
  })

  test('an empty string is returned when every suggestion is taken', () => {
    expect(
      suggestProjectEnvironmentName([
        'Stage',
        'QA',
        'Dev',
        'Preview',
        'Test',
        'Sandbox',
        'Demo',
        'UAT',
        'Beta',
      ]),
    ).toBe('')
  })

  test('blank taken names are ignored', () => {
    expect(suggestProjectEnvironmentName(['', '  '])).toBe('Stage')
  })
})

describe('project environment id suggestion', () => {
  test('the name becomes a project-shaped id', () => {
    expect(suggestProjectEnvironmentId('Stage')).toBe('stage')
    expect(suggestProjectEnvironmentId('QA / Beta 2')).toBe('qa-beta-2')
    expect(suggestProjectEnvironmentId('  Preview  ')).toBe('preview')
  })

  test('a name without letters or digits leaves the id to the server', () => {
    expect(suggestProjectEnvironmentId('***')).toBe('')
    expect(suggestProjectEnvironmentId('')).toBe('')
  })

  test('the id never exceeds the project id length nor ends in a hyphen', () => {
    const id = suggestProjectEnvironmentId('a'.repeat(35) + ' tail')
    expect(id.length).toBeLessThanOrEqual(36)
    expect(id.endsWith('-')).toBe(false)
    expect(id).toBe('a'.repeat(35))
  })
})

describe('project environments on the plan', () => {
  test('a plan without environments offers none, however many a project kept', () => {
    // Free and Start answer 0; a project that had environments before keeps them
    expect(projectEnvironmentsAllowance({ environments: 0 }, 0)).toEqual({
      status: 'not-offered',
    })
    expect(projectEnvironmentsAllowance({ environments: 0 }, 2)).toEqual({
      status: 'not-offered',
    })
  })

  test('a plan with environments offers them up to its cap', () => {
    expect(projectEnvironmentsAllowance({ environments: 3 }, 0)).toEqual({
      status: 'available',
      limit: 3,
    })
    expect(projectEnvironmentsAllowance({ environments: 3 }, 2)).toEqual({
      status: 'available',
      limit: 3,
    })
    expect(projectEnvironmentsAllowance({ environments: 3 }, 3)).toEqual({
      status: 'at-limit',
      limit: 3,
    })
    // Kept from a larger cap: still at it, not below
    expect(projectEnvironmentsAllowance({ environments: 3 }, 5)).toEqual({
      status: 'at-limit',
      limit: 3,
    })
  })

  test('nothing is decided before the plan and the environments are read', () => {
    expect(projectEnvironmentsAllowance(undefined, 0)).toEqual({
      status: 'unknown',
    })
    expect(
      projectEnvironmentsAllowance({ environments: 3 }, undefined),
    ).toEqual({ status: 'unknown' })
  })

  test('a plan that does not name the cap offers none', () => {
    // A cloud older than the cap, or a plan written without it
    expect(projectEnvironmentsAllowance({}, 0)).toEqual({
      status: 'not-offered',
    })
  })
})
