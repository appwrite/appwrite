import { afterEach, describe, expect, test } from 'bun:test'
import { ID } from '@appwrite.io/console'
import {
  isValidProjectEnvironmentId,
  PROJECT_ENVIRONMENT_ID_MAX_LENGTH,
  projectEnvironmentDotClass,
  projectEnvironmentStorageKey,
  readStoredProjectEnvironmentId,
  resolveProjectEnvironmentSelection,
  writeStoredProjectEnvironmentId,
  type ProjectEnvironmentOption,
} from '@/lib/project-environments'

const PROJECT: ProjectEnvironmentOption = {
  id: 'proj0000000000000000001',
  name: 'Acme',
  isDefault: true,
}

const STAGING: ProjectEnvironmentOption = {
  id: 'staging',
  name: 'Staging',
  isDefault: false,
}

const PREVIEW: ProjectEnvironmentOption = {
  id: 'preview',
  name: 'Preview',
  isDefault: false,
}

describe('isValidProjectEnvironmentId', () => {
  test('accepts the ids the server-side ProjectId validator accepts', () => {
    expect(isValidProjectEnvironmentId('staging')).toBe(true)
    expect(isValidProjectEnvironmentId('staging-2')).toBe(true)
    expect(isValidProjectEnvironmentId('0')).toBe(true)
    expect(isValidProjectEnvironmentId('2026-eu-preview')).toBe(true)
    expect(isValidProjectEnvironmentId('a'.repeat(36))).toBe(true)
  })

  test('treats an empty id as valid, because create auto-generates one', () => {
    // The dialog leaves the field blank to mean `ID.unique()`, so a blank id
    // must not light up as an error before anything has been typed.
    expect(isValidProjectEnvironmentId('')).toBe(true)
  })

  test('rejects uppercase, underscores and periods the default format allows', () => {
    // Project-shaped ids are stricter than Appwrite resource ids: these are all
    // valid document ids but are rejected by `ProjectId`.
    expect(isValidProjectEnvironmentId('Staging')).toBe(false)
    expect(isValidProjectEnvironmentId('stagING')).toBe(false)
    expect(isValidProjectEnvironmentId('my_env')).toBe(false)
    expect(isValidProjectEnvironmentId('my.env')).toBe(false)
  })

  test('rejects a leading hyphen and other leading special characters', () => {
    expect(isValidProjectEnvironmentId('-staging')).toBe(false)
    expect(isValidProjectEnvironmentId('_staging')).toBe(false)
    expect(isValidProjectEnvironmentId('.staging')).toBe(false)
  })

  test('rejects spaces and anything outside a-z, 0-9 and hyphen', () => {
    expect(isValidProjectEnvironmentId('my env')).toBe(false)
    expect(isValidProjectEnvironmentId('staging ')).toBe(false)
    expect(isValidProjectEnvironmentId('env/1')).toBe(false)
    expect(isValidProjectEnvironmentId('env:1')).toBe(false)
    expect(isValidProjectEnvironmentId('sébastien')).toBe(false)
  })

  test('rejects an id longer than the server limit', () => {
    expect(isValidProjectEnvironmentId('a'.repeat(37))).toBe(false)
    expect(PROJECT_ENVIRONMENT_ID_MAX_LENGTH).toBe(36)
  })

  test('accepts the ID.unique() fallback the create hook sends', () => {
    // A blank field sends `ID.unique()`, which the server validates with the
    // same `ProjectId` rule: if that ever stopped being lowercase hex, every
    // auto-generated environment would be rejected with a 400.
    for (let attempt = 0; attempt < 50; attempt += 1) {
      const generated = ID.unique()
      expect(isValidProjectEnvironmentId(generated)).toBe(true)
    }
  })
})

describe('resolveProjectEnvironmentSelection', () => {
  const options = [PROJECT, STAGING, PREVIEW]

  test('falls back to the project when nothing is stored', () => {
    const { selected, isStale } = resolveProjectEnvironmentSelection(
      options,
      null,
    )
    expect(selected).toBe(PROJECT)
    expect(isStale).toBe(false)
  })

  test('selects the stored environment', () => {
    const { selected, isStale } = resolveProjectEnvironmentSelection(
      options,
      'preview',
    )
    expect(selected).toBe(PREVIEW)
    expect(isStale).toBe(false)
  })

  test('falls back to the project and reports a stored id that is gone', () => {
    // Deleted here or on another device: the id outlives the environment.
    const { selected, isStale } = resolveProjectEnvironmentSelection(
      options,
      'deleted-elsewhere',
    )
    expect(selected).toBe(PROJECT)
    expect(isStale).toBe(true)
  })

  test('is not stale while only the project is listed', () => {
    // The list is still in flight or failed, so nothing can be concluded about
    // a stored id yet; the caller gates clearing on a successful fetch.
    const { selected, isStale } = resolveProjectEnvironmentSelection(
      [PROJECT],
      'staging',
    )
    expect(selected).toBe(PROJECT)
    expect(isStale).toBe(true)
  })

  test('matches the project id itself without reporting it as stale', () => {
    const { selected, isStale } = resolveProjectEnvironmentSelection(
      options,
      PROJECT.id,
    )
    expect(selected).toBe(PROJECT)
    expect(isStale).toBe(false)
  })

  test('resolves nothing when there are no options at all', () => {
    const { selected, isStale } = resolveProjectEnvironmentSelection([], 'x')
    expect(selected).toBeUndefined()
    expect(isStale).toBe(true)
  })
})

describe('project environment selection storage', () => {
  const previousWindow = (globalThis as { window?: unknown }).window

  function withLocalStorage<T>(
    initial: Record<string, string>,
    run: (store: Map<string, string>) => T,
  ): T {
    const store = new Map(Object.entries(initial))
    ;(globalThis as { window?: unknown }).window = {
      localStorage: {
        getItem: (key: string) => store.get(key) ?? null,
        setItem: (key: string, value: string) => void store.set(key, value),
        removeItem: (key: string) => void store.delete(key),
      },
    }
    return run(store)
  }

  afterEach(() => {
    ;(globalThis as { window?: unknown }).window = previousWindow
  })

  test('keys the selection per project', () => {
    expect(projectEnvironmentStorageKey('proj1')).toBe(
      'console.projectEnvironment.proj1',
    )
    expect(projectEnvironmentStorageKey('proj2')).not.toBe(
      projectEnvironmentStorageKey('proj1'),
    )
  })

  test('reads nothing without a window, so SSR starts on the project', () => {
    ;(globalThis as { window?: unknown }).window = undefined
    expect(readStoredProjectEnvironmentId('proj1')).toBeNull()
  })

  test('reads back only the selection for that project', () => {
    withLocalStorage(
      {
        'console.projectEnvironment.proj1': 'staging',
        'console.projectEnvironment.proj2': 'preview',
      },
      () => {
        expect(readStoredProjectEnvironmentId('proj1')).toBe('staging')
        expect(readStoredProjectEnvironmentId('proj2')).toBe('preview')
        expect(readStoredProjectEnvironmentId('proj3')).toBeNull()
      },
    )
  })

  test('writes an environment id and clears it for the project itself', () => {
    withLocalStorage({}, (store) => {
      writeStoredProjectEnvironmentId('proj1', 'staging')
      expect(store.get('console.projectEnvironment.proj1')).toBe('staging')

      // `null` is the project: the key is removed rather than set to a value
      // that would then look like a missing environment on the next read.
      writeStoredProjectEnvironmentId('proj1', null)
      expect(store.has('console.projectEnvironment.proj1')).toBe(false)
      expect(readStoredProjectEnvironmentId('proj1')).toBeNull()
    })
  })

  test('leaves other projects alone when one selection changes', () => {
    withLocalStorage(
      { 'console.projectEnvironment.proj2': 'preview' },
      (store) => {
        writeStoredProjectEnvironmentId('proj1', 'staging')
        writeStoredProjectEnvironmentId('proj1', null)
        expect(store.get('console.projectEnvironment.proj2')).toBe('preview')
      },
    )
  })

  test('a round trip through storage resolves back to the same option', () => {
    withLocalStorage({}, () => {
      writeStoredProjectEnvironmentId(PROJECT.id, STAGING.id)
      const { selected, isStale } = resolveProjectEnvironmentSelection(
        [PROJECT, STAGING],
        readStoredProjectEnvironmentId(PROJECT.id),
      )
      expect(selected).toBe(STAGING)
      expect(isStale).toBe(false)
    })
  })
})

describe('projectEnvironmentDotClass', () => {
  test('always gives the project the first color', () => {
    expect(projectEnvironmentDotClass(PROJECT)).toBe('bg-emerald-500')
    expect(
      projectEnvironmentDotClass({ ...PROJECT, id: 'anything-else' }),
    ).toBe('bg-emerald-500')
  })

  test('keeps an environment color stable and never reuses the project color', () => {
    for (const id of ['staging', 'preview', 'eu', 'a', '0', 'z'.repeat(36)]) {
      const environment = { id, name: id, isDefault: false }
      const first = projectEnvironmentDotClass(environment)
      expect(first).toBe(projectEnvironmentDotClass(environment))
      expect(first).not.toBe('bg-emerald-500')
      expect(first).toMatch(/^bg-[a-z]+-500$/)
    }
  })
})
