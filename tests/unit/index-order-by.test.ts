/**
 * Index column order sent to the API.
 *
 * The index form works in `'ASC'`/`'DESC'` and the API in `'asc'`/`'desc'`. A
 * case-sensitive comparison against `'asc'` never matched, so every index the
 * console created came out descending no matter what was picked.
 */

import { describe, expect, test } from 'bun:test'
import { OrderBy } from '@appwrite.io/console'
import { toIndexOrderBy } from '@/lib/react-query/hooks/databases'

describe('toIndexOrderBy', () => {
  test('maps the form values the dialog actually produces', () => {
    expect(toIndexOrderBy(['ASC'])).toEqual([OrderBy.Asc])
    expect(toIndexOrderBy(['DESC'])).toEqual([OrderBy.Desc])
    expect(toIndexOrderBy(['ASC', 'DESC'])).toEqual([OrderBy.Asc, OrderBy.Desc])
  })

  test('is case-insensitive', () => {
    expect(toIndexOrderBy(['asc'])).toEqual([OrderBy.Asc])
    expect(toIndexOrderBy(['desc'])).toEqual([OrderBy.Desc])
    expect(toIndexOrderBy(['Asc', 'Desc'])).toEqual([OrderBy.Asc, OrderBy.Desc])
  })

  test('an unset order is ascending, never null', () => {
    // The form keeps nulls to hold column positions, and the API rejects a null
    // inside `orders` outright.
    expect(toIndexOrderBy([null])).toEqual([OrderBy.Asc])
    expect(toIndexOrderBy([undefined])).toEqual([OrderBy.Asc])
    expect(toIndexOrderBy(['ASC', null])).toEqual([OrderBy.Asc, OrderBy.Asc])
  })

  test('passes through an absent orders array', () => {
    expect(toIndexOrderBy(undefined)).toBeUndefined()
    expect(toIndexOrderBy([])).toEqual([])
  })
})
