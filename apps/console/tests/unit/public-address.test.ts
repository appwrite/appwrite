import { describe, expect, test } from 'bun:test'
import {
  isPublicAddress,
  resolvesToPublicAddresses,
  type AddressLookup,
} from '@/server/lib/public-address'

function resolvesTo(...addresses: string[]): AddressLookup {
  return async () => addresses
}

describe('isPublicAddress', () => {
  test('accepts public unicast addresses', () => {
    for (const address of ['8.8.8.8', '104.18.0.1', '2606:4700::6810:1']) {
      expect(isPublicAddress(address)).toBe(true)
    }
  })

  test('refuses loopback, private, link-local and reserved addresses', () => {
    for (const address of [
      '127.0.0.1',
      '10.1.2.3',
      '172.16.0.1',
      '192.168.1.1',
      '169.254.169.254',
      '100.64.0.1',
      '0.0.0.0',
      '255.255.255.255',
      '::1',
      '::',
      '::127.0.0.1',
      '::ffff:127.0.0.1',
      '::ffff:a9fe:a9fe',
      'fd00::1',
      'fe80::1',
      '64:ff9b::a9fe:a9fe',
      '2002:7f00:1::',
      'not-an-ip',
    ]) {
      expect(isPublicAddress(address)).toBe(false)
    }
  })
})

describe('resolvesToPublicAddresses', () => {
  test('checks IP literals without resolving them', async () => {
    const never: AddressLookup = async () => {
      throw new Error('resolved an IP literal')
    }
    expect(await resolvesToPublicAddresses('8.8.8.8', never)).toBe(true)
    expect(await resolvesToPublicAddresses('169.254.169.254', never)).toBe(
      false,
    )
    expect(await resolvesToPublicAddresses('[::1]', never)).toBe(false)
    expect(await resolvesToPublicAddresses('[2606:4700::1]', never)).toBe(true)
  })

  test('requires every resolved address to be public', async () => {
    expect(
      await resolvesToPublicAddresses('a.example', resolvesTo('8.8.8.8')),
    ).toBe(true)
    expect(
      await resolvesToPublicAddresses(
        'a.example',
        resolvesTo('8.8.8.8', '10.0.0.1'),
      ),
    ).toBe(false)
    expect(await resolvesToPublicAddresses('a.example', resolvesTo())).toBe(
      false,
    )
    const unresolvable: AddressLookup = async () => {
      throw new Error('ENOTFOUND')
    }
    expect(await resolvesToPublicAddresses('a.example', unresolvable)).toBe(
      false,
    )
  })
})
