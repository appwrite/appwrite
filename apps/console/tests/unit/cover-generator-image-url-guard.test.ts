import { describe, expect, test } from 'bun:test'
import { isPrivateOrReservedAddress } from '@/lib/cover-generator/image-url-guard'

describe('isPrivateOrReservedAddress', () => {
  test('blocks private, loopback, link-local and reserved IPv4', () => {
    for (const ip of [
      '0.0.0.0',
      '10.0.0.1',
      '127.0.0.1',
      '169.254.169.254', // cloud metadata
      '172.16.0.1',
      '172.31.255.255',
      '192.168.1.1',
      '100.64.0.1', // CGNAT
      '198.18.0.1', // benchmark
      '224.0.0.1', // multicast
      '255.255.255.255', // reserved
    ]) {
      expect(isPrivateOrReservedAddress(ip)).toBe(true)
    }
  })

  test('allows public IPv4', () => {
    for (const ip of ['8.8.8.8', '1.1.1.1', '93.184.216.34', '172.15.0.1', '172.32.0.1']) {
      expect(isPrivateOrReservedAddress(ip)).toBe(false)
    }
  })

  test('blocks loopback, link-local, ULA and IPv4-mapped IPv6', () => {
    for (const ip of [
      '::1',
      '::',
      'fe80::1',
      'fc00::1',
      'fd12:3456::1',
      'ff02::1', // multicast
      '::ffff:127.0.0.1', // IPv4-mapped loopback
      '::ffff:169.254.169.254',
    ]) {
      expect(isPrivateOrReservedAddress(ip)).toBe(true)
    }
  })

  test('allows public IPv6', () => {
    for (const ip of ['2606:4700:4700::1111', '2001:4860:4860::8888']) {
      expect(isPrivateOrReservedAddress(ip)).toBe(false)
    }
  })

  test('treats a non-IP string as unsafe', () => {
    expect(isPrivateOrReservedAddress('not-an-ip')).toBe(true)
    expect(isPrivateOrReservedAddress('')).toBe(true)
  })
})
