import { describe, expect, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import {
  getCloudRetentionLimit,
  getRetentionOptions,
  MAX_DEPLOYMENT_RETENTION,
} from '@/lib/deployment-retention'

function plan(group: string, name = group) {
  return { $id: name, name, group } as Pick<
    Models.BillingPlan,
    '$id' | 'name' | 'group'
  >
}

describe('deployment retention options', () => {
  test('Free and Education cannot select a period above 90 days', () => {
    for (const billingPlan of [plan('free'), plan('pro', 'Education')]) {
      const limit = getCloudRetentionLimit(billingPlan)
      expect(limit).toBe(90)
      expect(
        getRetentionOptions(3650, limit).map(({ value }) => value),
      ).toEqual([7, 30, 90])
    }
  })

  test('Start and Pro can select three years but not five or ten', () => {
    for (const group of ['start', 'pro']) {
      const limit = getCloudRetentionLimit(plan(group))
      expect(limit).toBe(1095)
      const values = getRetentionOptions(0, limit).map(({ value }) => value)
      expect(values).toContain(1095)
      expect(values.every((value) => value <= 1095)).toBe(true)
    }
  })

  test('preserves custom saved periods only within the applicable limit', () => {
    expect(getRetentionOptions(45, 90)[0]).toEqual({
      value: 45,
      label: '45 days',
    })
    expect(
      getRetentionOptions(180, 90).some(({ value }) => value === 180),
    ).toBe(false)
    expect(getRetentionOptions(0, 90).some(({ value }) => value === 0)).toBe(
      false,
    )
  })

  test('unknown plans do not silently inherit paid plan limits', () => {
    expect(getCloudRetentionLimit(undefined)).toBeUndefined()
    expect(getCloudRetentionLimit(plan('starter'))).toBeUndefined()
  })

  test('custom Scale and self-hosted retain the existing extended options', () => {
    expect(getCloudRetentionLimit(plan('scale'))).toBe(MAX_DEPLOYMENT_RETENTION)
    expect(getRetentionOptions(36500)[0]).toEqual({
      value: 36500,
      label: '36500 days',
    })
    expect(getRetentionOptions(0).some(({ value }) => value === 3650)).toBe(
      true,
    )
  })
})
