import { describe, expect, test } from 'bun:test'
import {
  getAccountMenuLinks,
  getActiveAccountMenuSection,
} from '@/lib/account-menu-section'

describe('account menu section', () => {
  test('matches the marketing home page', () => {
    expect(getActiveAccountMenuSection('/home')).toBe('home')
    expect(getActiveAccountMenuSection('/home/')).toBe('home')
    expect(getActiveAccountMenuSection('/home#pricing')).toBe('home')
  })

  test('matches docs pages and their children', () => {
    expect(getActiveAccountMenuSection('/docs')).toBe('docs')
    expect(getActiveAccountMenuSection('/docs/quick-starts/react')).toBe('docs')
    expect(getActiveAccountMenuSection('/docs/references?platform=web')).toBe(
      'docs',
    )
  })

  test('matches changelog pages and their children', () => {
    expect(getActiveAccountMenuSection('/changelog')).toBe('changelog')
    expect(getActiveAccountMenuSection('/changelog/entry/some-entry')).toBe(
      'changelog',
    )
  })

  test('leaves console and unrelated marketing pages unmatched', () => {
    expect(getActiveAccountMenuSection('/')).toBeNull()
    expect(getActiveAccountMenuSection('/organizations/abc')).toBeNull()
    expect(getActiveAccountMenuSection('/account')).toBeNull()
    expect(getActiveAccountMenuSection('/pricing')).toBeNull()
  })

  test('does not match paths that merely share a prefix', () => {
    expect(getActiveAccountMenuSection('/docs-team')).toBeNull()
    expect(getActiveAccountMenuSection('/homepage')).toBeNull()
    expect(getActiveAccountMenuSection('/changelog.md')).toBeNull()
  })
})

describe('account menu links', () => {
  const cloudMarketing = { showMarketingNav: true, isCloud: true }

  test('the marketing home offers the console and docs, not itself', () => {
    expect(
      getAccountMenuLinks({ pathname: '/home', ...cloudMarketing }),
    ).toEqual(['console', 'docs', 'changelog', 'oldConsole'])
  })

  test('docs offers home and the console, not itself', () => {
    expect(
      getAccountMenuLinks({
        pathname: '/docs/quick-starts/react',
        ...cloudMarketing,
      }),
    ).toEqual(['home', 'console', 'changelog', 'oldConsole'])
  })

  test('the changelog offers home, the console and docs, not itself', () => {
    expect(
      getAccountMenuLinks({ pathname: '/changelog', ...cloudMarketing }),
    ).toEqual(['home', 'console', 'docs', 'oldConsole'])
  })

  test('other marketing pages keep every destination', () => {
    expect(
      getAccountMenuLinks({ pathname: '/pricing', ...cloudMarketing }),
    ).toEqual(['home', 'console', 'docs', 'changelog', 'oldConsole'])
  })

  test('console pages drop the console entry, as before', () => {
    expect(
      getAccountMenuLinks({
        pathname: '/organizations/abc',
        showMarketingNav: false,
        isCloud: true,
      }),
    ).toEqual(['home', 'docs', 'changelog', 'oldConsole'])
  })

  test('self-hosted hides the legacy console link', () => {
    expect(
      getAccountMenuLinks({
        pathname: '/organizations/abc',
        showMarketingNav: false,
        isCloud: false,
      }),
    ).toEqual(['home', 'docs', 'changelog'])
  })
})
