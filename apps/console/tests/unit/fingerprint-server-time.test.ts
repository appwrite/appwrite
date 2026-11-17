import { afterEach, beforeAll, describe, expect, spyOn, test } from 'bun:test'
import {
  ensureFingerprintServerTimeSynced,
  generateFingerprintToken,
  resetFingerprintCache,
} from '@/lib/fingerprint'

const SERVER_SECS = 1_800_000_000
const ENDPOINT = 'https://cloud.example.com/v1'

let serverSecs = SERVER_SECS
let fetchCalls = 0

beforeAll(() => {
  Object.assign(globalThis, {
    window: globalThis,
    document: { createElement: () => ({}) },
    screen: { width: 1920, height: 1080, colorDepth: 24, pixelDepth: 24 },
  })
  globalThis.fetch = (async () => {
    fetchCalls++
    return new Response(null, {
      headers: { Date: new Date(serverSecs * 1000).toUTCString() },
    })
  }) as unknown as typeof fetch
})

afterEach(() => {
  resetFingerprintCache()
  fetchCalls = 0
  serverSecs = SERVER_SECS
})

async function tokenTimestamp(): Promise<number> {
  const token = await generateFingerprintToken()
  return JSON.parse(atob(token.split('.')[0])).timestamp
}

describe('fingerprint server time', () => {
  test('signs with server time even when the local clock is off', async () => {
    const now = spyOn(Date, 'now').mockReturnValue(1_000_000_000_000)
    await ensureFingerprintServerTimeSynced(ENDPOINT, 'console')
    expect(await tokenTimestamp()).toBe(SERVER_SECS)
    now.mockRestore()
  })

  test('re-syncs after the wall clock jumps, as a reload would', async () => {
    let local = 1_000_000_000_000
    const now = spyOn(Date, 'now').mockImplementation(() => local)
    await ensureFingerprintServerTimeSynced(ENDPOINT, 'console')

    // NTP correction or sleep/wake skew: local clock jumps 20 minutes ahead
    // while only 1 real minute passes on the server.
    local += 20 * 60 * 1000
    serverSecs += 60
    await ensureFingerprintServerTimeSynced(ENDPOINT, 'console')
    expect(fetchCalls).toBe(2)
    expect(await tokenTimestamp()).toBe(serverSecs)

    // Clock jumps backwards.
    local -= 30 * 60 * 1000
    await ensureFingerprintServerTimeSynced(ENDPOINT, 'console')
    expect(fetchCalls).toBe(3)
    expect(await tokenTimestamp()).toBe(serverSecs)
    now.mockRestore()
  })

  test('keeps a fresh sync without refetching', async () => {
    let local = 1_000_000_000_000
    const now = spyOn(Date, 'now').mockImplementation(() => local)
    await ensureFingerprintServerTimeSynced(ENDPOINT, 'console')
    local += 60 * 1000
    await ensureFingerprintServerTimeSynced(ENDPOINT, 'console')
    expect(fetchCalls).toBe(1)
    expect(await tokenTimestamp()).toBe(SERVER_SECS + 60)
    now.mockRestore()
  })
})
