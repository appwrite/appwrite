import { describe, expect, test } from 'bun:test'
import {
  buildDedicatedDbBillingSpecTitle,
  getDedicatedDbBillingUsageDescription,
  groupDedicatedDbBillingResources,
  resolveBillingProjectResourceMapping,
  type BillingProjectResourceItem,
} from '@/lib/billing/project-breakdown-resources'

const AUTOSCALED_STORAGE = 'dedicatedDbAutoscaledStorage.vectorsdb.s-2vcpu-4gb'

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

describe('dedicated database autoscaled storage billing line', () => {
  test('maps the line cloud bills for storage above the tier', () => {
    expect(
      resolveBillingProjectResourceMapping(AUTOSCALED_STORAGE, null),
    ).toMatchObject({
      name: 'Dedicated database autoscaled storage (VectorsDB · 2 vCPU · 4 GB)',
      format: 'bytes',
      category: 'dedicated-databases',
      showOnlyWhenUsed: true,
    })
  })

  test('groups it under its specification after storage', () => {
    const { specGroups, ungrouped } = groupDedicatedDbBillingResources([
      resource(AUTOSCALED_STORAGE, 150_000_000_000, 37.5),
      resource('dedicatedDbStorage.vectorsdb.s-2vcpu-4gb', 0, 0),
      resource('dedicatedDbSpecificationCost.vectorsdb.s-2vcpu-4gb', 720, 40),
    ])

    expect(ungrouped).toEqual([])
    expect(specGroups).toHaveLength(1)
    expect(specGroups[0]?.totalCost).toBe(77.5)
    expect(specGroups[0]?.items.map((item) => item.metricLabel)).toEqual([
      'Compute',
      'Storage',
      'Autoscaled storage',
    ])
  })

  test('explains what the line covers', () => {
    expect(
      getDedicatedDbBillingUsageDescription(
        'dedicatedDbAutoscaledStorage',
        'bytes',
      ),
    ).toBe('Storage autoscaled beyond the tier allowance.')
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
