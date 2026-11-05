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
  /**
   * When true, matches live on another settings section and navigation is pending.
   * Card lists should not filter or show an empty state until the switch completes.
   */
  deferEmptyResults: boolean
}

const SettingsSearchContext = createContext<Omit<
  SettingsSearchContextValue,
  'deferEmptyResults'
> | null>(null)

const DeferEmptyResultsContext = createContext(false)

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

export function DeferEmptyResultsProvider({
  deferEmptyResults,
  children,
}: {
  deferEmptyResults: boolean
  children: ReactNode
}) {
  return (
    <DeferEmptyResultsContext.Provider value={deferEmptyResults}>
      {children}
    </DeferEmptyResultsContext.Provider>
  )
}

export function useSettingsSearch(): SettingsSearchContextValue {
  const ctx = useContext(SettingsSearchContext)
  const deferEmptyResults = useContext(DeferEmptyResultsContext)
  if (!ctx) {
    throw new Error('useSettingsSearch must be used within SettingsSearchProvider')
  }
  return { ...ctx, deferEmptyResults }
}
