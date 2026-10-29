import { describe, expect, test } from 'bun:test'
import { getZeroTransferSearch } from '@/lib/zero/session'

describe('getZeroTransferSearch', () => {
  test('reads the transfer from an absolute claim link on any host', () => {
    expect(
      getZeroTransferSearch(
        'https://console.example.com/transfer?transferId=abc&secret=s%2B1',
      ),
    ).toEqual({ transferId: 'abc', secret: 's+1' })
  })

  test('reads the transfer from a relative claim link', () => {
    expect(
      getZeroTransferSearch('/transfer?transferId=abc&secret=xyz'),
    ).toEqual({ transferId: 'abc', secret: 'xyz' })
  })

  test('returns nothing when the link lacks the transfer or its secret', () => {
    expect(getZeroTransferSearch(undefined)).toBeNull()
    expect(getZeroTransferSearch('/transfer?transferId=abc')).toBeNull()
    expect(getZeroTransferSearch('/transfer?secret=xyz')).toBeNull()
  })
})
