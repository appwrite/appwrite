import { beforeAll, describe, expect, test } from 'bun:test'
import { registerDom, unregisterDom } from './dom.ts'

const TIMER_DELAY_MS = 5
const SETTLE_MS = 30

describe('unit DOM teardown', () => {
  beforeAll(async () => {
    registerDom()
    await unregisterDom()
  })

  test('drops the timers a DOM test left pending', async () => {
    registerDom()
    const fired: string[] = []
    setTimeout(() => fired.push('timeout'), TIMER_DELAY_MS)
    setInterval(() => fired.push('interval'), TIMER_DELAY_MS)

    await unregisterDom()
    await Bun.sleep(SETTLE_MS)

    expect(fired).toEqual([])
  })

  test('keeps timers running while the DOM is registered', async () => {
    registerDom()
    const fired: string[] = []
    const interval = setInterval(() => fired.push('interval'), TIMER_DELAY_MS)
    setTimeout(() => fired.push('timeout'), TIMER_DELAY_MS)

    await Bun.sleep(SETTLE_MS)
    clearInterval(interval)
    await unregisterDom()

    expect(fired).toContain('timeout')
    expect(fired).toContain('interval')
  })
})
