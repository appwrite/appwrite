import type { SupportedLanguage } from '@/lib/i18n/active-language'

type Dictionary = Record<string, string>

const cache: Partial<Record<SupportedLanguage, Dictionary>> = {}
const inflight = new Map<SupportedLanguage, Promise<Dictionary | undefined>>()

let version = 0
const listeners = new Set<() => void>()

function emit() {
  version += 1
  for (const listener of listeners) {
    listener()
  }
}

const loaders: Partial<
  Record<SupportedLanguage, () => Promise<Dictionary>>
> = {
  he: () =>
    import('@/lib/i18n/dictionaries/he').then((module) => module.heDictionary),
  ja: () =>
    import('@/lib/i18n/dictionaries/ja').then((module) => module.jaDictionary),
}

/**
 * he/ja dictionaries are ~3MB of source. Keep them out of the English
 * first-load graph so public pages do not parse unused translations (INP/LCP).
 */
export function subscribeLanguageDictionaries(onStoreChange: () => void) {
  listeners.add(onStoreChange)
  return () => {
    listeners.delete(onStoreChange)
  }
}

export function getLanguageDictionariesVersion() {
  return version
}

export function getLanguageDictionary(
  language: SupportedLanguage,
): Dictionary | undefined {
  if (language === 'en') return undefined
  return cache[language]
}

export function ensureLanguageDictionary(
  language: SupportedLanguage,
): Promise<Dictionary | undefined> {
  if (language === 'en') return Promise.resolve(undefined)

  const cached = cache[language]
  if (cached) return Promise.resolve(cached)

  const pending = inflight.get(language)
  if (pending) return pending

  const loader = loaders[language]
  if (!loader) return Promise.resolve(undefined)

  const request = loader()
    .then((dictionary) => {
      cache[language] = dictionary
      inflight.delete(language)
      emit()
      return dictionary
    })
    .catch((error: unknown) => {
      inflight.delete(language)
      throw error
    })

  inflight.set(language, request)
  return request
}
