import { createHash } from 'node:crypto'
import { describe, expect, it } from 'bun:test'
import { buildOpenAiAdsUserData } from '@/lib/openai-ads'

function sha256(value: string) {
  return createHash('sha256').update(value, 'utf8').digest('hex')
}

describe('buildOpenAiAdsUserData', () => {
  it('hashes a trimmed lowercase email and a case-preserving external id', async () => {
    const user = await buildOpenAiAdsUserData({
      externalId: '  User_123  ',
      email: '  Test@Example.com ',
    })

    expect(user).toEqual({
      external_id_sha256: sha256('User_123'),
      email_sha256: sha256('test@example.com'),
    })
  })

  it('hashes a phone number down to digits with the country code kept', async () => {
    const user = await buildOpenAiAdsUserData({
      externalId: 'user',
      phone: '+1 (415) 555-2671',
    })

    expect(user?.phone_number_sha256).toBe(sha256('14155552671'))
  })

  it('drops phone numbers that are not 8 to 15 digits', async () => {
    const user = await buildOpenAiAdsUserData({
      externalId: 'user',
      phone: '555-1212',
    })

    expect(user?.phone_number_sha256).toBeUndefined()
    expect(user?.external_id_sha256).toBe(sha256('user'))
  })

  it('returns undefined when no identifier is left after normalization', async () => {
    expect(
      await buildOpenAiAdsUserData({
        externalId: '   ',
        email: '   ',
        phone: 'abc',
      }),
    ).toBeUndefined()
  })
})
