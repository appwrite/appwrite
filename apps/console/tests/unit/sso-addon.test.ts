import { describe, expect, test } from 'bun:test'
import { AppwriteException, type Models } from '@appwrite.io/console'
import { isSSOAddonError, ssoAddonStatus } from '@/lib/sso-addon'

function addon(overrides: Partial<Models.Addon> = {}): Models.Addon {
  return {
    $id: 'addon-1',
    $createdAt: '',
    $updatedAt: '',
    $permissions: [],
    key: 'baa',
    resourceType: 'organization',
    resourceId: 'org-1',
    status: 'active',
    currentValue: 1,
    ...overrides,
  }
}

const SELLS = { supportedAddons: { baa: true, sso: true } }
const DOES_NOT_SELL = { supportedAddons: { baa: false, sso: false } }

describe('ssoAddonStatus', () => {
  test('is active with an active add-on, also one ending with the cycle', () => {
    expect(ssoAddonStatus(SELLS, [addon()], 'baa')).toBe('active')
    expect(ssoAddonStatus(SELLS, [addon({ nextValue: 0 })], 'baa')).toBe(
      'active',
    )
    // An add-on the plan no longer sells still counts until it ends
    expect(ssoAddonStatus(DOES_NOT_SELL, [addon()], 'baa')).toBe('active')
  })

  test('is pending until the add-on counts: its payment, or the next cycle', () => {
    expect(
      ssoAddonStatus(SELLS, [addon({ currentValue: 0, nextValue: 1 })], 'baa'),
    ).toBe('pending')
    expect(ssoAddonStatus(SELLS, [addon({ status: 'pending' })], 'baa')).toBe(
      'pending',
    )
  })

  test('without the add-on, says whether the plan sells it', () => {
    expect(ssoAddonStatus(SELLS, [], 'baa')).toBe('available')
    expect(ssoAddonStatus(SELLS, undefined, 'sso')).toBe('available')
    expect(ssoAddonStatus(DOES_NOT_SELL, [], 'baa')).toBe('unavailable')
    // A plan from an API without the key, or no plan, does not sell it
    expect(ssoAddonStatus({ supportedAddons: { baa: true } }, [], 'sso')).toBe(
      'unavailable',
    )
    expect(ssoAddonStatus(null, [], 'baa')).toBe('unavailable')
  })

  test('reads only the add-on it is asked about', () => {
    const projectSSO = addon({ key: 'sso', resourceType: 'project' })
    expect(ssoAddonStatus(SELLS, [projectSSO], 'baa')).toBe('available')
    expect(ssoAddonStatus(SELLS, [projectSSO], 'sso')).toBe('active')
    expect(ssoAddonStatus(SELLS, [addon({ status: 'cancelled' })], 'baa')).toBe(
      'available',
    )
  })
})

describe('isSSOAddonError', () => {
  test('recognises the refusal without the add-on', () => {
    expect(
      isSSOAddonError(
        new AppwriteException(
          "Single sign-on is part of the HIPAA BAA add-on. Enable it in the organization's settings.",
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
