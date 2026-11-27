import { describe, expect, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import { Globe, MoreHorizontal, Package } from 'lucide-react'
import {
  canCreateDistributionApp,
  canManageDistributionCredentials,
  canManageDistributionSubmissions,
  canPublishDistributionApp,
  canSeeProjectNavItem,
  canShowDistributionCredentials,
  canShowDistributionSubmissions,
  canWriteDistributionApp,
  type AccessCheckFeatures,
} from '@/lib/console-access-checks'
import {
  buildAllAvailableScopeIds,
  consoleKeyScopesToEditorRows,
  getScopeCategoryIcon,
  scopeEditorCategory,
  sortScopeCategories,
} from '@/lib/console-project-scopes'
import {
  DEFAULT_SCOPES,
  FULL_ACCESS,
  deriveAccessFromRolesScopes,
  type ConsoleAccess,
} from '@/lib/console-roles'

const DISTRIBUTION_SCOPES = [
  'distribution.read',
  'distribution.write',
  'distribution.credentials.read',
  'distribution.credentials.write',
  'distribution.submissions.read',
  'distribution.submissions.write',
] as const

type DistributionScope = (typeof DISTRIBUTION_SCOPES)[number]

const ACCESS_FIELD_BY_SCOPE: Record<DistributionScope, keyof ConsoleAccess> = {
  'distribution.read': 'canSeeDistribution',
  'distribution.write': 'canWriteDistribution',
  'distribution.credentials.read': 'canSeeDistributionCredentials',
  'distribution.credentials.write': 'canWriteDistributionCredentials',
  'distribution.submissions.read': 'canSeeDistributionSubmissions',
  'distribution.submissions.write': 'canWriteDistributionSubmissions',
}

const CHECK_BY_SCOPE: Record<
  DistributionScope,
  {
    name: string
    check: (access: ConsoleAccess, features: AccessCheckFeatures) => boolean
  }[]
> = {
  'distribution.read': [],
  'distribution.write': [
    { name: 'canCreateDistributionApp', check: canCreateDistributionApp },
    {
      name: 'canWriteDistributionApp',
      check: canWriteDistributionApp,
    },
  ],
  'distribution.credentials.read': [
    {
      name: 'canShowDistributionCredentials',
      check: canShowDistributionCredentials,
    },
  ],
  'distribution.credentials.write': [
    {
      name: 'canManageDistributionCredentials',
      check: canManageDistributionCredentials,
    },
  ],
  'distribution.submissions.read': [
    {
      name: 'canShowDistributionSubmissions',
      check: canShowDistributionSubmissions,
    },
  ],
  'distribution.submissions.write': [
    {
      name: 'canManageDistributionSubmissions',
      check: canManageDistributionSubmissions,
    },
  ],
}

const ORG_ROLES: AccessCheckFeatures = { orgRoles: true }
const NO_ORG_ROLES: AccessCheckFeatures = { orgRoles: false }
const NO_SCOPES = deriveAccessFromRolesScopes(['developer'], ['projects.read'])

function catalog(
  scopes: { id: string; category?: string }[],
): Models.ConsoleKeyScopeList {
  return {
    total: scopes.length,
    scopes: scopes.map(({ id, category = '' }) => ({
      $id: id,
      description: id,
      category,
      deprecated: false,
    })),
  }
}

const LIST = catalog([
  { id: 'sites.read', category: 'Sites' },
  { id: 'distribution.read', category: 'Distribution' },
  { id: 'distribution.credentials.write' },
])

describe('buildAllAvailableScopeIds', () => {
  test('drops distribution scopes while distribution is off', () => {
    expect(
      buildAllAvailableScopeIds(LIST, {
        isCloud: true,
        oauth2Server: true,
        distribution: false,
      }),
    ).toEqual(['sites.read'])
  })

  test('keeps distribution scopes once distribution is on', () => {
    expect(
      buildAllAvailableScopeIds(LIST, {
        isCloud: true,
        oauth2Server: true,
        distribution: true,
      }),
    ).toEqual([
      'sites.read',
      'distribution.read',
      'distribution.credentials.write',
    ])
  })
})

describe('consoleKeyScopesToEditorRows', () => {
  test('drops distribution rows while distribution is off', () => {
    const rows = consoleKeyScopesToEditorRows(LIST, {
      isCloud: true,
      oauth2Server: true,
      distribution: false,
    })
    expect(rows.map((row) => row.scope)).toEqual(['sites.read'])
  })

  test('groups distribution rows under Distribution with the package icon', () => {
    const rows = consoleKeyScopesToEditorRows(LIST, {
      isCloud: true,
      oauth2Server: true,
      distribution: true,
    })
    const distributionRows = rows.filter((row) =>
      row.scope.startsWith('distribution.'),
    )
    expect(distributionRows.map((row) => row.scope).sort()).toEqual([
      'distribution.credentials.write',
      'distribution.read',
    ])
    for (const row of distributionRows) {
      expect(row.category).toBe('Distribution')
      expect(row.icon).toBe(Package)
    }
  })
})

describe('distribution scope category', () => {
  test('infers Distribution from the scope id', () => {
    for (const scope of DISTRIBUTION_SCOPES) {
      expect(scopeEditorCategory(scope, undefined)).toBe('Distribution')
      expect(scopeEditorCategory(scope, 'Deprecated')).toBe('Distribution')
    }
  })

  test('maps the category and the scope id to the package icon', () => {
    expect(getScopeCategoryIcon('Distribution')).toBe(Package)
    expect(getScopeCategoryIcon('General', 'distribution.write')).toBe(Package)
    expect(getScopeCategoryIcon('General', 'sites.write')).toBe(Globe)
    expect(getScopeCategoryIcon('General')).toBe(MoreHorizontal)
  })

  test('orders Distribution right after Sites', () => {
    expect(
      sortScopeCategories(['Project', 'Distribution', 'Videos', 'Sites']),
    ).toEqual(['Videos', 'Sites', 'Distribution', 'Project'])
  })
})

describe('distribution access', () => {
  test('each scope grants only its own access field', () => {
    const fields = Object.values(ACCESS_FIELD_BY_SCOPE)
    for (const scope of DISTRIBUTION_SCOPES) {
      const access = deriveAccessFromRolesScopes(['developer'], [scope])
      for (const field of fields) {
        expect(access[field]).toBe(field === ACCESS_FIELD_BY_SCOPE[scope])
      }
    }
  })

  test('the full-access fallback holds all six scopes', () => {
    for (const scope of DISTRIBUTION_SCOPES) {
      expect(DEFAULT_SCOPES).toContain(scope)
      expect(FULL_ACCESS[ACCESS_FIELD_BY_SCOPE[scope]]).toBe(true)
    }
  })

  test('each check follows its scope when organization roles apply', () => {
    for (const scope of DISTRIBUTION_SCOPES) {
      const access = deriveAccessFromRolesScopes(['developer'], [scope])
      for (const { name, check } of CHECK_BY_SCOPE[scope]) {
        expect({ name, allowed: check(access, ORG_ROLES) }).toEqual({
          name,
          allowed: true,
        })
        expect({ name, allowed: check(NO_SCOPES, ORG_ROLES) }).toEqual({
          name,
          allowed: false,
        })
      }
    }
  })

  test('every check allows everything without organization roles', () => {
    for (const scope of DISTRIBUTION_SCOPES) {
      for (const { name, check } of CHECK_BY_SCOPE[scope]) {
        expect({ name, allowed: check(NO_SCOPES, NO_ORG_ROLES) }).toEqual({
          name,
          allowed: true,
        })
      }
    }
  })

  test('publishing needs both distribution.write and distribution.submissions.write', () => {
    const owner = deriveAccessFromRolesScopes(['owner'], [...DEFAULT_SCOPES])
    const readonly = deriveAccessFromRolesScopes(
      ['analyst'],
      ['projects.read', 'distribution.read', 'distribution.submissions.read'],
    )
    const writer = deriveAccessFromRolesScopes(
      ['developer'],
      ['projects.read', 'distribution.read', 'distribution.write'],
    )
    const submitter = deriveAccessFromRolesScopes(
      ['developer'],
      [
        'projects.read',
        'distribution.read',
        'distribution.submissions.read',
        'distribution.submissions.write',
      ],
    )

    expect(canPublishDistributionApp(owner, ORG_ROLES)).toBe(true)
    expect(canPublishDistributionApp(readonly, ORG_ROLES)).toBe(false)
    expect(canPublishDistributionApp(writer, ORG_ROLES)).toBe(false)
    expect(canPublishDistributionApp(submitter, ORG_ROLES)).toBe(false)
    expect(canPublishDistributionApp(NO_SCOPES, NO_ORG_ROLES)).toBe(true)
  })

  test('the sidebar item follows distribution.read', () => {
    const reader = deriveAccessFromRolesScopes(
      ['developer'],
      ['projects.read', 'distribution.read'],
    )
    expect(canSeeProjectNavItem(reader, ORG_ROLES, 'distribution')).toBe(true)
    expect(canSeeProjectNavItem(NO_SCOPES, ORG_ROLES, 'distribution')).toBe(
      false,
    )
    expect(canSeeProjectNavItem(NO_SCOPES, NO_ORG_ROLES, 'distribution')).toBe(
      true,
    )
  })
})
