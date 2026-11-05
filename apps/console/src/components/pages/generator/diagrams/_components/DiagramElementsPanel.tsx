import {
  Box,
  GitBranch,
  ImageIcon,
  Plus,
  Shapes,
  Table2,
  Tag,
  Type,
} from 'lucide-react'
import { CoverThemeSelect } from '@/components/pages/generator/_components/CoverThemeSelect'
import { Button } from '@/components/ui/button'
import { DIAGRAM_NODE_KIND_LABELS } from '@/lib/diagram-generator/constants'
import type { DiagramNodeKind } from '@/lib/diagram-generator/types'
import type { CoverEditorThemeId } from '@/lib/cover-generator/themes'

const ELEMENT_ITEMS = [
  { kind: 'service' as const, label: DIAGRAM_NODE_KIND_LABELS.service, icon: Box },
  { kind: 'title' as const, label: DIAGRAM_NODE_KIND_LABELS.title, icon: Type },
  { kind: 'label' as const, label: DIAGRAM_NODE_KIND_LABELS.label, icon: Tag },
  { kind: 'group' as const, label: DIAGRAM_NODE_KIND_LABELS.group, icon: GitBranch },
  { kind: 'icon' as const, label: DIAGRAM_NODE_KIND_LABELS.icon, icon: Shapes },
  { kind: 'screenshot' as const, label: DIAGRAM_NODE_KIND_LABELS.screenshot, icon: ImageIcon },
  { kind: 'table' as const, label: DIAGRAM_NODE_KIND_LABELS.table, icon: Table2 },
]

type DiagramElementsPanelProps = {
  theme: CoverEditorThemeId
  onThemeChange: (theme: CoverEditorThemeId) => void
  onAddNode: (kind: DiagramNodeKind) => void
  variant?: 'panel' | 'compact'
}

export function DiagramElementsPanel({
  theme,
  onThemeChange,
  onAddNode,
  variant = 'panel',
}: DiagramElementsPanelProps) {
  if (variant === 'compact') {
    return (
      <div className="space-y-3">
        <CoverThemeSelect theme={theme} onThemeChange={onThemeChange} />
        <div className="flex flex-wrap gap-2">
          {ELEMENT_ITEMS.map((item) => (
            <Button
              key={item.kind}
              type="button"
              variant="outline"
              size="sm"
              className="h-8 text-[12px]"
              onClick={() => onAddNode(item.kind)}
            >
              <Plus className="me-1.5 size-3.5" />
              {item.label}
            </Button>
          ))}
        </div>
      </div>
    )
  }

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="shrink-0 space-y-2 border-b border-border px-4 py-3">
        <p className="text-[12px] font-medium text-foreground">Elements</p>
        <CoverThemeSelect theme={theme} onThemeChange={onThemeChange} />
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto px-4 py-3">
        <div className="grid grid-cols-1 gap-2">
          {ELEMENT_ITEMS.map((item) => {
            const Icon = item.icon
            return (
              <Button
                key={item.kind}
                type="button"
                variant="outline"
                size="sm"
                className="h-9 justify-start text-[12px]"
                onClick={() => onAddNode(item.kind)}
              >
                <Icon className="me-1.5 size-3.5" />
                Add {item.label.toLowerCase()}
              </Button>
            )
          })}
        </div>
      </div>
    </div>
  )
}
