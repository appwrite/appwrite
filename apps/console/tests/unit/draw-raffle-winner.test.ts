import { afterEach, describe, expect, spyOn, test } from 'bun:test'
import { drawRaffleWinnerIndex } from '@/lib/init/draw-raffle-winner'

describe('drawRaffleWinnerIndex', () => {
  const originalRandom = Math.random

  afterEach(() => {
    Math.random = originalRandom
  })

  test('returns an index inside the participant list', () => {
    for (const count of [1, 2, 3, 7, 1000]) {
      for (let i = 0; i < 200; i++) {
        const index = drawRaffleWinnerIndex(count)
        expect(Number.isInteger(index)).toBe(true)
        expect(index).toBeGreaterThanOrEqual(0)
        expect(index).toBeLessThan(count)
      }
    }
  })

  test('draws from the crypto source, not Math.random', () => {
    Math.random = () => {
      throw new Error('Math.random must not pick a giveaway winner')
    }
    const random = spyOn(crypto, 'getRandomValues')

    drawRaffleWinnerIndex(5)

    expect(random).toHaveBeenCalled()
    random.mockRestore()
  })

  test('rejects values that would bias the modulo', () => {
    const values = [0xffff_ffff, 7]
    const random = spyOn(crypto, 'getRandomValues').mockImplementation((<
      T extends ArrayBufferView | null,
    >(
      array: T,
    ): T => {
      ;(array as unknown as Uint32Array)[0] = values.shift() ?? 0
      return array
    }) as typeof crypto.getRandomValues)

    // 2^32 % 3 === 1, so 0xffffffff falls in the biased tail and is redrawn.
    expect(drawRaffleWinnerIndex(3)).toBe(1)
    expect(random).toHaveBeenCalledTimes(2)
    random.mockRestore()
  })

  test('rejects an empty raffle', () => {
    expect(() => drawRaffleWinnerIndex(0)).toThrow(RangeError)
    expect(() => drawRaffleWinnerIndex(1.5)).toThrow(RangeError)
  })
})
