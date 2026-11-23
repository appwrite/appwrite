'use client'

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { CommandCenter } from '@/components/global/shared/CommandCenter'
import { useGlobalCommandShortcuts } from '@/lib/keyboard-shortcuts/use-global-command-shortcuts'
import { registerCommandCenterOpener } from '@/lib/command-center/opener-bridge'

type DocsCommandCenterContextValue = {
  openDocsSearch: () => void
  closeDocsSearch: () => void
  isDocsSearchOpen: boolean
}

const DocsCommandCenterContext =
  createContext<DocsCommandCenterContextValue | null>(null)

export function useDocsSearchContext() {
  return useContext(DocsCommandCenterContext)
}

type DocsSearchProviderProps = {
  children: ReactNode
}

export function DocsSearchProvider({ children }: DocsSearchProviderProps) {
  const [commandCenterOpen, setCommandCenterOpen] = useState(false)
  const [initialSubPage, setInitialSubPage] = useState<string | null>(null)

  const openDocsSearch = useCallback(() => {
    setInitialSubPage('docs')
    setCommandCenterOpen(true)
  }, [])

  const closeDocsSearch = useCallback(() => {
    setCommandCenterOpen(false)
    setInitialSubPage(null)
  }, [])

  const openShortcutsHelp = useCallback(() => {
    setInitialSubPage('shortcuts')
    setCommandCenterOpen(true)
  }, [])

  useEffect(() => {
    return registerCommandCenterOpener((page) => {
      setInitialSubPage(page)
      setCommandCenterOpen(true)
    })
  }, [])

  useGlobalCommandShortcuts({
    commandCenterOpen,
    onOpenCommandCenter: openDocsSearch,
    onOpenShortcutsHelp: openShortcutsHelp,
  })

  const contextValue = useMemo<DocsCommandCenterContextValue>(
    () => ({
      openDocsSearch,
      closeDocsSearch,
      isDocsSearchOpen: commandCenterOpen,
    }),
    [openDocsSearch, closeDocsSearch, commandCenterOpen],
  )

  return (
    <DocsCommandCenterContext.Provider value={contextValue}>
      {children}
      <CommandCenter
        context="docs"
        open={commandCenterOpen}
        onOpenChange={(open) => {
          setCommandCenterOpen(open)
          if (!open) setInitialSubPage(null)
        }}
        initialSubPage={initialSubPage}
        onInitialSubPageConsumed={() => setInitialSubPage(null)}
      />
    </DocsCommandCenterContext.Provider>
  )
}
