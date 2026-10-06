import { describe, expect, test } from 'bun:test'
import {
  isInitTicketOgPath,
  isInitTicketSharePath,
} from '@/lib/init/init-surface'
import { resolveInitHref, resolvePreLaunchPublicHref } from '@/lib/init/links'

describe('init ticket share paths', () => {
  test('recognizes personal ticket pages and OG images', () => {
    expect(isInitTicketSharePath('/init/abc123')).toBe(true)
    expect(isInitTicketSharePath('/init/abc123/')).toBe(true)
    expect(isInitTicketOgPath('/init/abc123/og.png')).toBe(true)
  })

  test('rejects reserved Init segments and the landing page', () => {
    expect(isInitTicketSharePath('/init')).toBe(false)
    expect(isInitTicketSharePath('/init/keynote')).toBe(false)
    expect(isInitTicketSharePath('/init/ticket')).toBe(false)
    expect(isInitTicketSharePath('/init/calendar')).toBe(false)
    expect(isInitTicketSharePath('/init/ticket/init-july-2026')).toBe(false)
  })
})

describe('resolvePreLaunchPublicHref', () => {
  test('keeps Init landing, ticket share, hash, Discord, and auth links', () => {
    expect(resolvePreLaunchPublicHref('/init')).toEqual({
      href: '/init',
      external: false,
    })
    expect(resolvePreLaunchPublicHref('/init/ticket-id-1')).toEqual({
      href: '/init/ticket-id-1',
      external: false,
    })
    expect(resolvePreLaunchPublicHref('#ticket')).toEqual({
      href: '#ticket',
      external: false,
    })
    expect(resolvePreLaunchPublicHref('/sign-up')).toEqual({
      href: '/sign-up',
      external: false,
    })
    expect(resolvePreLaunchPublicHref('/discord')).toEqual({
      href: '/discord',
      external: false,
    })
  })

  test('rewrites public site links to appwrite.io', () => {
    expect(resolvePreLaunchPublicHref('/docs/products/databases')).toEqual({
      href: 'https://appwrite.io/docs/products/databases',
      external: true,
    })
    expect(
      resolvePreLaunchPublicHref('/blog/post/announcing-console-terminal'),
    ).toEqual({
      href: 'https://appwrite.io/blog/post/announcing-console-terminal',
      external: true,
    })
    expect(resolvePreLaunchPublicHref('/home')).toEqual({
      href: 'https://appwrite.io/',
      external: true,
    })
    expect(resolvePreLaunchPublicHref('/')).toEqual({
      href: 'https://appwrite.io/',
      external: true,
    })
  })

  test('hides unfinished Init routes and console-only paths', () => {
    expect(resolvePreLaunchPublicHref('/init/keynote')).toBeNull()
    expect(resolvePreLaunchPublicHref('/projects/abc')).toBeNull()
    expect(resolvePreLaunchPublicHref('/organizations/abc')).toBeNull()
  })

  test('leaves already-external URLs unchanged', () => {
    expect(resolvePreLaunchPublicHref('https://reddit.com/r/appwrite')).toEqual(
      {
        href: 'https://reddit.com/r/appwrite',
        external: true,
      },
    )
  })
})

describe('resolveInitHref', () => {
  test('passes through in-app links when pre-launch is off', () => {
    expect(resolveInitHref('/home', false)).toEqual({
      href: '/home',
      external: false,
    })
    expect(resolveInitHref('/init/keynote', false)).toEqual({
      href: '/init/keynote',
      external: false,
    })
  })
})
