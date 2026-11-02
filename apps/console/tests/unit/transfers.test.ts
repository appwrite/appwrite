import { describe, expect, test } from 'bun:test'
import { describeTransferGate } from '@/components/pages/transfer/AuthGate'
import {
  buildTransferPath,
  getTransferOrganizations,
  pickTransferOrganization,
} from '@/lib/transfers'

describe('project transfer organizations', () => {
  test('a member who owns no organization has none to claim into', () => {
    const organizations = getTransferOrganizations(['team-a', 'team-b'], {
      'team-a': ['developer'],
      'team-b': ['billing', 'analyst'],
    })

    expect(organizations.filter(({ eligible }) => eligible)).toEqual([])
    // An organization stays selected so the transfer can still be previewed.
    expect(pickTransferOrganization(organizations, '')).toBe('team-a')
  })

  test('only owned organizations can receive the project when roles are mixed', () => {
    const organizations = getTransferOrganizations(
      ['member-first', 'owned', 'also-owned'],
      {
        'member-first': ['developer'],
        owned: ['owner'],
        'also-owned': ['owner', 'billing'],
      },
    )

    expect(organizations).toEqual([
      { id: 'member-first', eligible: false },
      { id: 'owned', eligible: true },
      { id: 'also-owned', eligible: true },
    ])
    expect(pickTransferOrganization(organizations, '')).toBe('owned')
    expect(pickTransferOrganization(organizations, 'member-first')).toBe(
      'owned',
    )
    expect(pickTransferOrganization(organizations, 'also-owned')).toBe(
      'also-owned',
    )
  })

  test('leaves the decision to the server when roles are unknown', () => {
    const organizations = getTransferOrganizations(['team-a'], {})
    expect(organizations).toEqual([{ id: 'team-a', eligible: true }])
  })
})

describe('transfer links', () => {
  test('a project link carries no resource, so links shared before domain transfers still open', () => {
    expect(buildTransferPath('projects', 'transfer', 'secret')).toBe(
      '/transfer?transferId=transfer&secret=secret',
    )
  })

  test('a domain link names its resource, so the claim page asks the domains API', () => {
    const path = buildTransferPath('domains', 'transfer', 's+1/2')
    const search = new URLSearchParams(path.split('?')[1])

    expect(path.startsWith('/transfer?')).toBe(true)
    expect(search.get('resource')).toBe('domains')
    expect(search.get('transferId')).toBe('transfer')
    expect(search.get('secret')).toBe('s+1/2')
  })

  test('the sign-in gate for a domain link asks to claim the domain, whoever opened it', () => {
    const t = (text: string) => text
    const gate = describeTransferGate(t, Date.now(), undefined, 'domains')

    expect(gate.title).toBe('Claim domain')
    expect(gate.state).toBe('claim')
    expect(describeTransferGate(t, Date.now(), undefined).title).toBe(
      'Claim project',
    )
  })
})
