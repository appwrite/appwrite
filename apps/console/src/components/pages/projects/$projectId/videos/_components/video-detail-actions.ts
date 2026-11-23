import { createContext, useContext } from 'react'

export type VideoDetailActions = {
  openCreateRenditions: () => void
  openCreateCaption: () => void
  canWrite: boolean
  writeDisabledReason: string | null
}

export const VideoDetailActionsContext = createContext<VideoDetailActions>({
  openCreateRenditions: () => {},
  openCreateCaption: () => {},
  canWrite: false,
  writeDisabledReason: null,
})

export function useVideoDetailActions() {
  return useContext(VideoDetailActionsContext)
}
