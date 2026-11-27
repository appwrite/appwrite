import { describe, expect, test } from 'bun:test'
import {
  appleIssuerIdError,
  appleKeyIdError,
  applePrivateKeyError,
  parseServiceAccount,
} from '@/lib/distribution/credentials'

const PRIVATE_KEY =
  '-----BEGIN PRIVATE KEY-----\nMIGTAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBHkwdwIBAQQg\n-----END PRIVATE KEY-----'

describe('appleKeyIdError', () => {
  test.each(['2X9R4HXF34', ' 7RQ9P2LK8M '])('accepts %p', (keyId) => {
    expect(appleKeyIdError(keyId)).toBeNull()
  })

  test.each([
    ['', 'Enter the key ID.'],
    [
      '2x9r4hxf34',
      'A key ID is 10 uppercase letters and digits, like 2X9R4HXF34.',
    ],
    [
      '2X9R4HXF3',
      'A key ID is 10 uppercase letters and digits, like 2X9R4HXF34.',
    ],
    [
      '2X9R4-XF34',
      'A key ID is 10 uppercase letters and digits, like 2X9R4HXF34.',
    ],
  ])('rejects %p', (keyId, error) => {
    expect(appleKeyIdError(keyId)).toBe(error)
  })
})

describe('appleIssuerIdError', () => {
  test('accepts a UUID in either case', () => {
    expect(
      appleIssuerIdError('69a6de7e-0b3c-47e3-e053-5b8c7c11a4d1'),
    ).toBeNull()
    expect(
      appleIssuerIdError('69A6DE7E-0B3C-47E3-E053-5B8C7C11A4D1'),
    ).toBeNull()
  })

  test('points an empty value at team keys', () => {
    expect(appleIssuerIdError(' ')).toBe(
      'Enter the issuer ID. Individual keys have none, so use a team key.',
    )
  })

  test.each([
    '69a6de7e0b3c47e3e0535b8c7c11a4d1',
    '69a6de7e-0b3c-47e3-e053-5b8c7c11a4d',
    '69a6de7g-0b3c-47e3-e053-5b8c7c11a4d1',
  ])('rejects %p', (issuerId) => {
    expect(appleIssuerIdError(issuerId)).toBe(
      'An issuer ID looks like 69a6de7e-0b3c-47e3-e053-5b8c7c11a4d1.',
    )
  })
})

describe('applePrivateKeyError', () => {
  test('accepts a PEM private key', () => {
    expect(applePrivateKeyError(PRIVATE_KEY)).toBeNull()
  })

  test('asks for the key when empty', () => {
    expect(applePrivateKeyError('')).toBe('Add the .p8 private key.')
  })

  test('rejects text without PEM markers', () => {
    expect(applePrivateKeyError('MIGTAgEAMBMGByqGSM49')).toBe(
      'The .p8 file must contain a PEM private key.',
    )
  })
})

describe('parseServiceAccount', () => {
  test('keeps only the fields the server reads', () => {
    const key = {
      type: 'service_account',
      project_id: 'acme',
      private_key_id: 'a1b2c3d4e5f6',
      client_email: 'release@acme.iam.gserviceaccount.com',
      client_id: '104857600000000000000',
      private_key: PRIVATE_KEY,
      token_uri: 'https://attacker.example/token',
    }
    expect(parseServiceAccount(JSON.stringify(key))).toEqual({
      serviceAccount: {
        client_email: 'release@acme.iam.gserviceaccount.com',
        private_key: PRIVATE_KEY,
        project_id: 'acme',
      },
      error: null,
    })
  })

  test('omits an empty project ID', () => {
    const key = {
      client_email: 'release@acme.iam.gserviceaccount.com',
      private_key: PRIVATE_KEY,
      project_id: '',
    }
    expect(parseServiceAccount(JSON.stringify(key))).toEqual({
      serviceAccount: {
        client_email: 'release@acme.iam.gserviceaccount.com',
        private_key: PRIVATE_KEY,
      },
      error: null,
    })
  })

  test.each([
    ['', 'Add the service account JSON key.'],
    ['{not json', 'The key is not valid JSON.'],
    ['[]', 'The key is not valid JSON.'],
    [
      '{"type":"authorized_user","client_email":"a","private_key":"b"}',
      'This is not a service account key. Create a JSON key for a service account.',
    ],
    [
      '{"type":"service_account","client_email":"a"}',
      'The key needs client_email and private_key. Download a new JSON key.',
    ],
  ])('rejects %p', (text, error) => {
    expect(parseServiceAccount(text)).toEqual({ serviceAccount: null, error })
  })
})
