// Two server rules drive these cases, neither visible from the client types: a
// relationship resolves only for a dotted select (`orders.*`), and an update is
// merged onto the stored row, so an omitted key keeps its related rows.

import { describe, expect, test } from 'bun:test'
import {
  buildRowListSelectQuery,
  getRelationshipColumnKeys,
} from '@/lib/react-query/hooks/databases'
import {
  isMultiRelationship,
  relatedRowLabel,
  toRelatedRowId,
  toRelationshipPayloadValue,
} from '@/lib/database-relationship-values'

const selectValues = (query: string | undefined): string[] => {
  if (!query) return []
  const parsed = JSON.parse(query) as { method?: string; values?: string[] }
  expect(parsed.method).toBe('select')
  return parsed.values ?? []
}

const relationshipColumn = (
  key: string,
  overrides: Record<string, unknown> = {},
) => ({
  key,
  type: 'relationship',
  status: 'available',
  relationType: 'oneToMany',
  side: 'parent',
  ...overrides,
})

describe('getRelationshipColumnKeys', () => {
  test('picks available relationship columns only', () => {
    expect(
      getRelationshipColumnKeys([
        { key: 'name', type: 'string', status: 'available' },
        relationshipColumn('orders'),
        relationshipColumn('invoices', { status: 'processing' }),
      ]),
    ).toEqual(['orders'])
  })

  test('is stably ordered so the query key does not churn', () => {
    const columns = [relationshipColumn('orders'), relationshipColumn('cards')]
    expect(getRelationshipColumnKeys(columns)).toEqual(['cards', 'orders'])
    expect(getRelationshipColumnKeys([...columns].reverse())).toEqual([
      'cards',
      'orders',
    ])
  })

  test('accepts raw collection attributes, which carry no status', () => {
    // Documents/Vectors collections expose their schema as `attributes`, and the
    // loader reads them unmapped.
    expect(
      getRelationshipColumnKeys([
        { key: 'name', type: 'string' },
        { key: 'orders', type: 'relationship', relationType: 'oneToMany' },
      ]),
    ).toEqual(['orders'])
  })

  test('tolerates a missing or empty column list', () => {
    expect(getRelationshipColumnKeys(undefined)).toEqual([])
    expect(getRelationshipColumnKeys([])).toEqual([])
  })
})

describe('buildRowListSelectQuery', () => {
  test('with no saved layout, asks for everything plus the relationships', () => {
    const values = selectValues(
      buildRowListSelectQuery(null, '$createdAt', ['orders']),
    )
    expect(values).toEqual(['*', 'orders.*'])
  })

  test('with no saved layout and no relationships, sends no select', () => {
    // Nothing to gain from a projection here, and `skipRelationships` is
    // harmless on a table that has no relationships.
    expect(buildRowListSelectQuery(null, '$createdAt', [])).toBeUndefined()
    expect(buildRowListSelectQuery([], '$createdAt', null)).toBeUndefined()
  })

  test('requests a visible relationship as `key.*`, never as a bare key', () => {
    const values = selectValues(
      buildRowListSelectQuery(['name', 'orders'], '$createdAt', ['orders']),
    )
    expect(values).toContain('orders.*')
    expect(values).not.toContain('orders')
    expect(values).toContain('name')
  })

  test('keeps the system fields the grid and paging depend on', () => {
    const values = selectValues(
      buildRowListSelectQuery(['name'], '$createdAt', ['orders']),
    )
    for (const key of [
      '$id',
      '$createdAt',
      '$updatedAt',
      '$permissions',
      '$sequence',
    ]) {
      expect(values).toContain(key)
    }
  })

  test('a hidden relationship is not requested', () => {
    const values = selectValues(
      buildRowListSelectQuery(['name'], '$createdAt', ['orders']),
    )
    expect(values).not.toContain('orders.*')
    expect(values).not.toContain('orders')
  })

  test('never selects a relationship column as the sort key', () => {
    // Relationships are not sortable server-side, and a bare key 400s the list.
    const values = selectValues(
      buildRowListSelectQuery(['name', 'orders'], 'orders', ['orders']),
    )
    expect(values).not.toContain('orders')
    expect(values).toContain('orders.*')
  })

  test('drops `$`-prefixed and oversized keys from the saved layout', () => {
    const values = selectValues(
      buildRowListSelectQuery(
        ['name', '$id', 'x'.repeat(513)],
        '$createdAt',
        [],
      ),
    )
    expect(values.filter((value) => value === '$id')).toHaveLength(1)
    expect(values.some((value) => value.length > 512)).toBe(false)
  })
})

describe('isMultiRelationship', () => {
  test('follows the side, not just the relation type', () => {
    expect(
      isMultiRelationship(
        relationshipColumn('a', { relationType: 'oneToOne' }),
      ),
    ).toBe(false)
    expect(
      isMultiRelationship(
        relationshipColumn('a', { relationType: 'oneToMany', side: 'parent' }),
      ),
    ).toBe(true)
    // The child of a one-to-many holds a single row.
    expect(
      isMultiRelationship(
        relationshipColumn('a', { relationType: 'oneToMany', side: 'child' }),
      ),
    ).toBe(false)
    expect(
      isMultiRelationship(
        relationshipColumn('a', { relationType: 'manyToOne', side: 'parent' }),
      ),
    ).toBe(false)
    // ...and the child of a many-to-one holds many.
    expect(
      isMultiRelationship(
        relationshipColumn('a', { relationType: 'manyToOne', side: 'child' }),
      ),
    ).toBe(true)
    expect(
      isMultiRelationship(
        relationshipColumn('a', { relationType: 'manyToMany' }),
      ),
    ).toBe(true)
  })
})

describe('toRelatedRowId', () => {
  test('accepts both an id string and a populated row', () => {
    expect(toRelatedRowId('row-1')).toBe('row-1')
    expect(toRelatedRowId({ $id: 'row-1', name: 'Ada' })).toBe('row-1')
  })

  test('rejects the editor placeholders', () => {
    expect(toRelatedRowId('')).toBeNull()
    expect(toRelatedRowId('   ')).toBeNull()
    expect(toRelatedRowId(null)).toBeNull()
    expect(toRelatedRowId(undefined)).toBeNull()
    expect(toRelatedRowId({})).toBeNull()
  })
})

describe('toRelationshipPayloadValue', () => {
  const toMany = relationshipColumn('orders')
  const toOne = relationshipColumn('customer', {
    relationType: 'manyToOne',
    side: 'parent',
  })

  test('reduces populated rows to ids', () => {
    expect(
      toRelationshipPayloadValue(
        [{ $id: 'o1', total: 10 }, 'o2', { $id: 'o3' }],
        toMany,
      ),
    ).toEqual(['o1', 'o2', 'o3'])
    expect(toRelationshipPayloadValue({ $id: 'c1', name: 'Ada' }, toOne)).toBe(
      'c1',
    )
  })

  test('an emptied to-many is [] - never null', () => {
    // null on a to-many is what produced "Invalid relationship value ... NULL given".
    expect(toRelationshipPayloadValue([], toMany)).toEqual([])
    expect(toRelationshipPayloadValue(null, toMany)).toEqual([])
    expect(toRelationshipPayloadValue('', toMany)).toEqual([])
  })

  test('an emptied to-one is null', () => {
    expect(toRelationshipPayloadValue('', toOne)).toBeNull()
    expect(toRelationshipPayloadValue(null, toOne)).toBeNull()
  })

  test('drops entries that carry no id', () => {
    expect(toRelationshipPayloadValue(['o1', {}, null, ''], toMany)).toEqual([
      'o1',
    ])
  })
})

describe('relatedRowLabel', () => {
  test('prefers the first non-empty string field', () => {
    expect(relatedRowLabel({ $id: 'r1', name: 'Ada', city: 'Cairo' })).toBe(
      'Ada',
    )
  })

  test('skips system fields and empty strings', () => {
    expect(relatedRowLabel({ $id: 'r1', $createdAt: 'x', name: '  ' })).toBe(
      'r1',
    )
  })

  test('falls back to the id when there is nothing to show', () => {
    expect(relatedRowLabel({ $id: 'r1', total: 3 })).toBe('r1')
    expect(relatedRowLabel('r1')).toBe('r1')
  })
})
