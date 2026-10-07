import { describe, expect, test } from 'bun:test'
import {
  getSiteTemplateScreenshotUrl,
  normalizeSiteTemplateScreenshotUrl,
} from '@/lib/sites/site-template-wizard'

describe('normalizeSiteTemplateScreenshotUrl', () => {
  test('rewrites bundled screenshot URLs to a console-origin path', () => {
    expect(
      normalizeSiteTemplateScreenshotUrl(
        'http://localhost/images/sites/templates/template-for-blog-dark.png',
      ),
    ).toBe('/images/sites/templates/template-for-blog-dark.png')
    expect(
      normalizeSiteTemplateScreenshotUrl(
        'https://cloud.appwrite.io/images/sites/templates/vitepress-light.png',
      ),
    ).toBe('/images/sites/templates/vitepress-light.png')
  })

  test('leaves other URLs untouched', () => {
    expect(
      normalizeSiteTemplateScreenshotUrl('https://example.com/shot.png'),
    ).toBe('https://example.com/shot.png')
    expect(normalizeSiteTemplateScreenshotUrl('not a url')).toBe('not a url')
    expect(normalizeSiteTemplateScreenshotUrl(undefined)).toBeUndefined()
    expect(normalizeSiteTemplateScreenshotUrl('')).toBe('')
  })
})

describe('getSiteTemplateScreenshotUrl', () => {
  const template = {
    screenshotDark:
      'http://localhost/images/sites/templates/template-for-blog-dark.png',
    screenshotLight:
      'http://localhost/images/sites/templates/template-for-blog-light.png',
  }

  test('picks the theme variant and normalizes it', () => {
    expect(getSiteTemplateScreenshotUrl(template, true)).toBe(
      '/images/sites/templates/template-for-blog-dark.png',
    )
    expect(getSiteTemplateScreenshotUrl(template, false)).toBe(
      '/images/sites/templates/template-for-blog-light.png',
    )
  })
})
