import { describe, expect, test } from 'bun:test'
import {
  isPreLaunchAllowedPath,
  isPreLaunchHeavyContentPath,
} from '@/lib/pre-launch'
import { MARKDOWN_TWIN_PAGES } from '@/lib/seo/markdown-twins'

describe('isPreLaunchHeavyContentPath', () => {
  test('blocks marketing and content routes', () => {
    expect(isPreLaunchHeavyContentPath('/blog')).toBe(true)
    expect(isPreLaunchHeavyContentPath('/blog/post/foo')).toBe(true)
    expect(isPreLaunchHeavyContentPath('/blog/rss.xml')).toBe(true)
    expect(isPreLaunchHeavyContentPath('/changelog/rss.xml')).toBe(true)
    expect(isPreLaunchHeavyContentPath('/sitemap.xml')).toBe(true)
    expect(isPreLaunchHeavyContentPath('/sitemap/news.xml')).toBe(true)
    expect(isPreLaunchHeavyContentPath('/sitemap/pages.xml')).toBe(true)
    expect(isPreLaunchHeavyContentPath('/docs/quick-start')).toBe(true)
    expect(isPreLaunchHeavyContentPath('/og/image.png')).toBe(true)
    expect(isPreLaunchHeavyContentPath('/llms.txt')).toBe(true)
  })

  test('allows init, health, and assets', () => {
    expect(isPreLaunchHeavyContentPath('/health')).toBe(false)
    expect(isPreLaunchHeavyContentPath('/assets/index-abc.js')).toBe(false)
    expect(isPreLaunchHeavyContentPath('/init/ticket-123/og.png')).toBe(false)
    expect(isPreLaunchAllowedPath('/init')).toBe(true)
    expect(isPreLaunchAllowedPath('/init/ticket-123')).toBe(true)
    expect(isPreLaunchAllowedPath('/sign-in')).toBe(true)
    expect(isPreLaunchAllowedPath('/setup.md')).toBe(true)
    expect(isPreLaunchHeavyContentPath('/setup.md')).toBe(false)
  })

  test('locks every markdown twin with its page', () => {
    expect(isPreLaunchHeavyContentPath('/company.md')).toBe(true)
    expect(isPreLaunchHeavyContentPath('/startups.md')).toBe(true)
    expect(isPreLaunchHeavyContentPath('/products/auth.md')).toBe(true)
    for (const page of MARKDOWN_TWIN_PAGES) {
      expect({
        twin: `${page}.md`,
        locked: isPreLaunchHeavyContentPath(`${page}.md`),
      }).toEqual({
        twin: `${page}.md`,
        locked: true,
      })
    }
  })

  test('keeps the legal pages reachable, but not their twins', () => {
    expect(isPreLaunchAllowedPath('/privacy')).toBe(true)
    expect(isPreLaunchAllowedPath('/privacy.md')).toBe(false)
    expect(isPreLaunchHeavyContentPath('/privacy.md')).toBe(true)
  })
})
