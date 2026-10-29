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
import { DIAGRAM_TEMPLATE_CATALOG } from '@/lib/diagram-generator/diagram-template-catalog'
import type { DiagramTemplateId } from '@/lib/diagram-generator/types'
import type { CoverTheme } from '@/lib/cover-generator/constants'

const DIAGRAM_TEMPLATES = DIAGRAM_TEMPLATE_CATALOG

const ELEMENT_ITEMS = [
  { kind: 'service' as const, label: DIAGRAM_NODE_KIND_LABELS.service, icon: Box },
  { kind: 'title' as const, label: DIAGRAM_NODE_KIND_LABELS.title, icon: Type },
  { kind: 'label' as const, label: DIAGRAM_NODE_KIND_LABELS.label, icon: Tag },
  { kind: 'group' as const, label: DIAGRAM_NODE_KIND_LABELS.group, icon: GitBranch },
  { kind: 'icon' as const, label: DIAGRAM_NODE_KIND_LABELS.icon, icon: Shapes },
  { kind: 'screenshot' as const, label: DIAGRAM_NODE_KIND_LABELS.screenshot, icon: ImageIcon },
  { kind: 'table' as const, label: DIAGRAM_NODE_KIND_LABELS.table, icon: Table2 },
]

type DiagramPalettePanelProps = {
  theme: CoverTheme
  onThemeChange: (theme: CoverTheme) => void
  onApplyTemplate: (templateId: DiagramTemplateId) => void
  onAddNode: (kind: (typeof ELEMENT_ITEMS)[number]['kind']) => void
  variant?: 'panel' | 'compact'
}

export function DiagramPalettePanel({
  theme,
  onThemeChange,
  onApplyTemplate,
  onAddNode,
  variant = 'panel',
}: DiagramPalettePanelProps) {
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
        <p className="text-[12px] font-medium text-foreground">Diagram</p>
        <CoverThemeSelect theme={theme} onThemeChange={onThemeChange} />
      </div>

      <div className="shrink-0 border-b border-border px-4 py-3">
        <p className="text-[12px] text-muted-foreground">
          Select a node to resize it or use the dots on each side to connect
          nodes. Shift+click or Cmd/Ctrl+click to select multiple elements.
          Shift+drag on the canvas to box select. Drag any selected element to
          move the group. Use Cmd/Ctrl+Z to undo and Cmd/Ctrl+Shift+Z to redo.
          Click a line to edit the connection. Hold Shift while dragging to lock
          to one axis. Elements snap when edges or centers align.
        </p>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto px-4 py-3">
        <section className="space-y-2">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            Templates
          </p>
          <div className="space-y-2">
            {DIAGRAM_TEMPLATES.map((template) => (
              <button
                key={template.id}
                type="button"
                onClick={() => onApplyTemplate(template.id)}
                className="w-full rounded-lg border border-border bg-card/40 px-3 py-2.5 text-start transition-colors hover:bg-accent/40"
              >
                <span className="block text-[13px] font-medium text-foreground">
                  {template.label}
                </span>
                <span className="mt-0.5 block text-[11px] text-muted-foreground">
                  {template.description}
                </span>
              </button>
            ))}
          </div>
        </section>

        <section className="mt-5 space-y-2">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            Elements
          </p>
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
        </section>
      </div>
    </div>
  )
}
