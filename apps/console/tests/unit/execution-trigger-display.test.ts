import { describe, expect, test } from 'bun:test'
import { executionsFilterColumns } from '@/lib/table-filters/filter-configs/executions'

describe('execution trigger display', () => {
  test('includes email alongside http, schedule, and event filters', () => {
    const trigger = executionsFilterColumns.find(
      (column) => column.id === 'trigger',
    )
    const values = (trigger?.elements ?? []).map((element) => element.value)
    const labels = (trigger?.elements ?? []).map((element) => element.label)

    expect(values).toEqual(['http', 'schedule', 'event', 'email'])
    expect(labels).toContain('Email')
  })

  test('uses the Edge SMTP enum value email (not smtp)', () => {
    const trigger = executionsFilterColumns.find(
      (column) => column.id === 'trigger',
    )
    const values = (trigger?.elements ?? []).map((element) => element.value)
    expect(values).toContain('email')
    expect(values).not.toContain('smtp')
  })
})
