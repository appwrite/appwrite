import { VIDEO } from './video-config'
import { getHeroBrowserFrameHeight } from './hero-browser-frame'

export const FLOW_PANEL_ROW_PADDING = 72
export const FLOW_PANEL_GAP = 28
export const FLOW_PANEL_COUNT = 3

const availableRowWidth =
  VIDEO.width -
  FLOW_PANEL_ROW_PADDING * 2 -
  FLOW_PANEL_GAP * (FLOW_PANEL_COUNT - 1)

export const FLOW_PANEL_WIDTH = Math.floor(availableRowWidth / FLOW_PANEL_COUNT)
export const FLOW_PANEL_CONTENT_ASPECT = 1

export type FlowPanelId = 'docs' | 'api' | 'dashboard'
export type FlowPanelEnterFrom = 'up' | 'down' | 'left' | 'right'

export type FlowPanelLayout = {
  id: FlowPanelId
  label: string
  enterFrom: FlowPanelEnterFrom
  /** Static path under public/ - omit for placeholder chrome. */
  imageSrc?: string
}

export const FLOW_PANEL_LAYOUTS: FlowPanelLayout[] = [
  {
    id: 'docs',
    label: 'Docs',
    enterFrom: 'up',
    imageSrc: 'images/flow-panels/docs.png',
  },
  {
    id: 'dashboard',
    label: 'Dashboard',
    enterFrom: 'down',
    imageSrc: 'images/flow-panels/dashboard.png',
  },
  {
    id: 'api',
    label: 'API',
    enterFrom: 'up',
    imageSrc: 'images/flow-panels/api.png',
  },
]

export function getFlowPanelShellHeight() {
  return getHeroBrowserFrameHeight(
    FLOW_PANEL_WIDTH,
    true,
    true,
    FLOW_PANEL_CONTENT_ASPECT,
  )
}

/** Enough travel to clear the viewport from the centered row. */
export function getFlowPanelTravelDistanceY() {
  const shellHeight = getFlowPanelShellHeight()
  return VIDEO.height / 2 + shellHeight / 2 + 64
}

export function getFlowPanelTravelDistanceX() {
  return VIDEO.width / 2 + FLOW_PANEL_WIDTH / 2 + 64
}
