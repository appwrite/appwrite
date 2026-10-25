import { afterEach, describe, expect, test } from 'bun:test'
import { readCoverPublicAssetBuffer } from '@/lib/cover-generator/public-assets'

const originalFetch = globalThis.fetch

afterEach(() => {
  globalThis.fetch = originalFetch
})

describe('readCoverPublicAssetBuffer', () => {
  test('reads bundled assets', async () => {
    const svg = await readCoverPublicAssetBuffer('/icons/actix.svg')
    expect(svg?.toString('utf8')).toContain('<svg')
  })

  test('does not read or fetch paths outside the asset roots', async () => {
    const requested: string[] = []
    globalThis.fetch = (async (input: RequestInfo | URL) => {
      requested.push(String(input))
      return new Response('unexpected')
    }) as typeof fetch

    for (const path of [
      '/../package.json',
      '/icons/../../package.json',
      '/../../../../../../etc/hosts',
      '../tsconfig.json',
    ]) {
      expect(await readCoverPublicAssetBuffer(path)).toBeNull()
    }
    expect(requested).toEqual([])
  })
})
