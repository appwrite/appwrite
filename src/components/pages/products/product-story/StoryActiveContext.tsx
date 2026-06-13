import { createContext, useContext } from 'react'

const StoryActiveContext = createContext(false)

export function StoryActiveProvider({
  active,
  children,
}: {
  active: boolean
  children: React.ReactNode
}) {
  return (
    <StoryActiveContext.Provider value={active}>{children}</StoryActiveContext.Provider>
  )
}

export function useStoryActive() {
  return useContext(StoryActiveContext)
}
