import {
  createContext,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react'

type PoliciesSettingsSearchContextValue = {
  query: string
  setQuery: (value: string) => void
}

const PoliciesSettingsSearchContext =
  createContext<PoliciesSettingsSearchContextValue | null>(null)

/** Persists policies sidebar search across policy sub-route navigations. */
export function PoliciesSettingsSearchProvider({
  children,
}: {
  children: ReactNode
}) {
  const [query, setQuery] = useState('')
  const value = useMemo(() => ({ query, setQuery }), [query])
  return (
    <PoliciesSettingsSearchContext.Provider value={value}>
      {children}
    </PoliciesSettingsSearchContext.Provider>
  )
}

export function usePoliciesSettingsSearch(): PoliciesSettingsSearchContextValue {
  const ctx = useContext(PoliciesSettingsSearchContext)
  if (!ctx) {
    throw new Error(
      'usePoliciesSettingsSearch must be used within PoliciesSettingsSearchProvider',
    )
  }
  return ctx
}
