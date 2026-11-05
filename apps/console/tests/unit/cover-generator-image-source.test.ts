import { describe, expect, test } from 'bun:test'
import { resolveCoverImageHref } from '@/lib/cover-generator/brand-background'
import { readCoverPublicAssetBuffer } from '@/lib/cover-generator/public-assets'
import { runWithCoverRenderContext } from '@/lib/cover-generator/render-context'

const SITE = 'https://appwrite.io'

describe('resolveCoverImageHref', () => {
  test('passes through data: URIs', async () => {
    const uri = 'data:image/png;base64,AAAA'
    expect(await resolveCoverImageHref(uri)).toBe(uri)
  })

  test('allows a remote URL only on the site origin', async () => {
    await runWithCoverRenderContext(SITE, async () => {
      expect(await resolveCoverImageHref(`${SITE}/images/diagram.png`)).toBe(
        `${SITE}/images/diagram.png`,
      )
    })
  })

  test('refuses remote URLs off the site origin (SSRF)', async () => {
    await runWithCoverRenderContext(SITE, async () => {
      for (const value of [
        'http://169.254.169.254/latest/meta-data/',
        'http://127.0.0.1:9229/json',
        'https://evil.example/cover.png',
        'https://appwrite.io.evil.example/cover.png', // lookalike host
        'http://appwrite.io/cover.png', // scheme mismatch
      ]) {
        expect(await resolveCoverImageHref(value)).toBeNull()
      }
    })
  })
})

describe('readCoverPublicAssetBuffer', () => {
  test('rejects path traversal before reading or fetching', async () => {
    for (const value of [
      '/../../../../etc/passwd',
      '/assets/../../secret',
      '/../secret.key',
    ]) {
      expect(await readCoverPublicAssetBuffer(value)).toBeNull()
    }
  })
})
