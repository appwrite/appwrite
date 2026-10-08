'use client'

import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react'

export type ConsoleRightPaneContent = 'docs' | 'agent'

type ConsoleRightPaneContextValue = {
  activeContent: ConsoleRightPaneContent | null
  showDocs: () => void
  showAgent: () => void
  hideRightPane: () => void
}

const ConsoleRightPaneContext = createContext<ConsoleRightPaneContextValue | null>(
  null,
)

const noopConsoleRightPaneContext: ConsoleRightPaneContextValue = {
  activeContent: null,
  showDocs: () => {},
  showAgent: () => {},
  hideRightPane: () => {},
}

export function useConsoleRightPane() {
  return useContext(ConsoleRightPaneContext) ?? noopConsoleRightPaneContext
}

export function ConsoleRightPaneProvider({ children }: { children: ReactNode }) {
  const [activeContent, setActiveContent] =
    useState<ConsoleRightPaneContent | null>(null)

  const showDocs = useCallback(() => {
    setActiveContent((current) => (current === 'docs' ? current : 'docs'))
  }, [])

  const showAgent = useCallback(() => {
    setActiveContent((current) => (current === 'agent' ? current : 'agent'))
  }, [])

  const hideRightPane = useCallback(() => {
    setActiveContent((current) => (current === null ? current : null))
  }, [])

  const value = useMemo(
    () => ({
      activeContent,
      showDocs,
      showAgent,
      hideRightPane,
    }),
    [activeContent, hideRightPane, showAgent, showDocs],
  )

  return (
    <ConsoleRightPaneContext.Provider value={value}>
      {children}
    </ConsoleRightPaneContext.Provider>
  )
}
