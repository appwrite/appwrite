'use client'

import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react'

export type ConsoleRightPaneContent = 'docs' | 'assistant'

type ConsoleRightPaneContextValue = {
  activeContent: ConsoleRightPaneContent | null
  showDocs: () => void
  showAssistant: () => void
  hideRightPane: () => void
}

const ConsoleRightPaneContext = createContext<ConsoleRightPaneContextValue | null>(
  null,
)

const noopConsoleRightPaneContext: ConsoleRightPaneContextValue = {
  activeContent: null,
  showDocs: () => {},
  showAssistant: () => {},
  hideRightPane: () => {},
}

export function useConsoleRightPane() {
  return useContext(ConsoleRightPaneContext) ?? noopConsoleRightPaneContext
}

export function ConsoleRightPaneProvider({ children }: { children: ReactNode }) {
  const [activeContent, setActiveContent] =
    useState<ConsoleRightPaneContent | null>(null)

  const showDocs = useCallback(() => {
    setActiveContent('docs')
  }, [])

  const showAssistant = useCallback(() => {
    setActiveContent('assistant')
  }, [])

  const hideRightPane = useCallback(() => {
    setActiveContent(null)
  }, [])

  const value = useMemo(
    () => ({
      activeContent,
      showDocs,
      showAssistant,
      hideRightPane,
    }),
    [activeContent, hideRightPane, showAssistant, showDocs],
  )

  return (
    <ConsoleRightPaneContext.Provider value={value}>
      {children}
    </ConsoleRightPaneContext.Provider>
  )
}
