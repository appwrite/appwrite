import type { ApiExplorerProjectPlatform } from '@/lib/api-explorer/types'
import type { UserPrefs } from '@/lib/user-prefs-keys'
import {
  getDefaultReferencePlatform,
  isReferencePlatform,
  isReferenceVersion,
  type ReferencePlatform,
  type ReferenceVersion,
} from './constants'

/** Full key: `console.apiReference.ui` - JSON UI state for docs API reference explorer. */
export const USER_PREFS_KEY_API_REFERENCE_UI = 'console.apiReference.ui'

export const API_REFERENCE_UI_LOCAL_STORAGE_KEY = USER_PREFS_KEY_API_REFERENCE_UI

export type ApiReferenceCardId = 'parameters' | 'responses'

export type ApiReferenceCardPrefs = {
  parameters: boolean
  responses: boolean
}

export type ApiReferenceUiPrefs = {
  version: ReferenceVersion
  platformMode: ApiExplorerProjectPlatform
  clientPlatform: ReferencePlatform
  serverPlatform: ReferencePlatform
  cards: ApiReferenceCardPrefs
}

export const DEFAULT_API_REFERENCE_UI_PREFS: ApiReferenceUiPrefs = {
  version: 'cloud',
  platformMode: 'client',
  clientPlatform: getDefaultReferencePlatform('client'),
  serverPlatform: getDefaultReferencePlatform('server'),
  cards: {
    parameters: true,
    responses: true,
  },
}

type ApiReferenceUiPrefsJson = {
  version?: unknown
  platformMode?: unknown
  clientPlatform?: unknown
  serverPlatform?: unknown
  cards?: {
    parameters?: unknown
    responses?: unknown
  }
}

function parseCardPrefs(
  raw: ApiReferenceUiPrefsJson['cards'],
): ApiReferenceCardPrefs | null {
  if (!raw || typeof raw !== 'object') return null
  const parameters =
    typeof raw.parameters === 'boolean' ? raw.parameters : undefined
  const responses = typeof raw.responses === 'boolean' ? raw.responses : undefined
  if (parameters === undefined && responses === undefined) return null
  return {
    parameters: parameters ?? DEFAULT_API_REFERENCE_UI_PREFS.cards.parameters,
    responses: responses ?? DEFAULT_API_REFERENCE_UI_PREFS.cards.responses,
  }
}

function parsePlatformMode(
  value: unknown,
): ApiExplorerProjectPlatform | undefined {
  if (value === 'client' || value === 'server') return value
  return undefined
}

function parsePartialApiReferenceUiPrefs(
  raw: unknown,
): Partial<ApiReferenceUiPrefs> | null {
  if (!raw || typeof raw !== 'object') return null

  const json = raw as ApiReferenceUiPrefsJson
  const version =
    typeof json.version === 'string' && isReferenceVersion(json.version)
      ? json.version
      : undefined
  const platformMode = parsePlatformMode(json.platformMode)
  const clientPlatform =
    typeof json.clientPlatform === 'string' &&
    isReferencePlatform(json.clientPlatform)
      ? json.clientPlatform
      : undefined
  const serverPlatform =
    typeof json.serverPlatform === 'string' &&
    isReferencePlatform(json.serverPlatform)
      ? json.serverPlatform
      : undefined
  const cards = parseCardPrefs(json.cards)

  if (
    !version &&
    !platformMode &&
    !clientPlatform &&
    !serverPlatform &&
    !cards
  ) {
    return null
  }

  return {
    ...(version ? { version } : {}),
    ...(platformMode ? { platformMode } : {}),
    ...(clientPlatform ? { clientPlatform } : {}),
    ...(serverPlatform ? { serverPlatform } : {}),
    ...(cards ? { cards } : {}),
  }
}

export function parseApiReferenceUiPrefsFromJson(
  raw: unknown,
): Partial<ApiReferenceUiPrefs> | null {
  if (typeof raw === 'string') {
    if (raw.length === 0) return null
    try {
      return parsePartialApiReferenceUiPrefs(JSON.parse(raw))
    } catch {
      return null
    }
  }
  return parsePartialApiReferenceUiPrefs(raw)
}

export function parseApiReferenceUiPrefs(
  prefs: UserPrefs | null | undefined,
): Partial<ApiReferenceUiPrefs> | null {
  const raw = prefs?.[USER_PREFS_KEY_API_REFERENCE_UI]
  return parseApiReferenceUiPrefsFromJson(raw)
}

export function readApiReferenceUiPrefsFromLocalStorage(): Partial<ApiReferenceUiPrefs> | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = localStorage.getItem(API_REFERENCE_UI_LOCAL_STORAGE_KEY)
    if (!raw) return null
    return parseApiReferenceUiPrefsFromJson(raw)
  } catch {
    return null
  }
}

export function writeApiReferenceUiPrefsToLocalStorage(
  prefs: ApiReferenceUiPrefs,
): void {
  if (typeof window === 'undefined') return
  try {
    localStorage.setItem(
      API_REFERENCE_UI_LOCAL_STORAGE_KEY,
      JSON.stringify(prefs),
    )
  } catch {
    /* private mode */
  }
}

export function resolveApiReferenceUiPrefs(
  accountPrefs?: UserPrefs | null,
): ApiReferenceUiPrefs {
  const fromAccount = parseApiReferenceUiPrefs(accountPrefs)
  const fromStorage = readApiReferenceUiPrefsFromLocalStorage()
  return {
    ...DEFAULT_API_REFERENCE_UI_PREFS,
    ...fromStorage,
    ...fromAccount,
    cards: {
      ...DEFAULT_API_REFERENCE_UI_PREFS.cards,
      ...fromStorage?.cards,
      ...fromAccount?.cards,
    },
  }
}

export function mergeApiReferenceUiPrefs(
  current: ApiReferenceUiPrefs,
  patch: Partial<ApiReferenceUiPrefs>,
): ApiReferenceUiPrefs {
  return {
    ...current,
    ...patch,
    cards: {
      ...current.cards,
      ...patch.cards,
    },
  }
}

export function mergeApiReferenceUiPrefsIntoAccountPrefs(
  prefs: UserPrefs,
  uiPrefs: ApiReferenceUiPrefs,
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_API_REFERENCE_UI]: JSON.stringify(uiPrefs),
  }
}

export function getApiReferencePlatformForMode(
  prefs: ApiReferenceUiPrefs,
  mode: ApiExplorerProjectPlatform = prefs.platformMode,
): ReferencePlatform {
  return mode === 'client' ? prefs.clientPlatform : prefs.serverPlatform
}

export function getApiReferenceCardOpen(
  prefs: ApiReferenceUiPrefs,
  cardId: ApiReferenceCardId,
): boolean {
  return prefs.cards[cardId]
}
