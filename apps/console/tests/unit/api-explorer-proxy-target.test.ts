import { describe, expect, test } from 'bun:test'
import { assertExplorerProxyTarget } from '@/server/api-explorer/proxy-target'
import type { AddressLookup } from '@/server/lib/public-address'

const endpoint = 'https://cloud.appwrite.io/v1'

function resolvesTo(...addresses: string[]): AddressLookup {
  return async () => addresses
}

const unresolvable: AddressLookup = async () => {
  throw new Error('ENOTFOUND')
}

describe('assertExplorerProxyTarget', () => {
  test('keeps the endpoint rules', async () => {
    await expect(
      assertExplorerProxyTarget(
        'http://169.254.169.254/latest/meta-data/',
        endpoint,
        resolvesTo('8.8.8.8'),
      ),
    ).rejects.toThrow()
  })

  test('uses the configured host as set', async () => {
    await assertExplorerProxyTarget(
      'http://localhost:9601/v1/account',
      'http://localhost:9601/v1',
      unresolvable,
    )
  })

  test('allows a region that resolves to public addresses', async () => {
    await assertExplorerProxyTarget(
      'https://fra.cloud.appwrite.io/v1/account',
      endpoint,
      resolvesTo('104.18.0.1', '2606:4700::6810:1'),
    )
  })

  test('refuses a region that does not resolve to public addresses', async () => {
    for (const resolve of [
      resolvesTo('10.0.0.5'),
      resolvesTo('104.18.0.1', '169.254.169.254'),
      resolvesTo(),
      unresolvable,
    ]) {
      await expect(
        assertExplorerProxyTarget(
          'https://internal.cloud.appwrite.io/v1/account',
          endpoint,
          resolve,
        ),
      ).rejects.toThrow('host is not allowed')
    }
  })
})
