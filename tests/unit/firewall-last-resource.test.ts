import { describe, expect, test } from 'bun:test'
import {
  firewallListSearch,
  parseFirewallListSearch,
  resolveFirewallListSearch,
} from '@/lib/firewall/conditions'
import {
  firewallLastResourcesEqual,
  getFirewallLastResourceKey,
  getFirewallLastResourceRedirectSearch,
  mergeFirewallLastResourceIntoPrefs,
  normalizeFirewallLastResource,
  parseFirewallLastResource,
} from '@/lib/firewall/last-resource'

describe('parseFirewallListSearch', () => {
  test('returns undefined when the URL does not name a resource', () => {
    expect(parseFirewallListSearch({})).toBeUndefined()
    expect(parseFirewallListSearch({ resourceType: 'functions' })).toBeUndefined()
  })

  test('accepts API and complete function or site selections', () => {
    expect(parseFirewallListSearch({ resourceType: 'api' })).toEqual({
      resourceType: 'api',
    })
    expect(
      parseFirewallListSearch({
        resourceType: 'sites',
        resourceId: 'site_123',
      }),
    ).toEqual({ resourceType: 'sites', resourceId: 'site_123' })
  })
})

describe('resolveFirewallListSearch', () => {
  test('defaults missing or incomplete search to API', () => {
    expect(resolveFirewallListSearch({})).toEqual({ resourceType: 'api' })
    expect(
      resolveFirewallListSearch({ resourceType: 'functions' }),
    ).toEqual({ resourceType: 'api' })
  })
})

describe('firewallListSearch', () => {
  test('omits resourceId for API', () => {
    expect(firewallListSearch({ resourceType: 'api', resourceId: 'ignored' })).toEqual({
      resourceType: 'api',
    })
  })
})

describe('firewall last resource prefs', () => {
  const projectId = 'proj_abc'

  test('round-trips a site selection as JSON on the per-project key', () => {
    const prefs = mergeFirewallLastResourceIntoPrefs(
      {},
      projectId,
      { resourceType: 'sites', resourceId: 'site_1' },
    )
    expect(prefs[getFirewallLastResourceKey(projectId)]).toBe(
      JSON.stringify({ resourceType: 'sites', resourceId: 'site_1' }),
    )
    expect(parseFirewallLastResource(prefs, projectId)).toEqual({
      resourceType: 'sites',
      resourceId: 'site_1',
    })
  })

  test('ignores incomplete function selections and oversized ids', () => {
    expect(
      normalizeFirewallLastResource({ resourceType: 'functions' }),
    ).toBeNull()
    expect(
      normalizeFirewallLastResource({
        resourceType: 'sites',
        resourceId: 'x'.repeat(129),
      }),
    ).toBeNull()
  })

  test('redirects only when the URL is empty and the last resource is not API', () => {
    const prefs = mergeFirewallLastResourceIntoPrefs(
      {},
      projectId,
      { resourceType: 'functions', resourceId: 'fn_1' },
    )
    expect(
      getFirewallLastResourceRedirectSearch(prefs, projectId, {}),
    ).toEqual({ resourceType: 'functions', resourceId: 'fn_1' })
    expect(
      getFirewallLastResourceRedirectSearch(prefs, projectId, {
        resourceType: 'api',
      }),
    ).toBeNull()
    expect(
      getFirewallLastResourceRedirectSearch(prefs, projectId, {
        resourceType: 'functions',
      }),
    ).toBeNull()
    expect(
      getFirewallLastResourceRedirectSearch(
        mergeFirewallLastResourceIntoPrefs(
          {},
          projectId,
          { resourceType: 'api' },
        ),
        projectId,
        {},
      ),
    ).toBeNull()
  })

  test('treats API selections as equal regardless of leftover resourceId', () => {
    expect(
      firewallLastResourcesEqual(
        { resourceType: 'api' },
        { resourceType: 'api' },
      ),
    ).toBe(true)
    expect(
      firewallLastResourcesEqual(
        { resourceType: 'sites', resourceId: 'a' },
        { resourceType: 'sites', resourceId: 'b' },
      ),
    ).toBe(false)
  })
})
