'use client'

import { createContext, useContext, type ReactNode } from 'react'

type DocsPromptContextValue = {
  promptText: string | null
}

const DocsPromptContext = createContext<DocsPromptContextValue>({ promptText: null })

type DocsPromptProviderProps = {
  promptText: string | null
  children: ReactNode
}

export function DocsPromptProvider({ promptText, children }: DocsPromptProviderProps) {
  return (
    <DocsPromptContext.Provider value={{ promptText }}>
      {children}
    </DocsPromptContext.Provider>
  )
}

export function useDocsPrompt(): DocsPromptContextValue {
  return useContext(DocsPromptContext)
}
