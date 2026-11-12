import { createContext, useContext } from 'react'

export type VideoDetailActions = {
  openCreateRenditions: () => void
  openCreateSubtitle: () => void
  canWrite: boolean
  writeDisabledReason: string | null
}

export const VideoDetailActionsContext = createContext<VideoDetailActions>({
  openCreateRenditions: () => {},
  openCreateSubtitle: () => {},
  canWrite: false,
  writeDisabledReason: null,
})

export function useVideoDetailActions() {
  return useContext(VideoDetailActionsContext)
}
