import { describe, expect, test } from 'bun:test'
import { getHanukkahNight, isHanukkahBranchLit } from '@/lib/hanukkah'

describe('getHanukkahNight', () => {
  test('stays unlit before the first sundown', () => {
    expect(getHanukkahNight(new Date(2026, 11, 4, 16, 29))).toBe(0)
  })

  test('starts night 1 at sundown on the first day', () => {
    expect(getHanukkahNight(new Date(2026, 11, 4, 16, 30))).toBe(1)
  })

  test('keeps the previous night until the next sundown', () => {
    expect(getHanukkahNight(new Date(2026, 11, 5, 10, 0))).toBe(1)
    expect(getHanukkahNight(new Date(2026, 11, 5, 16, 30))).toBe(2)
  })

  test('reaches night 8 on the last nights', () => {
    expect(getHanukkahNight(new Date(2026, 11, 11, 16, 30))).toBe(8)
    expect(getHanukkahNight(new Date(2026, 11, 12, 10, 0))).toBe(8)
    expect(getHanukkahNight(new Date(2026, 11, 12, 18, 0))).toBe(8)
  })

  test('is unlit outside the festival unless preview is on', () => {
    expect(getHanukkahNight(new Date(2026, 6, 1, 12, 0))).toBe(0)
    expect(
      getHanukkahNight(new Date(2026, 6, 1, 12, 0), { preview: true }),
    ).toBe(8)
  })
})

describe('isHanukkahBranchLit', () => {
  test('adds candles from the right', () => {
    expect(isHanukkahBranchLit(7, 1)).toBe(true)
    expect(isHanukkahBranchLit(6, 1)).toBe(false)
    expect(isHanukkahBranchLit(6, 2)).toBe(true)
    expect(isHanukkahBranchLit(0, 7)).toBe(false)
    expect(isHanukkahBranchLit(0, 8)).toBe(true)
  })

  test('lights nothing before night 1', () => {
    expect(isHanukkahBranchLit(7, 0)).toBe(false)
  })
})
