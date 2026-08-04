import { AbsoluteFill } from 'remotion'
import { FlowPanelCard } from '../components/FlowPanelCard'
import {
  FLOW_PANEL_GAP,
  FLOW_PANEL_LAYOUTS,
  FLOW_PANEL_ROW_PADDING,
  FLOW_PANEL_WIDTH,
} from '../lib/flow-panels-layout'

/** Docs, API, and dashboard placeholders - side cards from top, center from bottom. */
export function FlowPanelsScene() {
  const panelCount = FLOW_PANEL_LAYOUTS.length

  return (
    <AbsoluteFill style={{ overflow: 'hidden' }}>
      <div
        className="flex h-full w-full items-center justify-center"
        style={{
          gap: FLOW_PANEL_GAP,
          paddingLeft: FLOW_PANEL_ROW_PADDING,
          paddingRight: FLOW_PANEL_ROW_PADDING,
        }}
      >
        {FLOW_PANEL_LAYOUTS.map((panel, index) => (
          <FlowPanelCard
            key={panel.id}
            index={index}
            panelCount={panelCount}
            width={FLOW_PANEL_WIDTH}
            enterFrom={panel.enterFrom}
            imageSrc={panel.imageSrc}
          />
        ))}
      </div>
    </AbsoluteFill>
  )
}
