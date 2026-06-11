'use client'

import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { useGlobalCommandShortcuts } from '@/lib/keyboard-shortcuts/use-global-command-shortcuts'
import { DocsSearch } from './DocsSearch'

type DocsSearchContextValue = {
  openDocsSearch: () => void
  closeDocsSearch: () => void
  isDocsSearchOpen: boolean
}

const DocsSearchContext = createContext<DocsSearchContextValue | null>(null)

export function useDocsSearchContext() {
  return useContext(DocsSearchContext)
}

type DocsSearchProviderProps = {
  children: ReactNode
}

export function DocsSearchProvider({ children }: DocsSearchProviderProps) {
  const [open, setOpen] = useState(false)

  const openDocsSearch = useCallback(() => {
    setOpen(true)
  }, [])

  const closeDocsSearch = useCallback(() => {
    setOpen(false)
  }, [])

  useGlobalCommandShortcuts({
    commandCenterOpen: open,
    onOpenCommandCenter: openDocsSearch,
    onOpenShortcutsHelp: openDocsSearch,
  })

  const contextValue = useMemo<DocsSearchContextValue>(
    () => ({
      openDocsSearch,
      closeDocsSearch,
      isDocsSearchOpen: open,
    }),
    [openDocsSearch, closeDocsSearch, open],
  )

  return (
    <DocsSearchContext.Provider value={contextValue}>
      {children}
      <DocsSearch open={open} onOpenChange={setOpen} />
    </DocsSearchContext.Provider>
  )
}
