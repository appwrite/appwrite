import { describe, expect, it } from 'vitest'
import { resolveDatabaseSwitcherHomeLink } from '@/lib/databases/navigate-to-database-switcher'

describe('resolveDatabaseSwitcherHomeLink', () => {
  it('routes Appwrite product databases by type, ignoring compute engine', () => {
    const documents = resolveDatabaseSwitcherHomeLink('proj', {
      id: 'doc1',
      apiType: 'documentsdb',
      engine: 'postgresql',
      product: 'documentsdb',
    })
    expect(String(documents.to)).toContain('/databases/$dbKind/')
    expect(documents.params.dbKind).toBe('documentsdb')

    const vectors = resolveDatabaseSwitcherHomeLink('proj', {
      id: 'vec1',
      apiType: 'vectorsdb',
      engine: 'postgresql',
    })
    expect(vectors.params.dbKind).toBe('vectorsdb')
  })

  it('routes native postgres databases to the postgres workspace', () => {
    const link = resolveDatabaseSwitcherHomeLink('proj', {
      id: 'pg1',
      product: 'nativedb',
      engine: 'postgresql',
    })
    expect(String(link.to)).toContain('/databases/postgres/')
    expect(link.params.databaseId).toBe('pg1')
  })

  it('falls back legacy databases to TablesDB', () => {
    const link = resolveDatabaseSwitcherHomeLink('proj', {
      id: 'legacy1',
      apiType: 'legacy',
    })
    expect(link.params.dbKind).toBe('tablesdb')
  })
})
