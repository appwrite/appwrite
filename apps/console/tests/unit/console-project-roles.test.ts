/**
 * Encoding and decoding of per-project membership roles.
 *
 * These strings are an access-control boundary, and the failure mode is silent:
 * a role that fails to parse is not an error anywhere, it simply disappears, and
 * the member loses (or, if a foreign string were to parse, gains) access. The
 * dash-splitting cases matter most -- Appwrite project ids routinely contain
 * dashes, so the role name is the trailing segment rather than the second one.
 */

import { describe, expect, test } from 'bun:test'
import {
  buildProjectRole,
  hasProjectSpecificRoles,
  isProjectSpecificRole,
  parseProjectAccess,
  parseProjectRole,
  projectIdsFromRoles,
} from '@/lib/console-project-roles'

describe('isProjectSpecificRole', () => {
  test('accepts every supported project role', () => {
    for (const role of ['owner', 'developer', 'editor', 'analyst']) {
      expect(isProjectSpecificRole(`project-abc-${role}`)).toBe(true)
    }
  })

  test('rejects plain organization roles', () => {
    for (const role of ['owner', 'developer', 'editor', 'analyst', 'billing']) {
      expect(isProjectSpecificRole(role)).toBe(false)
    }
  })

  test('rejects billing, which is organization-level only', () => {
    expect(isProjectSpecificRole('project-abc-billing')).toBe(false)
  })

  test('rejects an unknown trailing role', () => {
    expect(isProjectSpecificRole('project-abc-superuser')).toBe(false)
  })

  test('rejects a missing project id, consistently with the parser', () => {
    expect(isProjectSpecificRole('project--developer')).toBe(false)
    expect(parseProjectRole('project--developer')).toBeNull()
  })

  test('rejects a bare prefix', () => {
    expect(isProjectSpecificRole('project-developer')).toBe(false)
  })
})

describe('parseProjectRole', () => {
  test('splits on the last dash so dashed project ids survive', () => {
    expect(parseProjectRole('project-my-cool-app-editor')).toEqual({
      projectId: 'my-cool-app',
      roleName: 'editor',
    })
  })

  test('round-trips through buildProjectRole', () => {
    const encoded = buildProjectRole('68a1f2c-prod', 'analyst')
    expect(encoded).toBe('project-68a1f2c-prod-analyst')
    expect(parseProjectRole(encoded)).toEqual({
      projectId: '68a1f2c-prod',
      roleName: 'analyst',
    })
  })

  test('returns null for non-project roles', () => {
    expect(parseProjectRole('owner')).toBeNull()
    expect(parseProjectRole('')).toBeNull()
  })
})

describe('membership helpers', () => {
  const roles = [
    'project-alpha-developer',
    'project-beta-analyst',
    'not-a-project-role',
  ]

  test('parseProjectAccess keeps only the project-scoped entries', () => {
    expect(parseProjectAccess(roles)).toEqual([
      { projectId: 'alpha', roleName: 'developer' },
      { projectId: 'beta', roleName: 'analyst' },
    ])
  })

  test('projectIdsFromRoles lists reachable projects', () => {
    expect(projectIdsFromRoles(roles)).toEqual(['alpha', 'beta'])
  })

  test('hasProjectSpecificRoles distinguishes org-wide members', () => {
    expect(hasProjectSpecificRoles(['owner'])).toBe(false)
    expect(hasProjectSpecificRoles(roles)).toBe(true)
  })

  test('tolerates missing role arrays', () => {
    expect(parseProjectAccess(undefined)).toEqual([])
    expect(projectIdsFromRoles(null)).toEqual([])
    expect(hasProjectSpecificRoles(undefined)).toBe(false)
  })
})
