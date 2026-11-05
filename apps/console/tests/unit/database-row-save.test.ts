// The row save mutation branches on rowId alone: a non-null value is sent as
// an update (PATCH), which 404s for a row that does not exist yet. A custom ID
// typed into the create form therefore must never surface as rowId.

import { describe, expect, test } from 'bun:test'
import { resolveRowSaveTarget } from '@/lib/database-row-save'

describe('resolveRowSaveTarget', () => {
  test('create without a custom ID leaves both IDs unset', () => {
    expect(
      resolveRowSaveTarget({ isCreateMode: true, customRowId: undefined }),
    ).toEqual({ rowId: null, customId: undefined })
    expect(
      resolveRowSaveTarget({ isCreateMode: true, customRowId: '' }),
    ).toEqual({ rowId: null, customId: undefined })
  })

  test('create with a custom ID keeps rowId null and forwards it as customId', () => {
    expect(
      resolveRowSaveTarget({ isCreateMode: true, customRowId: '123' }),
    ).toEqual({ rowId: null, customId: '123' })
  })

  test('create ignores any stale existing row ID', () => {
    expect(
      resolveRowSaveTarget({
        isCreateMode: true,
        existingRowId: 'stale',
        customRowId: '123',
      }),
    ).toEqual({ rowId: null, customId: '123' })
  })

  test('update targets the existing row and never sends a custom ID', () => {
    expect(
      resolveRowSaveTarget({
        isCreateMode: false,
        existingRowId: 'row_1',
        customRowId: 'ignored',
      }),
    ).toEqual({ rowId: 'row_1', customId: undefined })
  })

  test('update without a resolvable row ID falls back to null', () => {
    expect(
      resolveRowSaveTarget({ isCreateMode: false, existingRowId: undefined }),
    ).toEqual({ rowId: null, customId: undefined })
    expect(
      resolveRowSaveTarget({ isCreateMode: false, existingRowId: '' }),
    ).toEqual({ rowId: null, customId: undefined })
  })
})
