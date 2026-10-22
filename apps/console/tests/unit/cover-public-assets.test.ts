import { describe, expect, test } from 'bun:test'
import { readCoverPublicAssetBuffer } from '@/lib/cover-generator/public-assets'

describe('readCoverPublicAssetBuffer', () => {
  test('reads bundled public assets', async () => {
    const buffer = await readCoverPublicAssetBuffer('/icons/akamai.svg')
    expect(buffer?.toString('utf-8')).toContain('<svg')
  })

  test.each(['/../package.json', '/icons/../../package.json'])(
    'does not read outside the asset directory: %s',
    async (path) => {
      expect(await readCoverPublicAssetBuffer(path)).toBeNull()
    },
  )
})
