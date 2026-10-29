/**
 * Which resources the import wizard asks the server to migrate.
 *
 * The failure mode here is silent and total: the server migrates exactly what
 * the `resources` array names, and anything missing from it is skipped without
 * an error -- the migration still reports "completed" with an empty error list.
 * A shipped console dropped teams, memberships, functions and all of messaging
 * this way, so the checkboxes rendered but changed nothing.
 *
 * Several resources also cannot travel alone: the exporter reads their parent
 * out of the transfer cache (empty when the parent was not requested) or the
 * importer resolves the parent by id and throws. Those pairings are asserted
 * exhaustively rather than by example, since a missed one is invisible.
 */

import { describe, expect, test } from 'bun:test'
import { AppwriteMigrationResource } from '@appwrite.io/console'
import {
  INITIAL_RESOURCE_FORM,
  resourceFormToResources,
  type ResourceFormState,
} from '@/lib/migrations/resource-selection'

/**
 * What the server needs alongside each resource, stated here from the migration
 * library's own behavior rather than read back from the wizard:
 *
 * - membership: exportMemberships walks the exported teams and looks each
 *   member up among the exported users
 * - subscriber: exportSubscribers walks the exported topics, and the importer
 *   resolves the subscriber's user before writing it
 * - table/column/index/row, file: the exporter walks its exported parent
 * - environment-variable, deployment: the exporter walks the exported functions
 */
const DEPENDENCIES: Record<string, string[]> = {
  membership: ['user', 'team'],
  subscriber: ['topic', 'user'],
  table: ['database'],
  column: ['database', 'table'],
  index: ['database', 'table', 'column'],
  row: ['database', 'table', 'column'],
  file: ['bucket'],
  'environment-variable': ['function'],
  deployment: ['function'],
}

const everything: ResourceFormState = {
  users: { root: true, teams: true },
  databases: { root: true, rows: true },
  storage: { root: true },
  functions: { root: true, env: true, inactive: true },
  messaging: { root: true, messages: true },
}

/** Every reachable checkbox combination. */
function allForms(): ResourceFormState[] {
  const forms: ResourceFormState[] = []
  for (let bits = 0; bits < 1 << 10; bits++) {
    const bit = (n: number) => (bits & (1 << n)) !== 0
    forms.push({
      users: { root: bit(0), teams: bit(1) },
      databases: { root: bit(2), rows: bit(3) },
      storage: { root: bit(4) },
      functions: { root: bit(5), env: bit(6), inactive: bit(7) },
      messaging: { root: bit(8), messages: bit(9) },
    })
  }
  return forms
}

describe('resourceFormToResources, Appwrite', () => {
  test('a full selection asks for teams, memberships, functions and messaging', () => {
    const resources = resourceFormToResources(everything, 'appwrite')

    expect(resources).toContain(AppwriteMigrationResource.Team)
    expect(resources).toContain(AppwriteMigrationResource.Membership)
    expect(resources).toContain(AppwriteMigrationResource.Function)
    expect(resources).toContain(AppwriteMigrationResource.Environmentvariable)
    expect(resources).toContain(AppwriteMigrationResource.Deployment)
    expect(resources).toContain(AppwriteMigrationResource.Provider)
    expect(resources).toContain(AppwriteMigrationResource.Topic)
    expect(resources).toContain(AppwriteMigrationResource.Subscriber)
    expect(resources).toContain(AppwriteMigrationResource.Message)
  })

  test('the teams checkbox is what carries teams and memberships', () => {
    const withoutTeams = resourceFormToResources(
      { ...everything, users: { root: true, teams: false } },
      'appwrite',
    )

    expect(withoutTeams).not.toContain(AppwriteMigrationResource.Team)
    expect(withoutTeams).not.toContain(AppwriteMigrationResource.Membership)
  })

  test('subscribers are only requested alongside their users', () => {
    const messagingOnly = resourceFormToResources(
      {
        ...INITIAL_RESOURCE_FORM,
        messaging: { root: true, messages: false },
      },
      'appwrite',
    )

    expect(messagingOnly).toContain(AppwriteMigrationResource.Topic)
    expect(messagingOnly).not.toContain(AppwriteMigrationResource.Subscriber)
  })

  test('an empty form asks for nothing', () => {
    expect(resourceFormToResources(INITIAL_RESOURCE_FORM, 'appwrite')).toEqual(
      [],
    )
  })

  test('users are requested before the messaging resources that need them', () => {
    const resources = resourceFormToResources(everything, 'appwrite')

    // The server groups the array and runs each group in first-appearance
    // order, so a subscriber reaching the destination before its user fails.
    expect(resources.indexOf(AppwriteMigrationResource.User)).toBeLessThan(
      resources.indexOf(AppwriteMigrationResource.Subscriber),
    )
  })

  test('every selection satisfies each resource dependency', () => {
    for (const form of allForms()) {
      const resources = new Set<string>(
        resourceFormToResources(form, 'appwrite'),
      )

      for (const [resource, requires] of Object.entries(DEPENDENCIES)) {
        if (!resources.has(resource)) continue

        for (const required of requires) {
          expect({
            form,
            resource,
            missing: resources.has(required) ? null : required,
          }).toEqual({ form, resource, missing: null })
        }
      }
    }
  })
})
