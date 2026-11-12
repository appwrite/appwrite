/**
 * `isSelfServiceProPlan` gates every in-console Enterprise prompt. A false
 * positive sends Free, Start, or self-hosted users to sales with support
 * questions; a false negative hides the prompts from the Pro orgs they target.
 */

import { describe, expect, test } from 'bun:test'
import { isSelfServiceProPlan } from '@/lib/utils/plan-filter'

describe('isSelfServiceProPlan', () => {
  test.each([
    ['tier-1', true],
    ['tier-0', false],
    ['tier-1-1', false],
    ['tier-2', false],
    ['auto-1', false],
    ['enterprise', false],
  ])('plan %s returns %p', (planId, expected) => {
    expect(isSelfServiceProPlan({ $id: planId, selfService: true })).toBe(
      expected,
    )
  })

  test('treats a missing selfService flag as self-serve', () => {
    expect(isSelfServiceProPlan({ $id: 'tier-1' })).toBe(true)
  })

  test('excludes a Pro plan that is not self-serve', () => {
    expect(isSelfServiceProPlan({ $id: 'tier-1', selfService: false })).toBe(
      false,
    )
  })

  test('excludes the self-hosted plan', () => {
    expect(
      isSelfServiceProPlan({ $id: 'self-hosted', selfService: false }),
    ).toBe(false)
  })

  test('excludes a plan that has not loaded', () => {
    expect(isSelfServiceProPlan(undefined)).toBe(false)
    expect(isSelfServiceProPlan(null)).toBe(false)
  })
})
