import { SCENE_EXIT_FRAMES } from './slide-transition'

export const FLOW_PANEL_ENTER_FRAMES = 30
export const FLOW_PANEL_STAGGER_FRAMES = 8
export const FLOW_PANEL_HOLD_FRAMES = 48
export const FLOW_PANEL_EXIT_FRAMES = 30

export function getFlowPanelEnterStart(index: number) {
  return index * FLOW_PANEL_STAGGER_FRAMES
}

export function getFlowPanelsEnterPhaseEnd(panelCount: number) {
  const lastIndex = panelCount - 1
  return getFlowPanelEnterStart(lastIndex) + FLOW_PANEL_ENTER_FRAMES
}

export function getFlowPanelsExitStart(panelCount: number) {
  return getFlowPanelsEnterPhaseEnd(panelCount) + FLOW_PANEL_HOLD_FRAMES
}

export function getFlowPanelExitStart(index: number, panelCount: number) {
  return getFlowPanelsExitStart(panelCount) + index * FLOW_PANEL_STAGGER_FRAMES
}

export function getFlowPanelsSceneDuration(panelCount: number) {
  const lastIndex = panelCount - 1
  return (
    getFlowPanelExitStart(lastIndex, panelCount) +
    FLOW_PANEL_EXIT_FRAMES +
    SCENE_EXIT_FRAMES
  )
}
