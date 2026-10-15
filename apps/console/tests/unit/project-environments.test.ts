import { afterEach, describe, expect, test } from 'bun:test'
import {
  projectEnvironmentDotClass,
  projectEnvironmentStorageKey,
  readStoredProjectEnvironmentId,
  resolveProjectEnvironmentSelection,
  writeStoredProjectEnvironmentId,
  type ProjectEnvironmentOption,
  type ProjectEnvironmentOptions,
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

describe('resolveProjectEnvironmentSelection', () => {
  const options: ProjectEnvironmentOptions = [PROJECT, STAGING, PREVIEW]

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

  test('reports a stored id as stale even while only the project is listed', () => {
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
  const PROJECT_DOT = projectEnvironmentDotClass(PROJECT)
  const environmentDots = [
    'staging',
    'preview',
    'eu',
    'a',
    '0',
    'z'.repeat(36),
  ].map((id) => projectEnvironmentDotClass({ id, name: id, isDefault: false }))

  test('gives the project the same color whatever its id', () => {
    expect(
      projectEnvironmentDotClass({ ...PROJECT, id: 'anything-else' }),
    ).toBe(PROJECT_DOT)
  })

  test('spreads environments across the palette instead of collapsing them', () => {
    // The color is derived from the id precisely so two environments do not
    // look alike; a constant or broken hash collapses these to one class.
    expect(new Set(environmentDots).size).toBeGreaterThan(1)
  })

  test('reserves the project color for the project', () => {
    expect(environmentDots).not.toContain(PROJECT_DOT)
  })
})
