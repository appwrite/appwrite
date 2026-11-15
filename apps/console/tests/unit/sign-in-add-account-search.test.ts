import { describe, expect, test } from 'bun:test'
import {
  defaultParseSearch,
  defaultStringifySearch,
} from '@tanstack/react-router'
import type { z } from 'zod'
import { ADD_ACCOUNT_SEARCH_PARAM } from '@/lib/console-devices'
import { Route } from '@/routes/_auth/sign-in'

const searchSchema = Route.options.validateSearch as unknown as z.ZodType<{
  redirect?: string
  addAccount?: number
}>

/** What the router hands to validateSearch for a given URL query string. */
function validate(searchStr: string) {
  return searchSchema.parse(defaultParseSearch(searchStr))
}

describe('sign-in addAccount search param', () => {
  test('accepts the account switcher link, which the router parses as a number', () => {
    // ConsoleAccountSwitcherDialog navigates to /sign-in?addAccount=1.
    const searchStr = `?${ADD_ACCOUNT_SEARCH_PARAM}=1`
    expect(defaultParseSearch(searchStr)).toEqual({ addAccount: 1 })
    expect(validate(searchStr)).toEqual({ addAccount: 1 })
  })

  test('keeps the link unchanged when the router writes the URL back', () => {
    expect(defaultStringifySearch(validate('?addAccount=1'))).toBe(
      '?addAccount=1',
    )
  })

  test('drops an unusable value instead of failing the sign-in page', () => {
    expect(validate('?addAccount=abc').addAccount).toBeUndefined()
    expect(validate('?addAccount=abc&redirect=%2Fjoin').redirect).toBe('/join')
  })

  test('is absent on a plain sign-in', () => {
    expect(validate('')).toEqual({})
  })
})
