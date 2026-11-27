import { describe, expect, test } from 'bun:test'
import { pageAfterEmptyResult } from '@/lib/distribution/paging'

describe('pageAfterEmptyResult', () => {
  test.each([
    [1, 0, 25, null],
    [1, 30, 25, null],
    [3, 0, 25, null],
    [3, 30, 25, 2],
    [5, 30, 25, 2],
    [4, 76, 25, 3],
    [2, 5, 25, 1],
    [2, 10, 10, 1],
    [5, 23, 10, 3],
    [2, 14, 10, 1],
  ])(
    'empty page %p of %p items at %p per page goes to %p',
    (page, total, pageSize, expected) => {
      expect(pageAfterEmptyResult(page, total, pageSize)).toBe(expected)
    },
  )
})
