import { describe, expect, test } from 'bun:test'
import {
  assetCss,
  assetSrcSet,
  assetUrl,
  getAssetBase,
} from '../../src/lib/asset-url'
import {
  CDN_PRERENDER_ORIGIN,
  RUNTIME_CONFIG_PLACEHOLDER,
  injectRuntimeConfigIntoHtml,
  normalizeCdnOrigin,
  readRuntimeConfigFromEnv,
  serializeRuntimeConfig,
  stampAssetOrigin,
} from '../../src/lib/runtime-config-shared'

describe('runtime CDN assets', () => {
  test('accepts origins but rejects paths, credentials and non-HTTP schemes', () => {
    expect(normalizeCdnOrigin('https://cdn.appwrite.io/')).toBe(
      'https://cdn.appwrite.io',
    )
    for (const origin of [
      'https://cdn.appwrite.io/path',
      'https://user:pass@cdn.appwrite.io',
      'javascript:alert(1)',
      'https://cdn.appwrite.io/?q=1',
    ]) {
      expect(() => normalizeCdnOrigin(origin)).toThrow()
    }
  })
  test('the same prerendered HTML uses either environment or local assets', () => {
    const html = `<img src="${CDN_PRERENDER_ORIGIN}/builds/build-42/images/test.avif"><script>window.__APP_CONFIG__=${RUNTIME_CONFIG_PLACEHOLDER};</script>`
    for (const origin of [
      'https://cdn.staging.appwrite.io',
      'https://cdn.appwrite.io',
      '',
    ]) {
      const result = injectRuntimeConfigIntoHtml(
        html,
        serializeRuntimeConfig(
          readRuntimeConfigFromEnv({ CDN_ORIGIN: origin }),
        ),
      )
      expect(result).toContain(
        `src="${origin ? origin + '/builds/build-42' : ''}/images/test.avif"`,
      )
      expect(result).not.toContain(CDN_PRERENDER_ORIGIN)
      expect(result).not.toContain(RUNTIME_CONFIG_PLACEHOLDER)
    }
  })
  test('substitution preserves the build identity across rollbacks', () => {
    const html = `<script src="${CDN_PRERENDER_ORIGIN}/builds/old-1/assets/a.js"></script>`
    expect(stampAssetOrigin(html, 'https://cdn.appwrite.io')).toContain(
      '/builds/old-1/assets/a.js',
    )
  })
  test('prerender URLs use the placeholder and public references are idempotent', () => {
    const previous = process.env.TSS_PRERENDERING
    process.env.TSS_PRERENDERING = 'true'
    try {
      const base = getAssetBase()
      expect(base).toBe(`${CDN_PRERENDER_ORIGIN}/builds/local`)
      expect(assetUrl('/images/test.avif')).toBe(`${base}/images/test.avif`)
      expect(assetUrl(assetUrl('/images/test.avif'))).toBe(
        `${base}/images/test.avif`,
      )
      expect(assetCss('url("/icons/react.svg")')).toBe(
        `url("${base}/icons/react.svg")`,
      )
      expect(
        assetSrcSet(
          '/images/a.avif 1x, https://external.test/images/b.avif 2x',
        ),
      ).toBe(`${base}/images/a.avif 1x, https://external.test/images/b.avif 2x`)
      for (const value of [
        '/projects/p1',
        '/assets',
        '/api/avatar',
        'https://external.test/images/a.png',
        '//external.test/a.png',
        'data:image/png;base64,abc',
        null,
        undefined,
      ]) {
        expect(assetUrl(value)).toBe(value)
      }
      expect(assetCss('url(#gradient)')).toBe('url(#gradient)')
      expect(assetCss('url(https://external.test/images/a.png)')).toBe(
        'url(https://external.test/images/a.png)',
      )
    } finally {
      if (previous === undefined) delete process.env.TSS_PRERENDERING
      else process.env.TSS_PRERENDERING = previous
    }
  })
})
