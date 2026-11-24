/**
 * Whether a plan sells an add-on is read from its `supportedAddons`, the same
 * way for `baa`, `premiumGeoDB` and `sso`, which the pinned SDK does not know.
 */

import { describe, expect, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import {
  hasUpgradeablePlanWithAddon,
  planSupportsAddon,
} from '@/lib/billing/addons'

function plan(
  $id: string,
  order: number,
  supportedAddons: Record<string, boolean>,
): Models.BillingPlan {
  return { $id, order, supportedAddons } as unknown as Models.BillingPlan
}

describe('planSupportsAddon', () => {
  test('is true only when the plan names the add-on as supported', () => {
    const pro = plan('tier-1', 1, { baa: true, premiumGeoDB: true, sso: true })
    expect(planSupportsAddon(pro, 'baa')).toBe(true)
    expect(planSupportsAddon(pro, 'sso')).toBe(true)
    expect(
      planSupportsAddon(plan('tier-0', 0, { baa: false, sso: false }), 'sso'),
    ).toBe(false)
  })

  test('counts a plan without the key, or without a plan, as not selling it', () => {
    // An API from before the Single sign-on add-on leaves `sso` out
    expect(
      planSupportsAddon(
        plan('tier-1', 1, { baa: true, premiumGeoDB: true }),
        'sso',
      ),
    ).toBe(false)
    expect(planSupportsAddon({ supportedAddons: { sso: 'true' } }, 'sso')).toBe(
      false,
    )
    expect(planSupportsAddon({ $id: 'tier-1' }, 'baa')).toBe(false)
    expect(planSupportsAddon(null, 'baa')).toBe(false)
    expect(planSupportsAddon(undefined, 'sso')).toBe(false)
  })
})

describe('hasUpgradeablePlanWithAddon', () => {
  const free = plan('tier-0', 0, { baa: false, sso: false })
  const pro = plan('tier-1', 1, { baa: true, sso: true })
  const scale = plan('tier-2', 2, { baa: true, sso: false })

  test('finds a higher plan that sells the add-on', () => {
    const plans = { [free.$id]: free, [pro.$id]: pro, [scale.$id]: scale }
    expect(hasUpgradeablePlanWithAddon(free, plans, 'sso')).toBe(true)
    expect(hasUpgradeablePlanWithAddon(free, plans, 'baa')).toBe(true)
    expect(hasUpgradeablePlanWithAddon(pro, plans, 'sso')).toBe(false)
    expect(hasUpgradeablePlanWithAddon(pro, plans, 'baa')).toBe(true)
  })

  test('finds nothing without the current plan', () => {
    expect(hasUpgradeablePlanWithAddon(null, { [pro.$id]: pro }, 'sso')).toBe(
      false,
    )
  })
})
