/**
 * The downgrade warning must only list usage that is really over the target plan.
 */

import { describe, expect, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import { getUsagePlanOverages } from '@/lib/billing/usage-plan-overages'

function metric(value: number): Models.Metric {
  return { value, date: '2026-01-01T00:00:00.000Z' }
}

function usage(
  overrides: Partial<Models.UsageOrganization>,
): Models.UsageOrganization {
  return overrides as Models.UsageOrganization
}

function plan(overrides: Partial<Models.BillingPlan>): Models.BillingPlan {
  return overrides as Models.BillingPlan
}

describe('getUsagePlanOverages', () => {
  test('sums the bandwidth metric series and compares it against the plan GB value', () => {
    expect(
      getUsagePlanOverages(
        usage({ bandwidth: [metric(600_000_000), metric(700_000_000)] }),
        plan({ bandwidth: 1 }),
      ),
    ).toEqual([
      {
        id: 'bandwidth',
        name: 'Bandwidth',
        usage: 1_300_000_000,
        limit: 1_000_000_000,
        format: 'bytes',
      },
    ])
  })

  test('omits a resource that is under its limit', () => {
    expect(
      getUsagePlanOverages(
        usage({ storageTotal: 500_000_000 }),
        plan({ storage: 1 }),
      ),
    ).toEqual([])
  })

  test('omits a resource the plan leaves unset', () => {
    expect(
      getUsagePlanOverages(usage({ usersTotal: 50_000 }), plan({})),
    ).toEqual([])
  })

  test('omits a resource the plan disables with -1', () => {
    expect(
      getUsagePlanOverages(
        usage({ screenshotsGeneratedTotal: 1_000 }),
        plan({ screenshotsGenerated: -1 }),
      ),
    ).toEqual([])
  })

  test('combines execution and build MB-seconds into GB-hours', () => {
    expect(
      getUsagePlanOverages(
        usage({
          executionsMBSecondsTotal: 3_600_000,
          buildsMBSecondsTotal: 3_600_000,
        }),
        plan({ GBHours: 1 }),
      ),
    ).toEqual([
      {
        id: 'GBHours',
        name: 'GB-hours',
        usage: 2,
        limit: 1,
        format: 'gbhours',
      },
    ])
  })

  test('treats missing totals as zero usage', () => {
    expect(
      getUsagePlanOverages(
        usage({}),
        plan({ users: 10, storage: 1, GBHours: 5, authPhone: 10 }),
      ),
    ).toEqual([])
  })
})
