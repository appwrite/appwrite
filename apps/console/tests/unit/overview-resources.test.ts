import { describe, expect, test } from 'bun:test'
import {
  needsOverviewSecondaryResources,
  pickOverviewResources,
  type OverviewResourceId,
} from '@/lib/overview-resources'

const ALL: OverviewResourceId[] = [
  'databases',
  'sites',
  'functions',
  'storage',
  'auth',
  'messaging',
]

describe('pickOverviewResources', () => {
  test('a new project gets the three get-started cards', () => {
    expect(
      pickOverviewResources(
        {
          databases: 0,
          sites: 0,
          functions: 0,
          storage: 0,
          auth: 0,
          messaging: 0,
        },
        ALL,
      ),
    ).toEqual(['databases', 'sites', 'functions'])
  })

  test('cards with items come before empty ones', () => {
    expect(
      pickOverviewResources({ databases: 0, sites: 4, functions: 1 }, ALL),
    ).toEqual(['sites', 'functions', 'databases'])
  })

  test('a secondary service takes the lowest priority empty card', () => {
    expect(
      pickOverviewResources(
        { databases: 0, sites: 0, functions: 0, storage: 2 },
        ALL,
      ),
    ).toEqual(['storage', 'databases', 'sites'])
  })

  test('secondary services fill empty cards in their own order', () => {
    expect(
      pickOverviewResources(
        {
          databases: 3,
          sites: 0,
          functions: 0,
          storage: 1,
          auth: 12,
          messaging: 5,
        },
        ALL,
      ),
    ).toEqual(['databases', 'storage', 'auth'])
  })

  test('primary services with items keep their cards', () => {
    expect(
      pickOverviewResources(
        { databases: 1, sites: 2, functions: 3, storage: 9, auth: 9 },
        ALL,
      ),
    ).toEqual(['databases', 'sites', 'functions'])
  })

  test('a primary that failed to load keeps its card', () => {
    expect(
      pickOverviewResources(
        { databases: 0, sites: 0, functions: null, storage: 2, auth: 4 },
        ALL,
      ),
    ).toEqual(['functions', 'storage', 'auth'])
  })

  test('a secondary that failed to load is skipped', () => {
    expect(
      pickOverviewResources(
        { databases: 0, sites: 0, functions: 0, storage: null, auth: 1 },
        ALL,
      ),
    ).toEqual(['auth', 'databases', 'sites'])
  })

  test('hidden services get no card', () => {
    expect(
      pickOverviewResources(
        { databases: 2, sites: 0, functions: 0, storage: 3 },
        ['databases', 'functions', 'auth'],
      ),
    ).toEqual(['databases', 'functions'])
  })
})

describe('needsOverviewSecondaryResources', () => {
  test('only when a visible primary is empty', () => {
    expect(
      needsOverviewSecondaryResources(
        { databases: 1, sites: 1, functions: 0 },
        ALL,
      ),
    ).toBe(true)
    expect(
      needsOverviewSecondaryResources(
        { databases: 1, sites: 1, functions: 1 },
        ALL,
      ),
    ).toBe(false)
    expect(
      needsOverviewSecondaryResources(
        { databases: 1, sites: 1, functions: 0 },
        ['databases', 'sites'],
      ),
    ).toBe(false)
  })

  test('not while a primary is still loading', () => {
    expect(
      needsOverviewSecondaryResources({ databases: 1, sites: 1 }, ALL),
    ).toBe(false)
  })
})
