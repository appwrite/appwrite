import type {
  ApiExplorerClientAuthState,
  ApiExplorerProjectPlatform,
  ApiExplorerServerAuthState,
  ApiExplorerServerAuthMode,
  ApiExplorerSessionAuthMode,
} from './types'

export const API_EXPLORER_AUTH_LOCAL_STORAGE_KEY = 'console.apiExplorer.auth'

export const DEFAULT_API_EXPLORER_CLIENT_AUTH: ApiExplorerClientAuthState = {
  mode: 'guest',
  userId: '',
}

export const DEFAULT_API_EXPLORER_SERVER_AUTH: ApiExplorerServerAuthState = {
  mode: 'manual',
  manualApiKey: '',
  ephemeralApiKey: '',
  ephemeralDraftScopes: [],
  ephemeralKeyScopes: [],
}

export type ApiExplorerProjectAuthSnapshot = {
  platform: ApiExplorerProjectPlatform
  clientAuth: ApiExplorerClientAuthState
  serverAuth: ApiExplorerServerAuthState
}

type ApiExplorerAuthStoragePayload = Record<string, unknown>

type ApiExplorerKeys = Pick<
  ApiExplorerServerAuthState,
  'manualApiKey' | 'ephemeralApiKey'
>

function parsePlatform(value: unknown): ApiExplorerProjectPlatform | undefined {
  if (value === 'client' || value === 'server') return value
  return undefined
}

function parseSessionAuthMode(value: unknown): ApiExplorerSessionAuthMode {
  return value === 'user' ? 'user' : 'guest'
}

function parseServerAuthMode(value: unknown): ApiExplorerServerAuthMode {
  return value === 'ephemeral' ? 'ephemeral' : 'manual'
}

function parseStringArray(value: unknown): string[] {
  if (!Array.isArray(value)) return []
  return value.filter((item): item is string => typeof item === 'string')
}

export function parseApiExplorerClientAuth(
  raw: unknown,
): ApiExplorerClientAuthState {
  if (!raw || typeof raw !== 'object') {
    return { ...DEFAULT_API_EXPLORER_CLIENT_AUTH }
  }

  const entry = raw as Record<string, unknown>
  return {
    mode: parseSessionAuthMode(entry.mode),
    userId: typeof entry.userId === 'string' ? entry.userId : '',
  }
}

export function parseApiExplorerServerAuth(
  raw: unknown,
): ApiExplorerServerAuthState {
  if (!raw || typeof raw !== 'object') {
    return { ...DEFAULT_API_EXPLORER_SERVER_AUTH }
  }

  const entry = raw as Record<string, unknown>
  return {
    mode: parseServerAuthMode(entry.mode),
    manualApiKey:
      typeof entry.manualApiKey === 'string' ? entry.manualApiKey : '',
    ephemeralApiKey:
      typeof entry.ephemeralApiKey === 'string' ? entry.ephemeralApiKey : '',
    ephemeralDraftScopes: parseStringArray(entry.ephemeralDraftScopes),
    ephemeralKeyScopes: parseStringArray(entry.ephemeralKeyScopes),
  }
}

const keysByProject = new Map<string, ApiExplorerKeys>()

function withoutKeys(entry: unknown): unknown {
  if (!entry || typeof entry !== 'object') return entry
  const record = entry as Record<string, unknown>
  if (!record.serverAuth || typeof record.serverAuth !== 'object') return entry
  const serverAuth = record.serverAuth as Record<string, unknown>
  return {
    ...record,
    serverAuth: {
      mode: serverAuth.mode,
      ephemeralDraftScopes: serverAuth.ephemeralDraftScopes,
      ephemeralKeyScopes: serverAuth.ephemeralKeyScopes,
    },
  }
}

function hasKeys(entry: unknown): boolean {
  if (!entry || typeof entry !== 'object') return false
  const serverAuth = (entry as Record<string, unknown>).serverAuth
  if (!serverAuth || typeof serverAuth !== 'object') return false
  return 'manualApiKey' in serverAuth || 'ephemeralApiKey' in serverAuth
}

function writeStoragePayload(payload: ApiExplorerAuthStoragePayload): void {
  localStorage.setItem(
    API_EXPLORER_AUTH_LOCAL_STORAGE_KEY,
    JSON.stringify(payload),
  )
}

function readStoragePayload(): ApiExplorerAuthStoragePayload {
  try {
    const raw = localStorage.getItem(API_EXPLORER_AUTH_LOCAL_STORAGE_KEY)
    if (!raw) return {}
    const parsed: unknown = JSON.parse(raw)
    if (!parsed || typeof parsed !== 'object') return {}

    const payload = parsed as ApiExplorerAuthStoragePayload
    if (!Object.values(payload).some(hasKeys)) return payload

    const scrubbed = Object.fromEntries(
      Object.entries(payload).map(([projectId, entry]) => [
        projectId,
        withoutKeys(entry),
      ]),
    )
    writeStoragePayload(scrubbed)
    return scrubbed
  } catch {
    return {}
  }
}

function readKeys(projectId: string): ApiExplorerKeys {
  return (
    keysByProject.get(projectId) ?? { manualApiKey: '', ephemeralApiKey: '' }
  )
}

export function readApiExplorerAuthFromStorage(
  projectId: string,
): Partial<ApiExplorerProjectAuthSnapshot> | null {
  if (typeof window === 'undefined' || !projectId) return null

  const entry = readStoragePayload()[projectId]
  if (!entry || typeof entry !== 'object') return null

  const record = entry as Record<string, unknown>
  const platform = parsePlatform(record.platform)
  const partial: Partial<ApiExplorerProjectAuthSnapshot> = {}

  if (platform) {
    partial.platform = platform
  }
  if (record.clientAuth !== undefined) {
    partial.clientAuth = parseApiExplorerClientAuth(record.clientAuth)
  }
  if (record.serverAuth !== undefined) {
    partial.serverAuth = {
      ...parseApiExplorerServerAuth(record.serverAuth),
      ...readKeys(projectId),
    }
  }

  if (!partial.platform && !partial.clientAuth && !partial.serverAuth) {
    return null
  }

  return partial
}

export function writeApiExplorerAuthToStorage(
  projectId: string,
  snapshot: ApiExplorerProjectAuthSnapshot,
): void {
  if (typeof window === 'undefined' || !projectId) return

  const { manualApiKey, ephemeralApiKey } = snapshot.serverAuth
  keysByProject.set(projectId, { manualApiKey, ephemeralApiKey })

  try {
    const payload = readStoragePayload()
    payload[projectId] = withoutKeys(snapshot)
    writeStoragePayload(payload)
  } catch {
    /* private mode / quota */
  }
}

export function clearApiExplorerAuthStorage(): void {
  keysByProject.clear()
  if (typeof window === 'undefined') return

  try {
    localStorage.removeItem(API_EXPLORER_AUTH_LOCAL_STORAGE_KEY)
  } catch {
    /* private mode */
  }
}

export function resolveApiExplorerAuthSnapshot(
  projectId: string,
  fallbackPlatform: ApiExplorerProjectPlatform,
): ApiExplorerProjectAuthSnapshot {
  const stored = readApiExplorerAuthFromStorage(projectId)
  return {
    platform: stored?.platform ?? fallbackPlatform,
    clientAuth: stored?.clientAuth ?? { ...DEFAULT_API_EXPLORER_CLIENT_AUTH },
    serverAuth: stored?.serverAuth ?? { ...DEFAULT_API_EXPLORER_SERVER_AUTH },
  }
}
