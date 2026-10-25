const UINT32_RANGE = 0x1_0000_0000

/** Uniform, unpredictable index in [0, count) for picking a giveaway winner. */
export function drawRaffleWinnerIndex(count: number): number {
  if (!Number.isInteger(count) || count < 1) {
    throw new RangeError('A raffle needs at least one participant')
  }

  // Values past the last whole multiple of count would bias the modulo toward low indexes.
  const limit = UINT32_RANGE - (UINT32_RANGE % count)
  const value = new Uint32Array(1)
  do {
    crypto.getRandomValues(value)
  } while (value[0] >= limit)

  return value[0] % count
}
