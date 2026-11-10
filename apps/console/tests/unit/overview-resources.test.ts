import { describe, expect, test } from 'bun:test'
import {
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
  test('a new project gets a get-started card for every service', () => {
    expect(pickOverviewResources({}, ALL)).toEqual(ALL)
  })

  test('cards with items come before empty ones', () => {
    expect(
      pickOverviewResources({ sites: 4, auth: 12, messaging: 0 }, ALL),
    ).toEqual([
      'sites',
      'auth',
      'databases',
      'functions',
      'storage',
      'messaging',
    ])
  })

  test('a list that failed to load keeps its card with the filled ones', () => {
    expect(pickOverviewResources({ databases: 2, storage: null }, ALL)).toEqual(
      ['databases', 'storage', 'sites', 'functions', 'auth', 'messaging'],
    )
  })

  test('hidden services get no card', () => {
    expect(
      pickOverviewResources({ databases: 2, storage: 3 }, [
        'databases',
        'functions',
        'storage',
      ]),
    ).toEqual(['databases', 'storage', 'functions'])
  })

  describe('with fewer cards than services', () => {
    test('secondary services with items fill the cards left first', () => {
      expect(
        pickOverviewResources(
          { databases: 1, sites: 1, functions: 1, auth: 5, messaging: 2 },
          ALL,
          4,
        ),
      ).toEqual(['databases', 'sites', 'functions', 'auth'])
    })

    test('a secondary service takes the lowest priority empty card', () => {
      expect(pickOverviewResources({ storage: 2 }, ALL, 3)).toEqual([
        'storage',
        'databases',
        'sites',
      ])
    })

    test('secondary services fill empty cards in their own order', () => {
      expect(
        pickOverviewResources(
          { databases: 3, storage: 1, auth: 12, messaging: 5 },
          ALL,
          3,
        ),
      ).toEqual(['databases', 'storage', 'auth'])
    })

    test('primary services with items keep their cards', () => {
      expect(
        pickOverviewResources(
          { databases: 1, sites: 2, functions: 3, storage: 9, auth: 9 },
          ALL,
          3,
        ),
      ).toEqual(['databases', 'sites', 'functions'])
    })

    test('a primary that failed to load keeps its card', () => {
      expect(
        pickOverviewResources({ functions: null, storage: 2, auth: 4 }, ALL, 3),
      ).toEqual(['functions', 'storage', 'auth'])
    })

    test('a secondary that failed to load takes no other card', () => {
      expect(pickOverviewResources({ storage: null, auth: 1 }, ALL, 3)).toEqual(
        ['auth', 'databases', 'sites'],
      )
    })
  })
})
