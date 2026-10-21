import { describe, expect, test } from 'bun:test'
import { getLabelFeatures, LABEL_FEATURES } from '@/lib/console-profiles'

const ALPHANUMERIC =
  'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'

const labelFeatures = {
  beta: ['analytics', 'functionEmail'],
  partner: ['functionEmail', 'videos'],
} as const

describe('label features', () => {
  test('a label switches on its flags', () => {
    expect(getLabelFeatures(['beta'], labelFeatures)).toEqual({
      analytics: true,
      functionEmail: true,
    })
  })

  test('several labels combine their flags', () => {
    expect(getLabelFeatures(['beta', 'partner'], labelFeatures)).toEqual({
      analytics: true,
      functionEmail: true,
      videos: true,
    })
  })

  test('unknown labels switch nothing on', () => {
    expect(
      getLabelFeatures(['vip', 'constructor', 'toString'], labelFeatures),
    ).toEqual({})
    expect(getLabelFeatures([], labelFeatures)).toEqual({})
  })

  test('labels match case-sensitively', () => {
    expect(getLabelFeatures(['Beta'], labelFeatures)).toEqual({})
  })

  // Appwrite only accepts alphanumeric labels up to 36 characters, so any
  // other key could never be set on an account.
  test('every configured label is one Appwrite accepts', () => {
    for (const label of Object.keys(LABEL_FEATURES)) {
      expect(label.length).toBeGreaterThan(0)
      expect(label.length).toBeLessThanOrEqual(36)
      expect([...label].every((char) => ALPHANUMERIC.includes(char))).toBe(
        true,
      )
    }
  })
})
