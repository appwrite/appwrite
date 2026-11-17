import { describe, expect, test } from 'bun:test'
import type { z } from 'zod'
import {
  ADD_ACCOUNT_SEARCH_PARAM,
  isAddAccountSearch,
} from '@/lib/console-devices'
import { parseSearch, stringifySearch } from '@/lib/router-search'
import { Route } from '@/routes/_auth/sign-in'

const searchSchema = Route.options.validateSearch as unknown as z.ZodType<{
  redirect?: string
  addAccount?: number
}>

/** What the router hands to validateSearch for a given URL query string. */
function validate(searchStr: string) {
  return searchSchema.parse(parseSearch(searchStr))
}

describe('sign-in addAccount search param', () => {
  test('the loader recognizes the account switcher link as the router parses it', () => {
    // ConsoleAccountSwitcherDialog navigates to /sign-in?addAccount=1. The loader
    // reads the query before validateSearch, where the router keeps it a string.
    const searchStr = `?${ADD_ACCOUNT_SEARCH_PARAM}=1`
    expect(parseSearch(searchStr)).toEqual({ addAccount: '1' })
    expect(isAddAccountSearch(parseSearch(searchStr))).toBe(true)
  })

  test('the loader does not treat other sign-ins as adding an account', () => {
    expect(isAddAccountSearch(parseSearch(''))).toBe(false)
    expect(isAddAccountSearch(parseSearch('?redirect=%2Fjoin'))).toBe(false)
    expect(isAddAccountSearch(parseSearch('?addAccount=0'))).toBe(false)
    expect(isAddAccountSearch(parseSearch('?addAccount=abc'))).toBe(false)
  })

  test('accepts the account switcher link in validateSearch', () => {
    expect(validate('?addAccount=1')).toEqual({ addAccount: 1 })
  })

  test('keeps the link unchanged when the router writes the URL back', () => {
    expect(stringifySearch(validate('?addAccount=1'))).toBe('?addAccount=1')
  })

  test('drops an unusable value instead of failing the sign-in page', () => {
    expect(validate('?addAccount=abc').addAccount).toBeUndefined()
    expect(validate('?addAccount=abc&redirect=%2Fjoin').redirect).toBe('/join')
  })

  test('is absent on a plain sign-in', () => {
    expect(validate('')).toEqual({})
  })
})
