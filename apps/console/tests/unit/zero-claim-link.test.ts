import { describe, expect, test } from 'bun:test'
import { describeTransferGate } from '@/components/pages/transfer/AuthGate'
import type { ZeroProject } from '@/lib/appwrite/zero'
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

describe('describeTransferGate', () => {
  const t = (text: string) => text
  const now = Date.parse('2026-10-30T12:00:00Z')
  const zero = (status: ZeroProject['status']): ZeroProject => ({
    $id: 'zero',
    $createdAt: '2026-10-30T11:00:00Z',
    status,
    organizationId: 'org',
    projectId: 'project',
    region: 'fra',
    endpoint: 'https://fra.example.com/v1',
    consoleUrl: 'https://console.example.com',
    expiresAt: '2026-10-30T13:00:00Z',
    claimBy: '2026-11-06T12:00:00Z',
  })

  test('offers to claim while the build window is open', () => {
    expect(describeTransferGate(t, now, zero('active')).state).toBe('claim')
  })

  test('shows a completed state once another session claimed the project', () => {
    const gate = describeTransferGate(t, now, zero('claimed'))
    expect(gate.state).toBe('claimed')
    expect(gate.title).toBe('Project already claimed')
    expect(gate.deadline).toBeNull()
  })

  test('shows the deleted state once the claim window has passed', () => {
    expect(describeTransferGate(t, now, zero('deleted')).state).toBe('deleted')
  })
})
