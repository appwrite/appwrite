import { describe, expect, test } from 'bun:test'
import { isWildcardPlatform, type ProjectPlatform } from '@/lib/utils/platform'

const base = {
  $id: 'platform',
  $createdAt: '2026-11-11T10:00:00.000+00:00',
  $updatedAt: '2026-11-11T10:00:00.000+00:00',
  name: 'Web app',
}

function web(hostname: string): ProjectPlatform {
  return { ...base, type: 'web', hostname } as ProjectPlatform
}

describe('isWildcardPlatform', () => {
  test('flags a web platform for any hostname', () => {
    expect(isWildcardPlatform(web('*'))).toBe(true)
    expect(isWildcardPlatform(web(' * '))).toBe(true)
  })

  test('leaves registered domains and subdomain wildcards alone', () => {
    expect(isWildcardPlatform(web('localhost'))).toBe(false)
    expect(isWildcardPlatform(web('app.example.com'))).toBe(false)
    expect(isWildcardPlatform(web('*.example.com'))).toBe(false)
    expect(isWildcardPlatform(web(''))).toBe(false)
  })

  test('ignores platforms without a hostname', () => {
    const android = {
      ...base,
      type: 'android',
      applicationId: 'com.example.app',
    } as ProjectPlatform

    expect(isWildcardPlatform(android)).toBe(false)
  })
})
