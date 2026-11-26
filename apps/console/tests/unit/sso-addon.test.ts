import { describe, expect, test } from 'bun:test'
import { AppwriteException, type Models } from '@appwrite.io/console'
import { isSSOAddonError, ssoAddonStatus } from '@/lib/sso-addon'

function addon(overrides: Partial<Models.Addon> = {}): Models.Addon {
  return {
    $id: 'addon-1',
    $createdAt: '',
    $updatedAt: '',
    $permissions: [],
    key: 'ssoOrg',
    resourceType: 'organization',
    resourceId: 'org-1',
    status: 'active',
    currentValue: 1,
    ...overrides,
  }
}

const SELLS = { supportedAddons: { ssoOrg: true, sso: true } }
const DOES_NOT_SELL = { supportedAddons: { ssoOrg: false, sso: false } }

describe('ssoAddonStatus', () => {
  test('is active with an active add-on, also one ending with the cycle', () => {
    expect(ssoAddonStatus(SELLS, [addon()], 'ssoOrg')).toBe('active')
    expect(ssoAddonStatus(SELLS, [addon({ nextValue: 0 })], 'ssoOrg')).toBe(
      'active',
    )
    // An add-on the plan no longer sells still counts until it ends
    expect(ssoAddonStatus(DOES_NOT_SELL, [addon()], 'ssoOrg')).toBe('active')
  })

  test('is pending while its payment waits for confirmation', () => {
    expect(
      ssoAddonStatus(SELLS, [addon({ status: 'pending' })], 'ssoOrg'),
    ).toBe('pending')
    expect(
      ssoAddonStatus(
        SELLS,
        [addon({ status: 'pending', currentValue: 0 })],
        'ssoOrg',
      ),
    ).toBe('pending')
  })

  test('is scheduled when it starts with the next cycle: no payment waits', () => {
    expect(
      ssoAddonStatus(
        SELLS,
        [addon({ currentValue: 0, nextValue: 1 })],
        'ssoOrg',
      ),
    ).toBe('scheduled')
    // Removed again before it started: it is not on, and can be enabled
    expect(
      ssoAddonStatus(
        SELLS,
        [addon({ currentValue: 0, nextValue: 0 })],
        'ssoOrg',
      ),
    ).toBe('available')
  })

  test('without the add-on, says whether the plan sells it', () => {
    expect(ssoAddonStatus(SELLS, [], 'ssoOrg')).toBe('available')
    expect(ssoAddonStatus(SELLS, undefined, 'sso')).toBe('available')
    expect(ssoAddonStatus(DOES_NOT_SELL, [], 'ssoOrg')).toBe('unavailable')
    // A plan from an API without the key, or no plan, does not sell it
    expect(
      ssoAddonStatus({ supportedAddons: { ssoOrg: true } }, [], 'sso'),
    ).toBe('unavailable')
    expect(ssoAddonStatus(null, [], 'ssoOrg')).toBe('unavailable')
  })

  test('reads only the add-on it is asked about', () => {
    const projectSSO = addon({ key: 'sso', resourceType: 'project' })
    expect(ssoAddonStatus(SELLS, [projectSSO], 'ssoOrg')).toBe('available')
    // The HIPAA BAA add-on has nothing to do with single sign-on
    const baa = addon({ key: 'baa' })
    expect(ssoAddonStatus(SELLS, [baa], 'ssoOrg')).toBe('available')
    expect(ssoAddonStatus(SELLS, [projectSSO], 'sso')).toBe('active')
    expect(
      ssoAddonStatus(SELLS, [addon({ status: 'cancelled' })], 'ssoOrg'),
    ).toBe('available')
  })
})

describe('isSSOAddonError', () => {
  test('recognises the refusal without the add-on', () => {
    expect(
      isSSOAddonError(
        new AppwriteException(
          "Single sign-on for an organization needs the Single sign-on add-on. Enable it in the organization's settings.",
          403,
          'sso_addon_required',
        ),
      ),
    ).toBe(true)
    // The plan gate's refusal is gone with it
    expect(
      isSSOAddonError(
        new AppwriteException('Forbidden', 403, 'billing_plan_not_allowed'),
      ),
    ).toBe(false)
    expect(isSSOAddonError(new Error('sso_addon_required'))).toBe(false)
  })
})
