import { describe, expect, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import { calculateKeptStorageCharge } from '@/lib/database-create-pricing'

const specification = (includedStorage: number) =>
  ({
    slug: 's-1vcpu-1gb',
    includedStorage,
    storageOverageRate: 0.25,
  }) as Models.DedicatedDatabaseSpecification

describe('calculateKeptStorageCharge', () => {
  test('bills the storage a smaller tier does not include', () => {
    expect(calculateKeptStorageCharge(200, specification(50))).toEqual({
      storageGb: 200,
      monthlyUsd: 37.5,
    })
  })

  test.each([
    [50, 50],
    [50, 200],
  ])('charges nothing when %p GB fits in %p GB', (storage, included) => {
    expect(calculateKeptStorageCharge(storage, specification(included))).toBe(
      null,
    )
  })

  test('charges nothing without a known storage or tier', () => {
    expect(calculateKeptStorageCharge(null, specification(10))).toBe(null)
    expect(calculateKeptStorageCharge(200, undefined)).toBe(null)
  })
})
