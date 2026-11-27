import { describe, expect, test } from 'bun:test'
import {
  buildDedicatedDbBillingSpecTitle,
  getDedicatedDbBillingUsageDescription,
  groupDedicatedDbBillingResources,
  resolveBillingProjectResourceMapping,
  type BillingProjectResourceItem,
} from '@/lib/billing/project-breakdown-resources'

const ADDITIONAL_STORAGE = 'dedicatedDbAutoscaledStorage.vectorsdb.s-2vcpu-4gb'

function resource(
  resourceId: string,
  usage: number,
  cost: number,
): BillingProjectResourceItem {
  const mapping = resolveBillingProjectResourceMapping(resourceId, null)
  if (!mapping) throw new Error(`No billing mapping for ${resourceId}`)
  return {
    resourceId,
    name: mapping.name,
    usage,
    limit: null,
    cost,
    formatType: mapping.format,
    category: mapping.category,
  }
}

describe('dedicated database additional storage billing line', () => {
  test('maps the line cloud bills for storage above the tier', () => {
    expect(
      resolveBillingProjectResourceMapping(ADDITIONAL_STORAGE, null),
    ).toMatchObject({
      name: 'Dedicated database additional storage (VectorsDB · 2 vCPU · 4 GB)',
      format: 'bytes',
      category: 'dedicated-databases',
      showOnlyWhenUsed: true,
    })
  })

  test('groups it under its specification after storage', () => {
    const { specGroups, ungrouped } = groupDedicatedDbBillingResources([
      resource(ADDITIONAL_STORAGE, 150_000_000_000, 37.5),
      resource('dedicatedDbStorage.vectorsdb.s-2vcpu-4gb', 0, 0),
      resource('dedicatedDbSpecificationCost.vectorsdb.s-2vcpu-4gb', 720, 40),
    ])

    expect(ungrouped).toEqual([])
    expect(specGroups).toHaveLength(1)
    expect(specGroups[0]?.totalCost).toBe(77.5)
    expect(specGroups[0]?.items.map((item) => item.metricLabel)).toEqual([
      'Compute',
      'Storage',
      'Additional storage',
    ])
  })

  test('explains what the line covers', () => {
    expect(
      getDedicatedDbBillingUsageDescription(
        'dedicatedDbAutoscaledStorage',
        'bytes',
      ),
    ).toBe(
      'Storage provisioned beyond the tier allowance, such as storage kept after a downgrade.',
    )
  })

  test.each([
    ['tablesdb', 'TablesDB'],
    ['documentsdb', 'DocumentsDB'],
    ['vectorsdb', 'VectorsDB'],
  ])('names the %s product in the group title', (segment, label) => {
    expect(
      buildDedicatedDbBillingSpecTitle(segment, 's-2vcpu-4gb').startsWith(
        `${label} · `,
      ),
    ).toBe(true)
  })
})
