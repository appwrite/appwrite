import { describe, expect, it } from 'vitest'
import { getDatabaseTypeDisplayLabel } from '@/lib/databases/database-type-display'

describe('getDatabaseTypeDisplayLabel', () => {
  it('prefers Appwrite product type over dedicated compute engine', () => {
    expect(
      getDatabaseTypeDisplayLabel('documentsdb', 'postgresql'),
    ).toBe('DocumentsDB')
    expect(getDatabaseTypeDisplayLabel('vectorsdb', 'postgresql')).toBe(
      'VectorsDB',
    )
    expect(getDatabaseTypeDisplayLabel('tablesdb', 'postgresql')).toBe(
      'TablesDB',
    )
  })

  it('uses engine labels for native databases', () => {
    expect(
      getDatabaseTypeDisplayLabel('legacy', 'postgresql', 'nativedb'),
    ).toBe('PostgreSQL')
    expect(getDatabaseTypeDisplayLabel(null, 'mysql', 'nativedb')).toBe(
      'MySQL',
    )
    expect(getDatabaseTypeDisplayLabel(null, 'mongodb', 'nativedb')).toBe(
      'MongoDB',
    )
  })

  it('falls back legacy / missing types to TablesDB', () => {
    expect(getDatabaseTypeDisplayLabel('legacy')).toBe('TablesDB')
    expect(getDatabaseTypeDisplayLabel(null)).toBe('TablesDB')
    expect(getDatabaseTypeDisplayLabel('databases')).toBe('TablesDB')
  })
})
