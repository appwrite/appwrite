import { describe, expect, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import {
  getReachedPlanUsageLimitKeys,
  isPlanUsageLimitReached,
  isProjectLockedByPlanUsage,
  isReviewCreditsLimitReached,
} from '@/lib/billing/billing-limits'
import { getUsagePlanOverages } from '@/lib/billing/usage-plan-overages'
import { buildOrganizationUsageCategoriesFromAggregation } from '@/lib/billing/project-breakdown-resources'

describe('review credits billing limits', () => {
  test('spent review credits never suspend the project', () => {
    const billingLimits = { reviewCredits: 120 }

    expect(getReachedPlanUsageLimitKeys(billingLimits)).toEqual([])
    expect(isPlanUsageLimitReached({ billingLimits })).toBe(false)
    expect(isProjectLockedByPlanUsage({ billingLimits })).toBe(false)
    expect(isReviewCreditsLimitReached(billingLimits)).toBe(true)
  })

  test('other limits still suspend alongside review credits', () => {
    const billingLimits = { reviewCredits: 120, GBHours: 101 }

    expect(getReachedPlanUsageLimitKeys(billingLimits)).toEqual(['GBHours'])
    expect(isProjectLockedByPlanUsage({ billingLimits })).toBe(true)
  })

  test('credits under the allowance are not a limit', () => {
    expect(isReviewCreditsLimitReached({ reviewCredits: 99 })).toBe(false)
    expect(isReviewCreditsLimitReached(null)).toBe(false)
  })
})

describe('review credits usage', () => {
  const plan = { reviewCredits: 20 } as unknown as Models.BillingPlan

  test('credits past the allowance are an overage row', () => {
    const usage = {
      reviewCreditsTotal: 40,
    } as unknown as Models.UsageOrganization

    expect(getUsagePlanOverages(usage, plan)).toEqual([
      {
        id: 'reviewCredits',
        name: 'Code review credits',
        usage: 40,
        limit: 20,
        format: 'number',
      },
    ])
  })

  test('older responses without the total count as zero', () => {
    const usage = {} as unknown as Models.UsageOrganization

    expect(getUsagePlanOverages(usage, plan)).toEqual([])
  })

  test('organization usage lists review credits under Reviews', () => {
    const categories = buildOrganizationUsageCategoriesFromAggregation(
      [{ resourceId: 'reviewCredits', value: 30, amount: 0.05 }],
      plan,
    )
    const reviews = categories.find((category) => category.id === 'reviews')

    expect(reviews?.resources).toEqual([
      expect.objectContaining({
        resourceId: 'reviewCredits',
        usage: 30,
        limit: 20,
        cost: 0.05,
      }),
    ])
  })
})
