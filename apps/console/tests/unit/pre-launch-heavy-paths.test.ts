import { describe, expect, test } from 'bun:test'
import {
  isPreLaunchAllowedPath,
  isPreLaunchHeavyContentPath,
} from '@/lib/pre-launch'

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
})
