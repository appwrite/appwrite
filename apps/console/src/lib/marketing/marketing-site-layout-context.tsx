import { createContext, useContext } from 'react'

const MarketingSiteLayoutContext = createContext(false)

export function MarketingSiteLayoutProvider({
  children,
}: {
  children: React.ReactNode
}) {
  return (
    <MarketingSiteLayoutContext.Provider value={true}>
      {children}
    </MarketingSiteLayoutContext.Provider>
  )
}

export function useMarketingSiteLayoutProvided(): boolean {
  return useContext(MarketingSiteLayoutContext)
}
