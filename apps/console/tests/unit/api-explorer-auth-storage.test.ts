import { afterEach, beforeEach, describe, expect, test } from 'bun:test'
import {
  API_EXPLORER_AUTH_LOCAL_STORAGE_KEY,
  API_EXPLORER_KEYS_SESSION_STORAGE_KEY,
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
  test('keeps API keys out of localStorage', () => {
    persistKeys()

    const stored = localStorage.getItem(API_EXPLORER_AUTH_LOCAL_STORAGE_KEY)
    expect(stored).not.toBeNull()
    expect(stored).not.toContain('standard_manual')
    expect(stored).not.toContain('ephemeral_key')

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

  test('ignores API keys left in localStorage', () => {
    localStorage.setItem(
      API_EXPLORER_AUTH_LOCAL_STORAGE_KEY,
      JSON.stringify({
        [projectId]: {
          serverAuth: { mode: 'manual', manualApiKey: 'standard_legacy' },
        },
      }),
    )

    expect(
      resolveApiExplorerAuthSnapshot(projectId, 'client').serverAuth
        .manualApiKey,
    ).toBe('')
  })

  test('sign-out clears the stored API keys', () => {
    persistKeys()

    clearConsoleSessionLocally()

    expect(localStorage.getItem(API_EXPLORER_AUTH_LOCAL_STORAGE_KEY)).toBeNull()
    expect(
      sessionStorage.getItem(API_EXPLORER_KEYS_SESSION_STORAGE_KEY),
    ).toBeNull()
    const { serverAuth } = resolveApiExplorerAuthSnapshot(projectId, 'client')
    expect(serverAuth.manualApiKey).toBe('')
    expect(serverAuth.ephemeralApiKey).toBe('')
  })
})
