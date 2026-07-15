import { describe, expect, it } from 'vitest'
import {
  isLegacyConsolePath,
  rewriteLegacyConsolePath,
} from '@/lib/legacy-console-path'

describe('rewriteLegacyConsolePath', () => {
  it('maps bare /console to root', () => {
    expect(rewriteLegacyConsolePath('/console')).toBe('/')
  })

  it('keeps auth paths after stripping /console', () => {
    expect(rewriteLegacyConsolePath('/console/auth/magic-url')).toBe(
      '/auth/magic-url',
    )
    expect(rewriteLegacyConsolePath('/console/auth/oauth2/success')).toBe(
      '/auth/oauth2/success',
    )
  })

  it('rewrites project-{region}-{id} sites deployment deep links', () => {
    expect(
      rewriteLegacyConsolePath(
        '/console/project-fra-itznotabug/sites/site-lede/deployments/deployment-6a573b86db1f9d7712d2',
      ),
    ).toBe('/projects/itznotabug/sites/lede/deployments/6a573b86db1f9d7712d2')
  })

  it('rewrites function deployment sibling segments to /deployments/{id}', () => {
    expect(
      rewriteLegacyConsolePath(
        '/console/project-nyc-proj1/functions/function-fn1/deployment-dep1',
      ),
    ).toBe('/projects/proj1/functions/fn1/deployments/dep1')
  })

  it('rewrites organization-{id} containers', () => {
    expect(
      rewriteLegacyConsolePath('/console/organization-team1/settings/members'),
    ).toBe('/organizations/team1/settings/members')
  })

  it('drops webhook ids under settings/webhooks', () => {
    expect(
      rewriteLegacyConsolePath(
        '/console/project-fra-project-1/settings/webhooks/webhook-1',
      ),
    ).toBe('/projects/project-1/settings/webhooks')
  })

  it('rewrites already-stripped project paths from incomplete redirects', () => {
    expect(
      rewriteLegacyConsolePath(
        '/project-fra-itznotabug/sites/site-lede/deployments/deployment-6a573b86db1f9d7712d2',
      ),
    ).toBe('/projects/itznotabug/sites/lede/deployments/6a573b86db1f9d7712d2')
  })

  it('preserves git authorize paths after stripping /console', () => {
    expect(
      rewriteLegacyConsolePath('/console/git/authorize-contributor'),
    ).toBe('/git/authorize-contributor')
  })
})

describe('isLegacyConsolePath', () => {
  it('detects /console paths', () => {
    expect(isLegacyConsolePath('/console')).toBe(true)
    expect(isLegacyConsolePath('/console/auth/magic-url')).toBe(true)
  })

  it('detects bare legacy project/organization containers', () => {
    expect(isLegacyConsolePath('/project-fra-abc/sites/site-x')).toBe(true)
    expect(isLegacyConsolePath('/organization-team1')).toBe(true)
  })

  it('ignores modern vibes paths', () => {
    expect(isLegacyConsolePath('/projects/abc/sites/x')).toBe(false)
    expect(isLegacyConsolePath('/auth/magic-url')).toBe(false)
    expect(isLegacyConsolePath('/')).toBe(false)
  })
})
