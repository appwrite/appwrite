import { lookup } from 'node:dns/promises'
import { BlockList, isIP } from 'node:net'

/** Every address a hostname resolves to. */
export type AddressLookup = (hostname: string) => Promise<string[]>

/**
 * Loopback, private, link-local (cloud metadata), carrier-grade NAT,
 * documentation, benchmarking, multicast and reserved ranges, plus the IPv6
 * prefixes that embed or translate to IPv4. IPv4-mapped IPv6 addresses are
 * checked against the IPv4 rules.
 */
const NON_PUBLIC_ADDRESSES = new BlockList()
for (const [network, prefix] of [
  ['0.0.0.0', 8],
  ['10.0.0.0', 8],
  ['100.64.0.0', 10],
  ['127.0.0.0', 8],
  ['169.254.0.0', 16],
  ['172.16.0.0', 12],
  ['192.0.0.0', 24],
  ['192.0.2.0', 24],
  ['192.88.99.0', 24],
  ['192.168.0.0', 16],
  ['198.18.0.0', 15],
  ['198.51.100.0', 24],
  ['203.0.113.0', 24],
  ['224.0.0.0', 4],
  ['240.0.0.0', 4],
] as const) {
  NON_PUBLIC_ADDRESSES.addSubnet(network, prefix, 'ipv4')
}
for (const [network, prefix] of [
  ['::', 96],
  ['64:ff9b::', 96],
  ['64:ff9b:1::', 48],
  ['100::', 64],
  ['2001::', 23],
  ['2001:db8::', 32],
  ['2002::', 16],
  ['fc00::', 7],
  ['fe80::', 10],
  ['fec0::', 10],
  ['ff00::', 8],
] as const) {
  NON_PUBLIC_ADDRESSES.addSubnet(network, prefix, 'ipv6')
}

export function isPublicAddress(address: string): boolean {
  const family = isIP(address)
  if (family === 0) return false
  return !NON_PUBLIC_ADDRESSES.check(address, family === 4 ? 'ipv4' : 'ipv6')
}

async function lookupAddresses(hostname: string): Promise<string[]> {
  const records = await lookup(hostname, { all: true, verbatim: true })
  return records.map((record) => record.address)
}

/**
 * Whether every address `hostname` (a URL hostname, so IPv6 in brackets)
 * resolves to is public. Unresolvable hosts are not.
 */
export async function resolvesToPublicAddresses(
  hostname: string,
  resolve: AddressLookup = lookupAddresses,
): Promise<boolean> {
  const host =
    hostname.startsWith('[') && hostname.endsWith(']')
      ? hostname.slice(1, -1)
      : hostname
  if (isIP(host) !== 0) return isPublicAddress(host)

  let addresses: string[]
  try {
    addresses = await resolve(host)
  } catch {
    return false
  }
  return addresses.length > 0 && addresses.every(isPublicAddress)
}
