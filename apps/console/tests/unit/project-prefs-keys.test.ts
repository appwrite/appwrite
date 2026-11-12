import { describe, expect, test } from 'bun:test'
import {
  PROJECT_PREFS_KEY_ENVIRONMENTS_ORDER,
  mergeEnvironmentsOrderPrefs,
  parseEnvironmentsOrder,
} from '@/lib/project-prefs-keys'

describe('project prefs: environments order', () => {
  test('the key follows the console.<feature>.<subKey> format', () => {
    expect(PROJECT_PREFS_KEY_ENVIRONMENTS_ORDER).toBe(
      'console.environments.order',
    )
  })

  test('parses the JSON string the merge writes', () => {
    const prefs = mergeEnvironmentsOrderPrefs({ other: 'kept' }, ['b', 'a'])

    expect(prefs.other).toBe('kept')
    expect(prefs[PROJECT_PREFS_KEY_ENVIRONMENTS_ORDER]).toBe('["b","a"]')
    expect(parseEnvironmentsOrder(prefs)).toEqual(['b', 'a'])
  })

  test('drops duplicates and empty ids', () => {
    expect(
      parseEnvironmentsOrder(
        mergeEnvironmentsOrderPrefs(null, ['a', '', 'b', 'a']),
      ),
    ).toEqual(['a', 'b'])
  })

  test('tolerates a missing, malformed or foreign value', () => {
    expect(parseEnvironmentsOrder(null)).toEqual([])
    expect(parseEnvironmentsOrder({})).toEqual([])
    expect(
      parseEnvironmentsOrder({
        [PROJECT_PREFS_KEY_ENVIRONMENTS_ORDER]: '{bad',
      }),
    ).toEqual([])
    expect(
      parseEnvironmentsOrder({
        [PROJECT_PREFS_KEY_ENVIRONMENTS_ORDER]: '[1, null, "ok"]',
      }),
    ).toEqual(['ok'])
    expect(
      parseEnvironmentsOrder({
        [PROJECT_PREFS_KEY_ENVIRONMENTS_ORDER]: ['x', 2, 'y'],
      }),
    ).toEqual(['x', 'y'])
  })
})
