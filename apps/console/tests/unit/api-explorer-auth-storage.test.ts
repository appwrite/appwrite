import { afterEach, beforeEach, describe, expect, test } from 'bun:test'
import {
  API_EXPLORER_AUTH_LOCAL_STORAGE_KEY,
  resolveApiExplorerAuthSnapshot,
  writeApiExplorerAuthToStorage,
} from '@/lib/api-explorer/auth-storage'
import { clearConsoleSessionLocally } from '@/lib/appwrite/sdk'

function memoryStorage(): Storage {
  const values = new Map<string, string>()
  return {
    get length() {
      return values.size
    },
    clear: () => values.clear(),
    key: (index: number) => [...values.keys()][index] ?? null,
    getItem: (key: string) => values.get(key) ?? null,
    setItem: (key: string, value: string) => void values.set(key, value),
    removeItem: (key: string) => void values.delete(key),
  }
}

const GLOBAL_NAMES = [
  'window',
  'document',
  'localStorage',
  'sessionStorage',
] as const

const originals = GLOBAL_NAMES.map((name) =>
  Object.getOwnPropertyDescriptor(globalThis, name),
)

function setGlobal(name: (typeof GLOBAL_NAMES)[number], value: unknown) {
  Object.defineProperty(globalThis, name, {
    value,
    configurable: true,
    writable: true,
  })
}

const projectId = 'explorer-project'

function persistKeys() {
  writeApiExplorerAuthToStorage(projectId, {
    platform: 'server',
    clientAuth: { mode: 'user', userId: 'user-1' },
    serverAuth: {
      mode: 'ephemeral',
      manualApiKey: 'standard_manual',
      ephemeralApiKey: 'ephemeral_key',
      ephemeralDraftScopes: ['users.read'],
      ephemeralKeyScopes: ['users.read'],
    },
  })
}

beforeEach(() => {
  setGlobal(
    'window',
    Object.assign(new EventTarget(), {
      location: { hostname: 'cloud.appwrite.io' },
    }),
  )
  setGlobal('document', { cookie: '' })
  setGlobal('localStorage', memoryStorage())
  setGlobal('sessionStorage', memoryStorage())
})

afterEach(() => {
  GLOBAL_NAMES.forEach((name, index) => {
    const original = originals[index]
    if (original) {
      Object.defineProperty(globalThis, name, original)
    } else {
      delete (globalThis as Record<string, unknown>)[name]
    }
  })
})

describe('API explorer auth storage', () => {
  test('keeps API keys out of browser storage', () => {
    persistKeys()

    const stored = localStorage.getItem(API_EXPLORER_AUTH_LOCAL_STORAGE_KEY)
    expect(stored).not.toBeNull()
    expect(stored).not.toContain('standard_manual')
    expect(stored).not.toContain('ephemeral_key')
    expect(sessionStorage.length).toBe(0)

    expect(resolveApiExplorerAuthSnapshot(projectId, 'client')).toEqual({
      platform: 'server',
      clientAuth: { mode: 'user', userId: 'user-1' },
      serverAuth: {
        mode: 'ephemeral',
        manualApiKey: 'standard_manual',
        ephemeralApiKey: 'ephemeral_key',
        ephemeralDraftScopes: ['users.read'],
        ephemeralKeyScopes: ['users.read'],
      },
    })
  })

  test('removes API keys left in localStorage for every project', () => {
    localStorage.setItem(
      API_EXPLORER_AUTH_LOCAL_STORAGE_KEY,
      JSON.stringify({
        'legacy-project': {
          serverAuth: { mode: 'manual', manualApiKey: 'standard_legacy' },
        },
        'other-project': {
          platform: 'server',
          serverAuth: {
            mode: 'ephemeral',
            ephemeralApiKey: 'ephemeral_legacy',
            ephemeralKeyScopes: ['users.read'],
          },
        },
      }),
    )

    expect(
      resolveApiExplorerAuthSnapshot('legacy-project', 'client').serverAuth
        .manualApiKey,
    ).toBe('')

    const stored = localStorage.getItem(API_EXPLORER_AUTH_LOCAL_STORAGE_KEY)
    expect(stored).not.toContain('standard_legacy')
    expect(stored).not.toContain('ephemeral_legacy')
    expect(
      resolveApiExplorerAuthSnapshot('other-project', 'client'),
    ).toMatchObject({
      platform: 'server',
      serverAuth: { mode: 'ephemeral', ephemeralKeyScopes: ['users.read'] },
    })
  })

  test('keeps stored preferences when legacy keys cannot be removed', () => {
    const storage = memoryStorage()
    storage.setItem(
      API_EXPLORER_AUTH_LOCAL_STORAGE_KEY,
      JSON.stringify({
        'read-only-project': {
          platform: 'server',
          serverAuth: { mode: 'ephemeral', manualApiKey: 'standard_legacy' },
        },
      }),
    )
    setGlobal('localStorage', {
      ...storage,
      setItem: () => {
        throw new DOMException('Storage is full', 'QuotaExceededError')
      },
    })

    expect(
      resolveApiExplorerAuthSnapshot('read-only-project', 'client'),
    ).toMatchObject({
      platform: 'server',
      serverAuth: { mode: 'ephemeral', manualApiKey: '' },
    })
  })

  test('falls back to defaults when storage cannot be read', () => {
    Object.defineProperty(globalThis, 'localStorage', {
      configurable: true,
      get: () => {
        throw new DOMException('Storage is disabled', 'SecurityError')
      },
    })

    expect(
      resolveApiExplorerAuthSnapshot('unreadable-project', 'client'),
    ).toEqual({
      platform: 'client',
      clientAuth: { mode: 'guest', userId: '' },
      serverAuth: {
        mode: 'manual',
        manualApiKey: '',
        ephemeralApiKey: '',
        ephemeralDraftScopes: [],
        ephemeralKeyScopes: [],
      },
    })
  })

  test('sign-out clears the stored API keys', () => {
    persistKeys()

    clearConsoleSessionLocally()

    expect(localStorage.getItem(API_EXPLORER_AUTH_LOCAL_STORAGE_KEY)).toBeNull()
    const { serverAuth } = resolveApiExplorerAuthSnapshot(projectId, 'client')
    expect(serverAuth.manualApiKey).toBe('')
    expect(serverAuth.ephemeralApiKey).toBe('')
  })
})
