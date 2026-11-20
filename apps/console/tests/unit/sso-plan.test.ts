import { describe, expect, test } from 'bun:test'
import { AppwriteException } from '@appwrite.io/console'
import { isSSOPlanError, planSupportsSSO } from '@/lib/sso-plan'

describe('planSupportsSSO', () => {
  test('is true only when the plan says so', () => {
    expect(planSupportsSSO({ $id: 'tier-1', supportsSSO: true })).toBe(true)
    expect(planSupportsSSO({ $id: 'tier-0', supportsSSO: false })).toBe(false)
  })

  test('counts a plan from an older API, without the flag, as not supported', () => {
    expect(planSupportsSSO({ $id: 'tier-1', name: 'Pro' })).toBe(false)
    expect(planSupportsSSO({ supportsSSO: 'true' })).toBe(false)
    expect(planSupportsSSO(undefined)).toBe(false)
    expect(planSupportsSSO(null)).toBe(false)
  })
})

describe('isSSOPlanError', () => {
  test('recognises the refusal for the plan', () => {
    expect(
      isSSOPlanError(
        new AppwriteException(
          'Single sign-on requires a Pro plan or higher.',
          403,
          'billing_plan_not_allowed',
        ),
      ),
    ).toBe(true)
    expect(
      isSSOPlanError(
        new AppwriteException('Forbidden', 403, 'general_unauthorized_scope'),
      ),
    ).toBe(false)
    expect(isSSOPlanError(new Error('billing_plan_not_allowed'))).toBe(false)
  })
})
