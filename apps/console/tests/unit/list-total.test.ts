import { describe, expect, test } from 'bun:test'
import {
  LIST_TOTAL_CAP,
  formatListTotal,
  formatPaginationTotal,
  isListTotalCapped,
} from '@/lib/list-total'

/** The clamp is the server's `APP_LIMIT_COUNT`, so locale separators are the only unknown. */
const cap = LIST_TOTAL_CAP.toLocaleString()

describe('list total clamp', () => {
  test('a total that reaches the clamp is a floor, below it is a count', () => {
    expect(isListTotalCapped(LIST_TOTAL_CAP - 1)).toBe(false)
    expect(isListTotalCapped(LIST_TOTAL_CAP)).toBe(true)
    expect(isListTotalCapped(LIST_TOTAL_CAP + 1)).toBe(true)
  })

  test('a clamped total renders with a "+", an exact one plain', () => {
    expect(formatListTotal(LIST_TOTAL_CAP)).toBe(`${cap}+`)
    expect(formatListTotal(42)).toBe('42')
  })
})

describe('pagination total', () => {
  test('a clamped total is never presented as the exact total', () => {
    // 50,000 users, first page: the count came back clamped at 5,000.
    expect(
      formatPaginationTotal({
        total: LIST_TOTAL_CAP,
        rangeEnd: 25,
        currentPage: 1,
        totalPages: 200,
        hasNextPage: true,
      }),
    ).toBe(`${cap}+`)
  })

  test('a clamped total stays a floor without a next-page hint', () => {
    expect(
      formatPaginationTotal({
        total: LIST_TOTAL_CAP,
        rangeEnd: 24,
        currentPage: 2,
        totalPages: 417,
        hasNextPage: false,
      }),
    ).toBe(`${cap}+`)
  })

  test('paging past the clamp to the end of the list gives the exact total', () => {
    expect(
      formatPaginationTotal({
        total: LIST_TOTAL_CAP,
        rangeEnd: 5123,
        currentPage: 206,
        totalPages: 200,
        hasNextPage: false,
      }),
    ).toBe((5123).toLocaleString())
  })

  test('paging past the clamp with rows still to come raises the floor', () => {
    expect(
      formatPaginationTotal({
        total: LIST_TOTAL_CAP,
        rangeEnd: 5025,
        currentPage: 201,
        totalPages: 200,
        hasNextPage: true,
      }),
    ).toBe(`${(5025).toLocaleString()}+`)
  })

  test('an uncapped count is reported as given', () => {
    expect(
      formatPaginationTotal({
        total: 100,
        rangeEnd: 25,
        currentPage: 1,
        totalPages: 4,
        hasNextPage: false,
      }),
    ).toBe('100')
  })

  test('a next page past the counted last page makes the count a floor', () => {
    expect(
      formatPaginationTotal({
        total: 100,
        rangeEnd: 100,
        currentPage: 4,
        totalPages: 4,
        hasNextPage: true,
      }),
    ).toBe('100+')
  })
})
