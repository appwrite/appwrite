import { afterAll, beforeAll, describe, expect, test } from 'bun:test'
import {
  API_EXPLORER_AUTH_LOCAL_STORAGE_KEY,
  writeApiExplorerAuthToLocalStorage,
  DEFAULT_API_EXPLORER_CLIENT_AUTH,
} from '@/lib/api-explorer/auth-storage'

class MemoryStorage {
  private items = new Map<string, string>()
  getItem(key: string): string | null {
    return this.items.get(key) ?? null
  }
  setItem(key: string, value: string): void {
    this.items.set(key, value)
  }
  removeItem(key: string): void {
    this.items.delete(key)
  }
  clear(): void {
    this.items.clear()
  }
}

const replaced = ['window', 'document', 'localStorage', 'sessionStorage']
const saved = new Map(
  replaced.map((name) => [
    name,
    Object.getOwnPropertyDescriptor(globalThis, name),
  ]),
)
const localStorage = new MemoryStorage()

function setGlobal(name: string, value: unknown): void {
  Object.defineProperty(globalThis, name, {
    value,
    configurable: true,
    writable: true,
  })
}

beforeAll(() => {
  const sessionStorage = new MemoryStorage()
  setGlobal('localStorage', localStorage)
  setGlobal('sessionStorage', sessionStorage)
  setGlobal('document', { cookie: '' })
  setGlobal('window', {
    localStorage,
    sessionStorage,
    location: {
      hostname: 'cloud.appwrite.io',
      host: 'cloud.appwrite.io',
      protocol: 'https:',
      origin: 'https://cloud.appwrite.io',
    },
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => true,
  })
})

afterAll(() => {
  for (const [name, descriptor] of saved) {
    if (descriptor) {
      Object.defineProperty(globalThis, name, descriptor)
    } else {
      Reflect.deleteProperty(globalThis, name)
    }
  }
})

describe('clearConsoleSessionLocally', () => {
  test('removes API keys the explorer saved', async () => {
    writeApiExplorerAuthToLocalStorage('project', {
      platform: 'server',
      clientAuth: { ...DEFAULT_API_EXPLORER_CLIENT_AUTH },
      serverAuth: {
        mode: 'manual',
        manualApiKey: 'standard_key',
        ephemeralApiKey: '',
        ephemeralDraftScopes: [],
        ephemeralKeyScopes: [],
      },
    })
    expect(localStorage.getItem(API_EXPLORER_AUTH_LOCAL_STORAGE_KEY)).toContain(
      'standard_key',
    )

    const { clearConsoleSessionLocally } = await import('@/lib/appwrite/sdk')
    clearConsoleSessionLocally()

    expect(localStorage.getItem(API_EXPLORER_AUTH_LOCAL_STORAGE_KEY)).toBeNull()
  })
})
