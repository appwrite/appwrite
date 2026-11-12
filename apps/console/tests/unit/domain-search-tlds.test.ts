import { describe, expect, test } from 'bun:test'
import { DOMAIN_SEARCH_TLDS, getDomainSearchTlds } from '@/lib/domains/tlds'
import { buildDomainSuggestions } from '@/lib/domains/search'

describe('getDomainSearchTlds', () => {
  test('leads with a typed TLD missing from the list', () => {
    expect(getDomainSearchTlds('carden', 'carden.page')).toEqual([
      'page',
      ...DOMAIN_SEARCH_TLDS,
    ])
    expect(getDomainSearchTlds('carden', 'carden.co.uk')[0]).toBe('co.uk')
  })

  test('keeps the list for listed TLDs and incomplete input', () => {
    for (const search of [
      'carden',
      'carden.',
      'carden.p',
      'carden.com',
      'carden..uk',
      'carden.co.',
    ]) {
      expect(getDomainSearchTlds('carden', search)).toBe(DOMAIN_SEARCH_TLDS)
    }
  })
})

describe('buildDomainSuggestions', () => {
  const build = (normalizedSearch: string) =>
    buildDomainSuggestions({
      baseName: 'carden',
      normalizedSearch,
      apiDataByDomain: new Map([
        ['carden.page', { price: 19.19, available: true }],
      ]),
      typedTld: normalizedSearch.split('.').slice(1).join(''),
      showSuggestions: true,
    })

  test('shows the typed domain first when its TLD is not listed', () => {
    const [first] = build('carden.page')
    expect(first).toMatchObject({
      full: 'carden.page',
      tld: 'page',
      price: 19.19,
      taken: false,
      isPerfectMatch: true,
    })
  })

  test('does not add a card for a listed TLD', () => {
    const suggestions = build('carden.com')
    expect(suggestions).toHaveLength(DOMAIN_SEARCH_TLDS.length)
    expect(suggestions[0].full).toBe('carden.com')
  })
})
