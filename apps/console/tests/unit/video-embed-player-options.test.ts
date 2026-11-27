import { describe, expect, test } from 'bun:test'
import {
  embedPlayerSearchParams,
  parseEmbedColor,
  parseEmbedFlag,
  parseEmbedPlayerOptions,
  parseEmbedPlayerQuery,
} from '@/lib/videos/embed-player-options'
import { getVideoEmbedPlayerQuery } from '@/lib/videos/embed-player-url'
import { parseEmbedStartParam } from '@/lib/videos/embed-start-time'

describe('parseEmbedStartParam', () => {
  test.each([
    ['90', 90],
    ['12.5', 12.5],
    ['90s', 90],
    ['1m30s', 90],
    ['1h2m3s', 3723],
    ['1:30', 90],
    ['1:02:03', 3723],
    [' 2M ', 120],
    [45, 45],
  ] as const)('reads %p as %p seconds', (value, expected) => {
    expect(parseEmbedStartParam(value)).toBe(expected)
  })

  test.each([
    ['0'],
    ['-5'],
    ['soon'],
    ['1:xx'],
    ['1m30'],
    ['m'],
    ['1:2:3:4'],
    [''],
    [null],
  ])('leaves %p unset', (value) => {
    expect(parseEmbedStartParam(value)).toBeUndefined()
  })
})

describe('parseEmbedFlag', () => {
  test.each([
    ['1', true],
    ['true', true],
    ['YES', true],
    ['', true],
    ['0', false],
    ['false', false],
    ['off', false],
    [1, true],
    [0, false],
    [true, true],
  ] as const)('reads %p as %p', (value, expected) => {
    expect(parseEmbedFlag(value)).toBe(expected)
  })

  test.each([['maybe'], [2], [null], [undefined], [['1', '0']]])(
    'leaves %p unset',
    (value) => {
      expect(parseEmbedFlag(value)).toBeUndefined()
    },
  )
})

describe('parseEmbedColor', () => {
  test.each([
    ['fd366e', '#fd366e'],
    ['#FD366E', '#fd366e'],
    ['f36', '#ff3366'],
    [' 000000 ', '#000000'],
  ])('reads %p as %p', (value, expected) => {
    expect(parseEmbedColor(value)).toBe(expected)
  })

  test.each([['red'], ['#12345'], ['zzzzzz'], ['ff0000;x'], [''], [12]])(
    'refuses %p',
    (value) => {
      expect(parseEmbedColor(value)).toBeUndefined()
    },
  )
})

describe('embed player options', () => {
  test('reads every option from the player page search', () => {
    expect(
      parseEmbedPlayerOptions({
        project: 'p1',
        start: '12.5',
        autoplay: '1',
        muted: 'true',
        loop: '',
        controls: '0',
        color: 'fd366e',
      }),
    ).toEqual({
      start: 12.5,
      autoplay: true,
      muted: true,
      loop: true,
      controls: false,
      color: '#fd366e',
    })
  })

  test('ignores values it cannot read', () => {
    expect(
      parseEmbedPlayerOptions({ start: 'soon', autoplay: 'x', color: 'red' }),
    ).toEqual({
      start: undefined,
      autoplay: undefined,
      muted: undefined,
      loop: undefined,
      controls: undefined,
      color: undefined,
    })
  })

  test('reads a typed query string, with or without the URL around it', () => {
    const expected = parseEmbedPlayerQuery('?project=p1&autoplay=1&loop=1')
    expect(expected).toMatchObject({ autoplay: true, loop: true })
    expect(
      parseEmbedPlayerQuery(
        'https://cloud.appwrite.io/player/v1?project=p1&autoplay=1&loop=1',
      ),
    ).toEqual(expected)
    expect(parseEmbedPlayerQuery('autoplay=1&loop=1')).toEqual(expected)
  })

  test('writes only the options that change the player', () => {
    expect(
      embedPlayerSearchParams({
        start: 0,
        autoplay: false,
        muted: true,
        controls: true,
        color: '#FD366E',
      }),
    ).toEqual([
      ['muted', '1'],
      ['color', 'fd366e'],
    ])
    expect(embedPlayerSearchParams({ controls: false })).toEqual([
      ['controls', '0'],
    ])
  })

  test('round-trips through the player query string', () => {
    const options = {
      start: 30,
      autoplay: true,
      muted: true,
      loop: true,
      controls: false,
      color: '#3b82f6',
    }
    const query = getVideoEmbedPlayerQuery('p1', options)
    expect(query).toBe(
      '?project=p1&start=30&autoplay=1&muted=1&loop=1&controls=0&color=3b82f6',
    )
    expect(parseEmbedPlayerQuery(query)).toEqual(options)
  })
})
