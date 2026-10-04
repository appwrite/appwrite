import { describe, expect, test } from 'bun:test'
import {
  buildSecurityTxt,
  wellKnownSecurityTxtResponse,
} from '@/lib/seo/security-txt'

describe('security.txt', () => {
  test('includes RFC 9116 required fields and a canonical URL', () => {
    const body = buildSecurityTxt(
      'https://appwrite.io',
      new Date('2026-10-04T12:00:00Z'),
    )

    expect(body).toContain('Contact: mailto:security@appwrite.io')
    expect(body).toContain('Expires: 2027-10-04T00:00:00.000Z')
    expect(body).toContain(
      'Canonical: https://appwrite.io/.well-known/security.txt',
    )
  })

  test('serves plain text only on the well-known path', async () => {
    const response = wellKnownSecurityTxtResponse(
      new Request('https://appwrite.io/.well-known/security.txt'),
    )

    expect(response?.status).toBe(200)
    expect(response?.headers.get('content-type')).toContain('text/plain')
    expect(await response?.text()).toContain('Contact:')
    expect(
      wellKnownSecurityTxtResponse(
        new Request('https://appwrite.io/.well-known/change-password'),
      ),
    ).toBeNull()
  })
})
