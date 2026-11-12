import { describe, expect, test } from 'bun:test'
import { AppwriteException } from '@appwrite.io/console'
import {
  getClaimRecord,
  getRecordName,
  getVerificationMethods,
  isDomainClaimError,
} from '@/lib/utils/proxy-domains'

describe('getRecordName', () => {
  test('names an apex domain @', () => {
    expect(getRecordName('example.com')).toBe('@')
    expect(getRecordName('example.co.uk')).toBe('@')
    expect(getRecordName(' Example.COM ')).toBe('@')
  })

  test('keeps every label in front of the registrable domain', () => {
    expect(getRecordName('app.example.com')).toBe('app')
    expect(getRecordName('a.b.example.com')).toBe('a.b')
    expect(getRecordName('in.mail.example.co.uk')).toBe('in.mail')
  })
})

describe('getVerificationMethods', () => {
  const targets = {
    cname: 'cloud.appwrite.io',
    a: '1.2.3.4',
    aaaa: '::1',
    nameservers: ['ns1.appwrite.zone', 'ns2.appwrite.zone'],
  }

  test('leads an apex on Cloud with the nameservers, then A and AAAA, not a CNAME on @', () => {
    expect(
      getVerificationMethods({
        domain: 'example.com',
        cloud: true,
        ...targets,
      }),
    ).toEqual(['nameservers', 'a', 'aaaa', 'cname'])
  })

  test('leads a subdomain on Cloud with the CNAME and keeps A and AAAA hidden', () => {
    expect(
      getVerificationMethods({
        domain: 'app.example.com',
        cloud: true,
        ...targets,
      }),
    ).toEqual(['cname', 'nameservers'])
  })

  test('leads an apex on self-hosted with A and AAAA', () => {
    expect(
      getVerificationMethods({
        domain: 'example.com',
        cloud: false,
        ...targets,
      }),
    ).toEqual(['a', 'aaaa', 'cname'])
  })

  test('leaves out what the server does not report', () => {
    expect(
      getVerificationMethods({
        domain: 'example.com',
        cloud: true,
        cname: 'cloud.appwrite.io',
        nameservers: [],
      }),
    ).toEqual(['cname'])
  })
})

describe('getClaimRecord', () => {
  test('puts the project ID at _appwrite relative to the zone', () => {
    expect(getClaimRecord('example.com', 'project1')).toEqual({
      type: 'TXT',
      name: '_appwrite',
      value: 'project1',
      ttl: 3600,
    })
    expect(getClaimRecord('app.example.com', 'project1').name).toBe(
      '_appwrite.app',
    )
    expect(getClaimRecord('in.mail.example.co.uk', 'project1').name).toBe(
      '_appwrite.in.mail',
    )
  })
})

describe('isDomainClaimError', () => {
  test('asks for a claim on a pointed domain or one another project holds', () => {
    expect(
      isDomainClaimError(
        new AppwriteException(
          'This domain already points to Appwrite.',
          400,
          'rule_verification_failed',
        ),
      ),
    ).toBe(true)
    expect(
      isDomainClaimError(
        new AppwriteException(
          'Domain is already used by another project.',
          409,
          'rule_claim_required',
        ),
      ),
    ).toBe(true)
  })

  test('leaves every other create failure alone', () => {
    expect(
      isDomainClaimError(
        new AppwriteException(
          'Domain already assigned to a different project.',
          409,
          'rule_already_exists',
        ),
      ),
    ).toBe(false)
    expect(isDomainClaimError(new Error('Network request failed'))).toBe(false)
    expect(isDomainClaimError(null)).toBe(false)
  })
})
