import { describe, expect, test } from 'bun:test'
import { describeTransferGate } from '@/components/pages/transfer/AuthGate'
import type { ZeroProject } from '@/lib/appwrite/zero'
import { resolveMagicUrlRedirect } from '@/lib/post-auth-navigation'
import { getZeroPhase } from '@/lib/zero/phase'
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

describe('getZeroPhase', () => {
  const now = Date.parse('2026-10-30T12:00:00Z')
  const deadlines = {
    expiresAt: '2026-10-30T13:00:00Z',
    claimBy: '2026-11-06T12:00:00Z',
  }

  test('reads the status the server reports', () => {
    expect(getZeroPhase({ status: 'active', ...deadlines }, now)).toBe(
      'building',
    )
    expect(getZeroPhase({ status: 'expired', ...deadlines }, now)).toBe(
      'expired',
    )
    expect(getZeroPhase({ status: 'claimed', ...deadlines }, now)).toBe(
      'claimed',
    )
    expect(getZeroPhase({ status: 'deleted', ...deadlines }, now)).toBe(
      'deleted',
    )
  })

  test('treats a passed claim deadline as deleted before the next poll', () => {
    expect(
      getZeroPhase(
        {
          status: 'expired',
          expiresAt: '2026-10-30T10:00:00Z',
          claimBy: '2026-10-30T11:59:59Z',
        },
        now,
      ),
    ).toBe('deleted')
  })

  test('treats a passed build deadline as expired before the next poll', () => {
    expect(
      getZeroPhase(
        {
          status: 'active',
          expiresAt: '2026-10-30T11:00:00Z',
          claimBy: deadlines.claimBy,
        },
        now,
      ),
    ).toBe('expired')
  })
})

describe('describeTransferGate on a dead link', () => {
  test('a passed claim deadline offers no way into the claim', () => {
    const gate = describeTransferGate(
      (text: string) => text,
      Date.parse('2026-10-30T12:00:00Z'),
      {
        $id: 'zero',
        $createdAt: '2026-10-23T12:00:00Z',
        status: 'expired',
        organizationId: 'org',
        projectId: 'project',
        region: 'fra',
        endpoint: 'https://fra.example.com/v1',
        consoleUrl: 'https://console.example.com',
        expiresAt: '2026-10-23T13:00:00Z',
        claimBy: '2026-10-30T11:00:00Z',
      },
    )
    expect(gate.state).toBe('deleted')
    expect(gate.deadline).toBeNull()
  })
})

describe('resolveMagicUrlRedirect', () => {
  test('opens the project a sign-in link names, from its legacy console path', () => {
    expect(resolveMagicUrlRedirect('/console/project-fra-abc123')).toBe(
      '/projects/abc123',
    )
  })

  test('keeps a current console path and its query', () => {
    expect(resolveMagicUrlRedirect('/projects/abc123/auth?tab=users')).toBe(
      '/projects/abc123/auth?tab=users',
    )
  })

  test('falls back to the home page for anything but a same-origin path', () => {
    for (const redirect of [
      null,
      undefined,
      '',
      'projects/abc123',
      'https://evil.example.com/projects/abc123',
      'javascript:alert(1)',
      '//evil.example.com',
      '/\\evil.example.com',
      '/%2F%2Fevil.example.com',
      '/sign-in',
      '/auth/magic-url?userId=a&secret=b',
    ]) {
      expect(resolveMagicUrlRedirect(redirect)).toBe('/')
    }
  })
})
