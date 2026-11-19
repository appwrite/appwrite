import { describe, expect, test } from 'bun:test'
import {
  getTransferOrganizations,
  pickTransferOrganization,
} from '@/lib/project-transfer'

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
