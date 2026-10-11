'use client'

import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import {
  resolveFenceCodeLabel,
  resolveFenceCodeLanguage,
} from '@/lib/code-language'
import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react'

type MultiCodeContextValue = {
  selected: string | null
  setSelected: (value: string) => void
  registerSnippet: (language: string, content: string) => void
  snippets: Map<string, string>
}

const MultiCodeContext = createContext<MultiCodeContextValue | null>(null)

export function useMultiCodeContext() {
  return useContext(MultiCodeContext)
}

export function MultiCode({ children }: { children: ReactNode }) {
  const [snippets, setSnippets] = useState(() => new Map<string, string>())
  const [selected, setSelected] = useState<string | null>(null)

  const registerSnippet = useCallback((language: string, content: string) => {
    setSnippets((prev) => {
      if (prev.get(language) === content) return prev
      const next = new Map(prev)
      next.set(language, content)
      return next
    })
    setSelected((current) => current ?? language)
  }, [])

  const value = useMemo(
    () => ({
      selected,
      setSelected: (lang: string) => setSelected(lang),
      registerSnippet,
      snippets,
    }),
    [selected, snippets, registerSnippet],
  )

  const languages = Array.from(snippets.keys())
  const activeLanguage = selected ?? languages[0] ?? null
  const activeContent = activeLanguage
    ? (snippets.get(activeLanguage) ?? '')
    : ''

  return (
    <MultiCodeContext.Provider value={value}>
      <div className="not-prose my-6 w-full">
        {activeLanguage && activeContent ? (
          <ConnectCodeExample
            code={activeContent}
            language={resolveFenceCodeLanguage(activeLanguage)}
            tabs={languages.map((lang) => ({
              id: lang,
              label: resolveFenceCodeLabel(lang),
            }))}
            activeTabId={activeLanguage}
            onTabChange={setSelected}
            selectorVariant="dropdown"
          />
        ) : null}
        <div className="hidden" aria-hidden>
          {children}
        </div>
      </div>
    </MultiCodeContext.Provider>
  )
}
