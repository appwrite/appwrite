import {
  createContext,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react'

type SettingsSearchContextValue = {
  query: string
  setQuery: (value: string) => void
}

const SettingsSearchContext = createContext<SettingsSearchContextValue | null>(
  null,
)

export function SettingsSearchProvider({
  query,
  onQueryChange,
  children,
}: {
  query: string
  onQueryChange: (value: string) => void
  children: ReactNode
}) {
  const value = useMemo(
    () => ({ query, setQuery: onQueryChange }),
    [query, onQueryChange],
  )
  return (
    <SettingsSearchContext.Provider value={value}>
      {children}
    </SettingsSearchContext.Provider>
  )
}

/** Local provider when search state is owned by the layout shell. */
export function SettingsSearchProviderLocal({
  children,
}: {
  children: ReactNode
}) {
  const [query, setQuery] = useState('')
  return (
    <SettingsSearchProvider query={query} onQueryChange={setQuery}>
      {children}
    </SettingsSearchProvider>
  )
}

export function useSettingsSearch(): SettingsSearchContextValue {
  const ctx = useContext(SettingsSearchContext)
  if (!ctx) {
    throw new Error('useSettingsSearch must be used within SettingsSearchProvider')
  }
  return ctx
}
