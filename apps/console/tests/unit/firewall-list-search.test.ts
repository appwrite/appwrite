import { describe, expect, test } from 'bun:test'
import {
  firewallListSearch,
  firewallTabPath,
  parseFirewallListSearch,
  resolveFirewallListSearch,
} from '@/lib/firewall/conditions'

describe('parseFirewallListSearch', () => {
  test('returns undefined when the URL does not name a resource', () => {
    expect(parseFirewallListSearch({})).toBeUndefined()
    expect(
      parseFirewallListSearch({ resourceType: 'functions' }),
    ).toBeUndefined()
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
    expect(resolveFirewallListSearch({ resourceType: 'functions' })).toEqual({
      resourceType: 'api',
    })
  })
})

describe('firewallListSearch', () => {
  test('omits resourceId for API', () => {
    expect(
      firewallListSearch({ resourceType: 'api', resourceId: 'ignored' }),
    ).toEqual({
      resourceType: 'api',
    })
  })
})

describe('firewallTabPath', () => {
  test('opens the Firewall tab of the selected function or site', () => {
    expect(
      firewallTabPath('project_1', {
        resourceType: 'functions',
        resourceId: 'function_1',
      }),
    ).toBe('/projects/project_1/functions/function_1/firewall')
    expect(
      firewallTabPath('project_1', {
        resourceType: 'sites',
        resourceId: ' site_1 ',
      }),
    ).toBe('/projects/project_1/sites/site_1/firewall')
  })

  test('opens project settings for the API or an incomplete selection', () => {
    expect(firewallTabPath('project_1', { resourceType: 'api' })).toBe(
      '/projects/project_1/settings/firewall',
    )
    expect(firewallTabPath('project_1', { resourceType: 'sites' })).toBe(
      '/projects/project_1/settings/firewall',
    )
  })
})
