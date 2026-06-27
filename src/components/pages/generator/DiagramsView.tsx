import { useCallback, useEffect } from 'react'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { GeneratorColumnsResizableLayout } from '@/components/pages/generator/_components/GeneratorColumnsResizableLayout'
import { DiagramCanvas } from '@/components/pages/generator/diagrams/_components/DiagramCanvas'
import { DiagramPalettePanel } from '@/components/pages/generator/diagrams/_components/DiagramPalettePanel'
import { DiagramPropertiesPanel } from '@/components/pages/generator/diagrams/_components/DiagramPropertiesPanel'
import { useGeneratorLayout } from '@/components/pages/generator/GeneratorLayoutContext'
import { useIsXlUp } from '@/hooks/use-mobile'
import {
  captureDiagramBlob,
  downloadDiagramBlob,
  openDiagramImage,
} from '@/lib/diagram-generator/export-diagram'
import { useDiagramGeneratorState } from '@/lib/diagram-generator/use-diagram-generator-state'
import type { CoverImageFormat } from '@/lib/cover-generator/constants'
import type { CoverDownloadScale } from '@/lib/cover-generator/download-scale'
import {
  useCoverGeneratorColumnsLayout,
  type ConsoleAccountCache,
} from '@/lib/react-query/hooks/auth'
import { cn } from '@/lib/utils'

const RESIZE_HANDLE_CLASS = cn(
  'relative z-[45] w-[0.5px] bg-border',
  'before:pointer-events-none before:absolute before:inset-y-0 before:left-1/2 before:w-2 before:-translate-x-1/2 before:bg-border before:opacity-0 before:transition-opacity',
  'hover:before:opacity-100 data-[resize-handle-state=drag]:before:opacity-100',
  'after:w-2 after:left-1/2 after:-translate-x-1/2',
)

export function DiagramsView() {
  const {
    setDiagramDocument,
    leftPanelOpen,
    rightPanelOpen,
    setLeftPanelOpen,
    setRightPanelOpen,
  } = useGeneratorLayout()
  const { account } = useAuth()
  const consoleAccount = account as ConsoleAccountCache | undefined
  const { layout, persistLayout } = useCoverGeneratorColumnsLayout(consoleAccount)
  const {
    document,
    selection,
    connectDraft,
    setDocument,
    setSelection,
    cancelConnectDraft,
    applyTemplate,
    addNode,
    updateNode,
    moveNode,
    moveNodes,
    resizeNode,
    reorderNodes,
    removeNode,
    removeNodes,
    updateEdge,
    removeEdge,
    handleNodeClick,
    handlePortClick,
    completePortConnect,
    canUndo,
    canRedo,
    undo,
    redo,
    beginDocumentGesture,
    commitDocumentGesture,
    resetDocument,
    setTheme,
    setFormat,
    setCanvasSize,
  } = useDiagramGeneratorState()
  const isXlUp = useIsXlUp()

  useEffect(() => {
    const isEditableTarget = (target: HTMLElement | null) =>
      Boolean(
        target &&
          (target.tagName === 'INPUT' ||
            target.tagName === 'TEXTAREA' ||
            target.tagName === 'SELECT' ||
            target.isContentEditable),
      )

    const handleKeyDown = (event: KeyboardEvent) => {
      const target = event.target as HTMLElement | null
      const isMeta = event.metaKey || event.ctrlKey

      if (isMeta && (event.key === 'z' || event.key === 'Z')) {
        if (isEditableTarget(target)) return
        event.preventDefault()
        if (event.shiftKey) {
          if (canRedo) redo()
        } else if (canUndo) {
          undo()
        }
        return
      }

      if (event.ctrlKey && event.key === 'y') {
        if (isEditableTarget(target)) return
        event.preventDefault()
        if (canRedo) redo()
        return
      }

      if (event.key !== 'Delete' && event.key !== 'Backspace') return
      if (isEditableTarget(target)) return

      if (selection.type === 'node') {
        event.preventDefault()
        removeNode(selection.id)
      } else if (selection.type === 'nodes') {
        event.preventDefault()
        removeNodes(selection.ids)
      } else if (selection.type === 'edge') {
        event.preventDefault()
        removeEdge(selection.id)
      }
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [canRedo, canUndo, redo, removeEdge, removeNode, removeNodes, selection, undo])

  useEffect(() => {
    setDiagramDocument(document)
    return () => setDiagramDocument(null)
  }, [document, setDiagramDocument])

  const handleDownload = useCallback(
    async (format: CoverImageFormat, scale: CoverDownloadScale) => {
      setFormat(format)
      try {
        const blob = await captureDiagramBlob(
          { ...document, format },
          { format, pixelRatio: scale },
        )
        downloadDiagramBlob(blob, { ...document, format }, scale)
      } catch {
        toast.error('Could not download diagram')
      }
    },
    [document, setFormat],
  )

  const handleOpenImage = useCallback(async () => {
    try {
      await openDiagramImage(document)
    } catch {
      toast.error('Could not open diagram image')
    }
  }, [document])

  const propertiesPanel = (
    <DiagramPropertiesPanel
      document={document}
      selection={selection}
      connectDraft={connectDraft}
      onDocumentChange={setDocument}
      onNodeChange={updateNode}
      onEdgeChange={updateEdge}
      onSelectEdge={(edgeId) => setSelection({ type: 'edge', id: edgeId })}
      onSelectNode={handleNodeClick}
      onReorderNodes={reorderNodes}
      onRemoveNode={removeNode}
      onRemoveNodes={removeNodes}
      onRemoveEdge={removeEdge}
      onReset={resetDocument}
    />
  )

  const canvas = (
    <DiagramCanvas
      document={document}
      selection={selection}
      connectDraft={connectDraft}
      onSelectionChange={setSelection}
      onCancelConnectDraft={cancelConnectDraft}
      onMoveNode={moveNode}
      onMoveNodes={moveNodes}
      onResizeNode={resizeNode}
      onBeginDocumentGesture={beginDocumentGesture}
      onCommitDocumentGesture={commitDocumentGesture}
      canUndo={canUndo}
      canRedo={canRedo}
      onUndo={undo}
      onRedo={redo}
      onPortClick={handlePortClick}
      onPortConnectComplete={completePortConnect}
      onCanvasSizeChange={setCanvasSize}
      onDownload={handleDownload}
      onOpenImage={handleOpenImage}
    />
  )

  return (
    <div className="flex h-full min-h-0 flex-col overflow-hidden">
      <div className="min-h-0 flex-1 overflow-hidden">
        {!isXlUp ? (
        <div className="flex h-full min-h-0 flex-col overflow-hidden">
          {leftPanelOpen ? (
            <div className="shrink-0 border-b border-border px-4 py-3">
              <DiagramPalettePanel
                theme={document.theme}
                onThemeChange={setTheme}
                onApplyTemplate={applyTemplate}
                onAddNode={addNode}
                variant="compact"
              />
            </div>
          ) : null}
          {canvas}
          {rightPanelOpen ? (
            <div className="max-h-[42dvh] min-h-0 shrink-0 overflow-hidden border-t border-border">
              {propertiesPanel}
            </div>
          ) : null}
        </div>
        ) : (
        <GeneratorColumnsResizableLayout
          layout={layout}
          persistLayout={persistLayout}
          leftOpen={leftPanelOpen}
          rightOpen={rightPanelOpen}
          onLeftOpenChange={setLeftPanelOpen}
          onRightOpenChange={setRightPanelOpen}
          handleClassName={RESIZE_HANDLE_CLASS}
          className="h-full min-h-0"
          templates={
            <div className="flex h-full min-h-0 flex-col overflow-hidden border-r border-border bg-background">
              <DiagramPalettePanel
                theme={document.theme}
                onThemeChange={setTheme}
                onApplyTemplate={applyTemplate}
                onAddNode={addNode}
              />
            </div>
          }
          canvas={
            <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden">
              {canvas}
            </div>
          }
          properties={
            <div className="flex h-full min-h-0 flex-col overflow-hidden border-l border-border bg-background">
              {propertiesPanel}
            </div>
          }
        />
        )}
      </div>
    </div>
  )
}
