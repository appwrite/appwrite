import { ArrowRight, Trash2 } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { DiagramPropertiesSplitResizableLayout } from '@/components/global/api-explorer/ApiExplorerResizableLayout'
import { DiagramLayersPanel } from '@/components/pages/generator/diagrams/_components/DiagramLayersPanel'
import {
  DiagramNodeKindProperties,
  getDiagramNodeLabelFieldLabel,
  getDiagramNodeLabelMaxLength,
  getDiagramNodePropertiesSubtitle,
  shouldShowDiagramNodeLabelField,
} from '@/components/pages/generator/diagrams/_components/DiagramNodeKindProperties'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  DIAGRAM_EDGE_ARROW_LABELS,
  DIAGRAM_EDGE_LABEL_SUGGESTIONS,
  DIAGRAM_EDGE_LINE_STYLE_LABELS,
  DIAGRAM_EDGE_PRESETS,
  DIAGRAM_EDGE_STROKE_TONE_LABELS,
} from '@/lib/diagram-generator/edge-appearance'
import {
  getSelectedNodeIds,
} from '@/lib/diagram-generator/selection'
import type {
  DiagramDocument,
  DiagramEdge,
  DiagramNode,
  DiagramSelection,
} from '@/lib/diagram-generator/types'
import {
  useDiagramGeneratorPropertiesSplitLayout,
  type ConsoleAccountCache,
} from '@/lib/react-query/hooks/auth'
import { cn } from '@/lib/utils'

const PROPERTIES_LAYERS_SPLIT_HANDLE_CLASS = cn(
  'relative z-[45] h-[0.5px] w-full bg-border',
  'before:pointer-events-none before:absolute before:inset-x-0 before:top-1/2 before:h-2 before:w-full before:-translate-y-1/2 before:bg-border before:opacity-0 before:transition-opacity',
  'hover:before:opacity-100 data-[resize-handle-state=drag]:before:opacity-100',
  'after:h-2 after:top-1/2 after:w-full after:-translate-y-1/2',
)

type DiagramPropertiesPanelProps = {
  document: DiagramDocument
  selection: DiagramSelection
  connectDraft: { nodeId: string } | null
  onDocumentChange: (patch: Partial<DiagramDocument>) => void
  onNodeChange: (nodeId: string, patch: Partial<DiagramNode>) => void
  onEdgeChange: (edgeId: string, patch: Partial<DiagramEdge>) => void
  onSelectEdge: (edgeId: string) => void
  onSelectNode: (
    nodeId: string,
    modifiers?: { shiftKey?: boolean; metaKey?: boolean; ctrlKey?: boolean },
  ) => void
  onReorderNodes: (activeId: string, overId: string) => void
  onRemoveNode: (nodeId: string) => void
  onRemoveNodes: (nodeIds: string[]) => void
  onRemoveEdge: (edgeId: string) => void
  onReset: () => void
}

function getNodeLabel(document: DiagramDocument, nodeId: string): string {
  return document.nodes.find((node) => node.id === nodeId)?.label ?? 'Unknown'
}

export function DiagramPropertiesPanel({
  document,
  selection,
  connectDraft,
  onDocumentChange,
  onNodeChange,
  onEdgeChange,
  onSelectEdge,
  onSelectNode,
  onReorderNodes,
  onRemoveNode,
  onRemoveNodes,
  onRemoveEdge,
  onReset,
}: DiagramPropertiesPanelProps) {
  const { account } = useAuth()
  const consoleAccount = account as ConsoleAccountCache | undefined
  const { layout: propertiesSplitLayout, persistLayout: persistPropertiesSplitLayout } =
    useDiagramGeneratorPropertiesSplitLayout(consoleAccount)

  const selectedNodeIds = getSelectedNodeIds(selection)
  const selectedNodeCount = selectedNodeIds.length
  const selectedNode =
    selection.type === 'node'
      ? document.nodes.find((node) => node.id === selection.id)
      : undefined
  const selectedEdge =
    selection.type === 'edge'
      ? document.edges.find((edge) => edge.id === selection.id)
      : undefined

  const nodeConnections = selectedNode
    ? document.edges.filter(
        (edge) =>
          edge.fromNodeId === selectedNode.id || edge.toNodeId === selectedNode.id,
      )
    : []

  const propertiesContent = (
    <div className="h-full overflow-y-auto px-4 py-4">
      {connectDraft ? (
        <p className="text-[12px] text-muted-foreground">
          Click a dot on another node to finish the connection. Press Escape to
          cancel.
        </p>
      ) : null}

      {selectedNodeCount > 1 ? (
        <div className="space-y-4">
          <div className="rounded-lg border border-border bg-muted/20 px-3 py-2.5">
            <p className="text-[13px] font-medium text-foreground">
              {selectedNodeCount} elements selected
            </p>
            <p className="mt-1 text-[12px] text-muted-foreground">
              Drag any selected element to move the group together. Hold Shift and
              drag on the canvas to box select.
            </p>
          </div>

          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 text-[12px]"
            onClick={() => onRemoveNodes(selectedNodeIds)}
          >
            <Trash2 className="me-1.5 size-3.5" />
            Delete {selectedNodeCount} elements
          </Button>
        </div>
      ) : selectedNode ? (
        <div className="space-y-4">
          {shouldShowDiagramNodeLabelField(selectedNode) ? (
            <div className="space-y-2">
              <Label htmlFor="diagram-node-label" className="text-[12px]">
                {getDiagramNodeLabelFieldLabel(selectedNode)}
              </Label>
              <Input
                id="diagram-node-label"
                value={selectedNode.label}
                maxLength={getDiagramNodeLabelMaxLength(selectedNode)}
                className="h-9 text-[13px]"
                onChange={(event) =>
                  onNodeChange(selectedNode.id, { label: event.target.value })
                }
              />
            </div>
          ) : null}

          <DiagramNodeKindProperties
            node={selectedNode}
            themeId={document.theme}
            onNodeChange={onNodeChange}
          />

          <div className="space-y-2">
            <p className="text-[12px] font-medium text-foreground">Connections</p>
            {nodeConnections.length === 0 ? (
              <p className="text-[12px] text-muted-foreground">
                No connections yet. Click a dot on this node, then a dot on another
                node.
              </p>
            ) : (
              <div className="space-y-1.5">
                {nodeConnections.map((edge) => {
                  const isOutgoing = edge.fromNodeId === selectedNode.id
                  const otherLabel = getNodeLabel(
                    document,
                    isOutgoing ? edge.toNodeId : edge.fromNodeId,
                  )

                  return (
                    <button
                      key={edge.id}
                      type="button"
                      onClick={() => onSelectEdge(edge.id)}
                      className={cn(
                        'flex w-full items-center gap-2 rounded-lg border border-border px-3 py-2 text-start text-[12px] transition-colors hover:bg-accent/40',
                        selection.type === 'edge' &&
                          selection.id === edge.id &&
                          'border-[var(--brand-cta)]/40 bg-[var(--brand-cta)]/5',
                      )}
                    >
                      <span className="min-w-0 flex-1 truncate text-foreground">
                        {isOutgoing ? selectedNode.label : otherLabel}
                      </span>
                      <ArrowRight className="size-3.5 shrink-0 text-muted-foreground" />
                      <span className="min-w-0 flex-1 truncate text-foreground">
                        {isOutgoing ? otherLabel : selectedNode.label}
                      </span>
                    </button>
                  )
                })}
              </div>
            )}
          </div>

          <p className="text-[12px] text-muted-foreground">
            Drag the corner handle to resize. Use the side dots to connect nodes.
          </p>

          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 text-[12px]"
            onClick={() => onRemoveNode(selectedNode.id)}
          >
            <Trash2 className="me-1.5 size-3.5" />
            Delete node
          </Button>
        </div>
      ) : selectedEdge ? (
        <div className="space-y-4">
          <div className="rounded-lg border border-border bg-muted/20 px-3 py-2.5 text-[12px]">
            <p className="font-medium text-foreground">
              {getNodeLabel(document, selectedEdge.fromNodeId)}
              <ArrowRight className="mx-1.5 inline size-3.5 text-muted-foreground" />
              {getNodeLabel(document, selectedEdge.toNodeId)}
            </p>
          </div>

          <div className="space-y-3">
            <div className="space-y-2">
              <Label className="text-[12px]">Presets</Label>
              <div className="flex flex-wrap gap-1.5">
                {DIAGRAM_EDGE_PRESETS.map((preset) => {
                  const isActive =
                    selectedEdge.lineStyle === preset.lineStyle &&
                    selectedEdge.arrow === preset.arrow &&
                    selectedEdge.strokeTone === preset.strokeTone

                  return (
                    <Button
                      key={preset.id}
                      type="button"
                      variant={isActive ? 'default' : 'outline'}
                      size="sm"
                      className="h-7 px-2.5 text-[11px]"
                      title={preset.description}
                      onClick={() =>
                        onEdgeChange(selectedEdge.id, {
                          lineStyle: preset.lineStyle,
                          arrow: preset.arrow,
                          strokeTone: preset.strokeTone,
                          ...(selectedEdge.label
                            ? {}
                            : preset.labelSuggestion
                              ? { label: preset.labelSuggestion }
                              : {}),
                        })
                      }
                    >
                      {preset.label}
                    </Button>
                  )
                })}
              </div>
            </div>

            <div className="space-y-3">
              <div className="space-y-2">
                <Label className="text-[12px]">Line</Label>
                <Select
                  value={selectedEdge.lineStyle}
                  onValueChange={(value) =>
                    onEdgeChange(selectedEdge.id, {
                      lineStyle: value as DiagramEdge['lineStyle'],
                    })
                  }
                >
                  <SelectTrigger className="h-9 w-full text-[13px]">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {Object.entries(DIAGRAM_EDGE_LINE_STYLE_LABELS).map(
                      ([style, label]) => (
                        <SelectItem key={style} value={style}>
                          {label}
                        </SelectItem>
                      ),
                    )}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label className="text-[12px]">Arrow</Label>
                <Select
                  value={selectedEdge.arrow}
                  onValueChange={(value) =>
                    onEdgeChange(selectedEdge.id, {
                      arrow: value as DiagramEdge['arrow'],
                    })
                  }
                >
                  <SelectTrigger className="h-9 w-full text-[13px]">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {Object.entries(DIAGRAM_EDGE_ARROW_LABELS).map(
                      ([arrow, label]) => (
                        <SelectItem key={arrow} value={arrow}>
                          {label}
                        </SelectItem>
                      ),
                    )}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label className="text-[12px]">Color</Label>
                <Select
                  value={selectedEdge.strokeTone}
                  onValueChange={(value) =>
                    onEdgeChange(selectedEdge.id, {
                      strokeTone: value as DiagramEdge['strokeTone'],
                    })
                  }
                >
                  <SelectTrigger className="h-9 w-full text-[13px]">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {Object.entries(DIAGRAM_EDGE_STROKE_TONE_LABELS).map(
                      ([tone, label]) => (
                        <SelectItem key={tone} value={tone}>
                          {label}
                        </SelectItem>
                      ),
                    )}
                  </SelectContent>
                </Select>
              </div>
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="diagram-edge-label" className="text-[12px]">
              Label
            </Label>
            <Input
              id="diagram-edge-label"
              value={selectedEdge.label ?? ''}
              maxLength={32}
              placeholder="HTTPS, Webhook, SQL…"
              className="h-9 text-[13px]"
              onChange={(event) =>
                onEdgeChange(selectedEdge.id, {
                  label: event.target.value || undefined,
                })
              }
            />
            <div className="flex flex-wrap gap-1.5">
              {DIAGRAM_EDGE_LABEL_SUGGESTIONS.map((suggestion) => (
                <Button
                  key={suggestion}
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-6 px-2 text-[10px]"
                  onClick={() =>
                    onEdgeChange(selectedEdge.id, { label: suggestion })
                  }
                >
                  {suggestion}
                </Button>
              ))}
            </div>
          </div>

          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 text-[12px]"
            onClick={() => onRemoveEdge(selectedEdge.id)}
          >
            <Trash2 className="me-1.5 size-3.5" />
            Delete connection
          </Button>
        </div>
      ) : (
        <div className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="diagram-title" className="text-[12px]">
              Title
            </Label>
            <Input
              id="diagram-title"
              value={document.title}
              maxLength={80}
              className="h-9 text-[13px]"
              onChange={(event) => onDocumentChange({ title: event.target.value })}
            />
          </div>

          <p className="text-[12px] text-muted-foreground">
            Select a node to resize it or create connections using the dots on
            each side. Click a connection line to edit its style and label.
          </p>
        </div>
      )}
    </div>
  )

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="flex shrink-0 items-start justify-between gap-3 border-b border-border px-4 py-3">
        <div className="min-w-0">
          <p className="text-[13px] font-medium text-foreground">Properties</p>
          <p className="mt-0.5 text-[12px] text-muted-foreground">
            {connectDraft
              ? 'Connecting…'
              : selectedNodeCount > 1
                ? `${selectedNodeCount} elements`
                : selectedNode
                  ? getDiagramNodePropertiesSubtitle(selectedNode)
                  : selectedEdge
                    ? 'Connection'
                    : 'Diagram settings'}
          </p>
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8 shrink-0 text-[12px]"
          onClick={onReset}
        >
          Reset
        </Button>
      </div>

      <DiagramPropertiesSplitResizableLayout
        layout={propertiesSplitLayout}
        persistLayout={persistPropertiesSplitLayout}
        handleClassName={PROPERTIES_LAYERS_SPLIT_HANDLE_CLASS}
        className="min-h-0 flex-1"
        properties={propertiesContent}
        layers={
          <div className="flex h-full min-h-0 flex-col bg-muted/10">
            <DiagramLayersPanel
              nodes={document.nodes}
              selection={selection}
              onSelectNode={onSelectNode}
              onReorderNodes={onReorderNodes}
              onRemoveNode={onRemoveNode}
            />
          </div>
        }
      />
    </div>
  )
}
