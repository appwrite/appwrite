import { createContext, useContext, type ReactNode } from 'react'

export type CliTerminalSessionsLayoutMode = 'sidebar' | 'strip'

const CliTerminalLayoutContext = createContext<CliTerminalSessionsLayoutMode>(
  'sidebar',
)

export function CliTerminalLayoutProvider({
  mode,
  children,
}: {
  mode: CliTerminalSessionsLayoutMode
  children: ReactNode
}) {
  return (
    <CliTerminalLayoutContext.Provider value={mode}>
      {children}
    </CliTerminalLayoutContext.Provider>
  )
}

export function useCliTerminalSessionsLayoutMode(): CliTerminalSessionsLayoutMode {
  return useContext(CliTerminalLayoutContext)
}
