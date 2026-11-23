import { useMemo, useState } from 'react'
import {
  DndContext,
  DragOverlay,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  type DragEndEvent,
  type DragStartEvent,
  useSensor,
  useSensors,
} from '@dnd-kit/core'
import {
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import {
  Box,
  GitBranch,
  GripVertical,
  ImageIcon,
  MoreHorizontal,
  Shapes,
  Table2,
  Tag,
  Trash2,
  Type,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { getDiagramLayerListItems } from '@/lib/diagram-generator/node-layers'
import type { DiagramNode, DiagramNodeKind, DiagramSelection } from '@/lib/diagram-generator/types'
import { getSelectedNodeIds } from '@/lib/diagram-generator/selection'
import { getDiagramNodeLayerSubtitle } from '@/components/pages/generator/diagrams/_components/DiagramNodeKindProperties'
import { CoverIconPreview } from '@/components/pages/generator/_components/CoverIconPreview'
import {
  getAxisRestrictedDragModifiers,
  sortableAxisTransform,
} from '@/lib/dnd-modifiers'
import { getDiagramNodeIconSrc } from '@/lib/diagram-generator/node-normalize'
import { cn } from '@/lib/utils'

const layerDragModifiers = getAxisRestrictedDragModifiers('vertical')

const NODE_KIND_ICONS: Record<DiagramNodeKind, typeof Box> = {
  service: Box,
  title: Type,
  label: Tag,
  group: GitBranch,
  icon: Shapes,
  screenshot: ImageIcon,
  table: Table2,
}

type DiagramLayersPanelProps = {
  nodes: DiagramNode[]
  selection: DiagramSelection
  onSelectNode: (
    nodeId: string,
    modifiers?: { shiftKey?: boolean; metaKey?: boolean; ctrlKey?: boolean },
  ) => void
  onReorderNodes: (activeId: string, overId: string) => void
  onRemoveNode: (nodeId: string) => void
}

function LayerNodeIcon({ node }: { node: DiagramNode }) {
  if (node.kind === 'service' && node.iconSrc) {
    return (
      <CoverIconPreview
        src={getDiagramNodeIconSrc(node)}
        colorMode="app"
        size={14}
        className="shrink-0"
      />
    )
  }

  if (node.kind === 'icon') {
    return (
      <CoverIconPreview
        src={getDiagramNodeIconSrc(node)}
        colorMode="app"
        size={14}
        className="shrink-0"
      />
    )
  }

  const KindIcon = NODE_KIND_ICONS[node.kind]
  return <KindIcon className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
}

type SortableLayerRowProps = {
  node: DiagramNode
  stackIndex: number
  total: number
  selected: boolean
  onSelect: (event: React.MouseEvent<HTMLButtonElement>) => void
  onRemove: () => void
}

function SortableLayerRow({
  node,
  stackIndex,
  total,
  selected,
  onSelect,
  onRemove,
}: SortableLayerRowProps) {
  const {
    attributes,
    listeners,
    setNodeRef,
    setActivatorNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({
    id: node.id,
    animateLayoutChanges: () => false,
    transition: null,
  })

  const style = isDragging
    ? undefined
    : {
        transform: sortableAxisTransform(transform, 'vertical'),
        transition,
      }

  return (
    <div
      ref={setNodeRef}
      style={style}
      className={cn(
        'group flex items-center gap-0.5 rounded-lg border border-transparent px-0.5 py-0.5 transition-colors',
        selected && 'border-[var(--brand-cta)]/25 bg-[var(--brand-cta)]/5',
        !selected && 'hover:bg-accent/40',
        isDragging && 'opacity-0',
      )}
    >
      <button
        ref={setActivatorNodeRef}
        type="button"
        className="flex size-7 shrink-0 cursor-grab items-center justify-center rounded-md text-muted-foreground hover:bg-accent/60 hover:text-foreground active:cursor-grabbing"
        aria-label={`Drag to reorder ${node.label}`}
        {...attributes}
        {...listeners}
      >
        <GripVertical className="size-3.5" />
      </button>

      <button
        type="button"
        onClick={(event) => onSelect(event)}
        className="flex min-w-0 flex-1 items-center gap-2 rounded-md px-1.5 py-1.5 text-start"
      >
        <LayerNodeIcon node={node} />
        <div className="min-w-0 flex-1">
          <p className="truncate text-[12px] font-medium text-foreground">
            {node.label}
          </p>
          <p className="truncate text-[10px] text-muted-foreground">
            {getDiagramNodeLayerSubtitle(node)}
          </p>
        </div>
        <span className="shrink-0 text-[10px] tabular-nums text-muted-foreground">
          {stackIndex + 1}/{total}
        </span>
      </button>

      <DropdownMenu modal={false}>
        <DropdownMenuTrigger asChild>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-7 shrink-0 opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100 data-[state=open]:opacity-100"
            aria-label={`Layer options for ${node.label}`}
          >
            <MoreHorizontal className="size-3.5" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-36">
          <DropdownMenuItem onClick={onRemove}>
            <Trash2 className="me-2 size-3.5" />
            Delete
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}

function LayerRowPreview({
  node,
  stackIndex,
  total,
  selected,
}: {
  node: DiagramNode
  stackIndex: number
  total: number
  selected: boolean
}) {
  return (
    <div
      className={cn(
        'flex items-center gap-0.5 rounded-lg border border-border bg-background px-0.5 py-0.5 shadow-sm',
        selected && 'border-[var(--brand-cta)]/25 bg-[var(--brand-cta)]/5',
      )}
    >
      <div className="flex size-7 shrink-0 items-center justify-center text-muted-foreground">
        <GripVertical className="size-3.5" />
      </div>
      <div className="flex min-w-0 flex-1 items-center gap-2 px-1.5 py-1.5">
        <LayerNodeIcon node={node} />
        <div className="min-w-0 flex-1">
          <p className="truncate text-[12px] font-medium text-foreground">
            {node.label}
          </p>
          <p className="truncate text-[10px] text-muted-foreground">
            {getDiagramNodeLayerSubtitle(node)}
          </p>
        </div>
        <span className="shrink-0 text-[10px] tabular-nums text-muted-foreground">
          {stackIndex + 1}/{total}
        </span>
      </div>
    </div>
  )
}

export function DiagramLayersPanel({
  nodes,
  selection,
  onSelectNode,
  onReorderNodes,
  onRemoveNode,
}: DiagramLayersPanelProps) {
  const layerItems = getDiagramLayerListItems(nodes)
  const selectedNodeIds = getSelectedNodeIds(selection)
  const [activeDragId, setActiveDragId] = useState<string | null>(null)

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    }),
  )

  const sortableIds = useMemo(
    () => layerItems.map(({ node }) => node.id),
    [layerItems],
  )

  const activeDragItem = activeDragId
    ? layerItems.find(({ node }) => node.id === activeDragId)
    : undefined

  const handleDragStart = (event: DragStartEvent) => {
    setActiveDragId(String(event.active.id))
  }

  const handleDragEnd = (event: DragEndEvent) => {
    setActiveDragId(null)
    const { active, over } = event
    if (!over || active.id === over.id) return
    onReorderNodes(String(active.id), String(over.id))
  }

  const handleDragCancel = () => {
    setActiveDragId(null)
  }

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="flex shrink-0 items-center justify-between gap-2 border-b border-border px-4 py-2.5">
        <div className="min-w-0">
          <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
            Layers
          </p>
          <p className="mt-0.5 text-[11px] text-muted-foreground">
            Drag to reorder. Top layers draw above others.
          </p>
        </div>
        <span className="rounded-md bg-muted px-2 py-0.5 text-[11px] tabular-nums text-muted-foreground">
          {nodes.length}
        </span>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto px-2 py-2">
        {layerItems.length === 0 ? (
          <p className="px-2 py-6 text-center text-[12px] text-muted-foreground">
            No elements yet. Add nodes from the left panel.
          </p>
        ) : (
          <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            modifiers={layerDragModifiers}
            onDragStart={handleDragStart}
            onDragEnd={handleDragEnd}
            onDragCancel={handleDragCancel}
          >
            <SortableContext
              items={sortableIds}
              strategy={verticalListSortingStrategy}
            >
              <div className="space-y-0.5">
                {layerItems.map(({ node, index }) => (
                  <SortableLayerRow
                    key={node.id}
                    node={node}
                    stackIndex={index}
                    total={nodes.length}
                    selected={selectedNodeIds.includes(node.id)}
                    onSelect={(event) =>
                      onSelectNode(node.id, {
                        shiftKey: event.shiftKey,
                        metaKey: event.metaKey,
                        ctrlKey: event.ctrlKey,
                      })
                    }
                    onRemove={() => onRemoveNode(node.id)}
                  />
                ))}
              </div>
            </SortableContext>
            <DragOverlay dropAnimation={null}>
              {activeDragItem ? (
                <LayerRowPreview
                  node={activeDragItem.node}
                  stackIndex={activeDragItem.index}
                  total={nodes.length}
                  selected={selectedNodeIds.includes(activeDragItem.node.id)}
                />
              ) : null}
            </DragOverlay>
          </DndContext>
        )}
      </div>
    </div>
  )
}
