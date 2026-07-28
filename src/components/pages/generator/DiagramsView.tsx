import { useCallback, useEffect, useRef } from 'react'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { GeneratorColumnsResizableLayout } from '@/components/pages/generator/_components/GeneratorColumnsResizableLayout'
import { DiagramCanvas } from '@/components/pages/generator/diagrams/_components/DiagramCanvas'
import { DiagramElementsPanel } from '@/components/pages/generator/diagrams/_components/DiagramElementsPanel'
import { DiagramPropertiesPanel } from '@/components/pages/generator/diagrams/_components/DiagramPropertiesPanel'
import { DiagramStartView } from '@/components/pages/generator/diagrams/_components/DiagramStartView'
import { useGeneratorLayout } from '@/components/pages/generator/GeneratorLayoutContext'
import { useIsXlUp } from '@/hooks/use-mobile'
import {
  captureDiagramBlob,
  downloadDiagramBlob,
  openDiagramImage,
} from '@/lib/diagram-generator/export-diagram'
import { normalizeDiagramDocument } from '@/lib/diagram-generator/storage'
import type { SavedDiagramGeneration } from '@/lib/diagram-generator/generation-prefs'
import {
  createDiagramFromTemplate,
  createDefaultDiagramDocument,
} from '@/lib/diagram-generator/templates'
import { useDiagramGeneratorState } from '@/lib/diagram-generator/use-diagram-generator-state'
import type { DiagramTemplateId } from '@/lib/diagram-generator/types'
import type { CoverImageFormat } from '@/lib/cover-generator/constants'
import type { CoverDownloadScale } from '@/lib/cover-generator/download-scale'
import {
  useCoverGeneratorColumnsLayout,
  type ConsoleAccountCache,
} from '@/lib/react-query/hooks/auth'
import { useDiagramGenerations } from '@/lib/react-query/hooks/diagram-generations'
import {
  deleteDiagramGenerationDraft,
  getDiagramGenerationDraft,
  setDiagramGenerationDraft,
} from '@/lib/generator/generation-drafts'
import { useRouteGenerationEditor } from '@/lib/generator/use-route-generation-editor'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'

const RESIZE_HANDLE_CLASS = cn(
  'relative z-[45] w-[0.5px] bg-border',
  'before:pointer-events-none before:absolute before:inset-y-0 before:left-1/2 before:w-2 before:-translate-x-1/2 before:bg-border before:opacity-0 before:transition-opacity',
  'hover:before:opacity-100 data-[resize-handle-state=drag]:before:opacity-100',
  'after:w-2 after:left-1/2 after:-translate-x-1/2',
)

type DiagramsViewProps = {
  generationId?: string
}

export function DiagramsView({ generationId: routeGenerationId }: DiagramsViewProps = {}) {
  const {
    setDiagramDocument,
    setDocumentChrome,
    setEditorTitle,
    leftPanelOpen,
    rightPanelOpen,
    setLeftPanelOpen,
    setRightPanelOpen,
  } = useGeneratorLayout()
  const { account } = useAuth()
  const consoleAccount = account as ConsoleAccountCache | undefined
  const { layout, persistLayout } = useCoverGeneratorColumnsLayout(consoleAccount)
  const {
    generations,
    isAuthenticated,
    isDeleting,
    isRenaming,
    saveGeneration,
    deleteGeneration,
    renameGeneration,
    maxNameLength,
    migrateLegacyIfNeeded,
  } = useDiagramGenerations(consoleAccount)

  const loadDocumentRef = useRef<
    (document: ReturnType<typeof createDefaultDiagramDocument>) => void
  >(() => {})

  const loadSavedDiagramGeneration = useCallback((generation: SavedDiagramGeneration) => {
    loadDocumentRef.current(generation.document)
  }, [])

  const leaveEditorRef = useRef<() => void>(() => {})

  const getDiagramGenerationId = useCallback(
    (generation: SavedDiagramGeneration) => generation.id,
    [],
  )

  const resolveDraft = useCallback(
    (id: string) => getDiagramGenerationDraft(id),
    [],
  )

  const discardDraftIfUnsaved = useCallback(
    (generationId: string | null | undefined) => {
      if (!generationId) return
      deleteDiagramGenerationDraft(generationId)
    },
    [],
  )

  const {
    phase,
    activeGenerationId,
    activeGenerationIdRef,
    isRouteSyncing,
    openEditorRoute,
    backToStart,
  } = useRouteGenerationEditor({
    routeGenerationId,
    startTo: '/generator/diagrams',
    editorTo: '/generator/diagrams/$generationId',
    generations,
    getGenerationId: getDiagramGenerationId,
    loadGeneration: loadSavedDiagramGeneration,
    resolveDraft,
    migrateLegacyIfNeeded,
    onEnterEditor: () => setLeftPanelOpen(false),
    onLeaveEditor: (generationId) => {
      leaveEditorRef.current()
      discardDraftIfUnsaved(generationId)
    },
    notFoundMessage: 'Diagram not found',
  })

  const persistActiveGeneration = useCallback(
    (document: ReturnType<typeof createDefaultDiagramDocument>) => {
      const generationId = activeGenerationIdRef.current
      if (!generationId) return

      const draft = getDiagramGenerationDraft(generationId)
      void saveGeneration({
        id: generationId,
        name: document.title.trim() || draft?.name || 'Untitled diagram',
        updatedAt: Date.now(),
        ...(draft?.templateId ? { templateId: draft.templateId } : {}),
        document: normalizeDiagramDocument(document),
      })
        .then(() => {
          deleteDiagramGenerationDraft(generationId)
        })
        .catch((error) => {
          toast.error(getErrorMessage(error, 'Could not save diagram'))
        })
    },
    [saveGeneration],
  )

  const {
    document,
    selection,
    connectDraft,
    setDocument,
    setSelection,
    cancelConnectDraft,
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
    copySelection,
    pasteClipboard,
    cutSelection,
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
    loadDocument,
    flushPersist,
    isDirty,
  } = useDiagramGeneratorState({ onDocumentPersist: persistActiveGeneration })

  loadDocumentRef.current = loadDocument
  leaveEditorRef.current = () => {
    setSelection({ type: 'none' })
    cancelConnectDraft()
  }

  const isXlUp = useIsXlUp()
  const migratedLegacyRef = useRef(false)

  useEffect(() => {
    if (migratedLegacyRef.current) return
    migratedLegacyRef.current = true
    void migrateLegacyIfNeeded().catch(() => {
      /* ignore migration errors */
    })
  }, [migrateLegacyIfNeeded])

  useEffect(() => {
    if (phase === 'editor') {
      setDiagramDocument(document)
      return () => setDiagramDocument(null)
    }

    setDiagramDocument(null)
  }, [document, phase, setDiagramDocument])

  const openEditor = useCallback(
    (
      generationId: string,
      nextDocument: ReturnType<typeof createDefaultDiagramDocument>,
      templateId?: DiagramTemplateId,
    ) => {
      setDiagramGenerationDraft({
        id: generationId,
        name: nextDocument.title.trim() || 'Untitled diagram',
        updatedAt: Date.now(),
        ...(templateId ? { templateId } : {}),
        document: normalizeDiagramDocument(nextDocument),
      })
      openEditorRoute(generationId)
    },
    [openEditorRoute],
  )

  const handleSelectTemplate = useCallback(
    (templateId: DiagramTemplateId) => {
      const nextDocument = normalizeDiagramDocument(createDiagramFromTemplate(templateId))
      openEditor(crypto.randomUUID(), nextDocument, templateId)
    },
    [openEditor],
  )

  const handleOpenGeneration = useCallback(
    (generationId: string) => {
      if (!generations.some((item) => item.id === generationId)) {
        toast.error('Diagram not found')
        return
      }

      openEditorRoute(generationId)
    },
    [generations, openEditorRoute],
  )

  const handleBackToStart = useCallback(() => {
    const generationId = activeGenerationIdRef.current
    backToStart(() => {
      flushPersist()
      leaveEditorRef.current()
      if (!isDirty()) {
        discardDraftIfUnsaved(generationId)
      }
    })
    setLeftPanelOpen(false)
  }, [
    activeGenerationIdRef,
    backToStart,
    discardDraftIfUnsaved,
    flushPersist,
    isDirty,
    setLeftPanelOpen,
  ])

  const handleBackToStartRef = useRef(handleBackToStart)
  handleBackToStartRef.current = handleBackToStart

  useEffect(() => {
    setDocumentChrome({
      phase: phase === 'editor' ? 'editor' : 'start',
      resource: 'diagrams',
      showLeftPanelToggle: phase === 'editor',
      leftPanelLabel: 'Toggle elements panel',
      onNewDocument: () => handleBackToStartRef.current(),
      onBrowseDocuments: () => handleBackToStartRef.current(),
      newDocumentLabel: 'New diagram',
      browseDocumentsLabel: 'All diagrams',
    })
    return () => setDocumentChrome(null)
  }, [phase, setDocumentChrome])

  const handleDeleteGeneration = useCallback(
    async (generationId: string) => {
      try {
        await deleteGeneration(generationId)
        deleteDiagramGenerationDraft(generationId)
        if (activeGenerationId === generationId) {
          handleBackToStart()
        }
        toast.success('Diagram deleted')
      } catch {
        toast.error('Could not delete diagram')
      }
    },
    [activeGenerationId, deleteGeneration, handleBackToStart],
  )

  const handleRenameGeneration = useCallback(
    async (generationId: string, name: string) => {
      try {
        await renameGeneration(generationId, name)
        toast.success('Name updated')
      } catch {
        toast.error('Could not update name')
        throw new Error('Could not update name')
      }
    },
    [renameGeneration],
  )

  const handleEditorTitleChange = useCallback(
    async (name: string) => {
      if (!activeGenerationId) return

      const trimmed = name.trim().slice(0, maxNameLength)
      if (!trimmed) return

      setDocument({ title: trimmed })

      const draft = getDiagramGenerationDraft(activeGenerationId)
      if (draft) {
        setDiagramGenerationDraft({
          ...draft,
          name: trimmed,
          updatedAt: Date.now(),
          document: { ...draft.document, title: trimmed },
        })
      }

      if (generations.some((item) => item.id === activeGenerationId)) {
        await handleRenameGeneration(activeGenerationId, trimmed)
      }
    },
    [
      activeGenerationId,
      generations,
      handleRenameGeneration,
      maxNameLength,
      setDocument,
    ],
  )

  const handleEditorTitleChangeRef = useRef(handleEditorTitleChange)
  handleEditorTitleChangeRef.current = handleEditorTitleChange

  useEffect(() => {
    if (phase !== 'editor' || !activeGenerationId) {
      setEditorTitle(null)
      return
    }

    const documentName = document.title.trim() || 'Untitled diagram'
    setEditorTitle({
      name: documentName,
      maxLength: maxNameLength,
      isSaving: isRenaming,
      onChange: (name) => handleEditorTitleChangeRef.current(name),
    })
    return () => setEditorTitle(null)
  }, [
    activeGenerationId,
    document.title,
    isRenaming,
    maxNameLength,
    phase,
    setEditorTitle,
  ])

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
      if (phase !== 'editor') return

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

      if (isMeta && !event.shiftKey) {
        if (isEditableTarget(target)) return

        const key = event.key.toLowerCase()
        if (key === 'c') {
          if (copySelection()) {
            event.preventDefault()
          }
          return
        }

        if (key === 'v') {
          if (pasteClipboard()) {
            event.preventDefault()
          }
          return
        }

        if (key === 'x') {
          if (cutSelection()) {
            event.preventDefault()
          }
          return
        }
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
  }, [
    canRedo,
    canUndo,
    copySelection,
    cutSelection,
    pasteClipboard,
    phase,
    redo,
    removeEdge,
    removeNode,
    removeNodes,
    selection,
    undo,
  ])

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

  if (phase === 'editor' && (isRouteSyncing || activeGenerationId !== routeGenerationId)) {
    return (
      <div className="flex h-full items-center justify-center text-[13px] text-muted-foreground">
        Loading diagram…
      </div>
    )
  }

  if (phase === 'start') {
    return (
      <DiagramStartView
        generations={generations}
        isAuthenticated={isAuthenticated}
        isDeleting={isDeleting}
        isRenaming={isRenaming}
        maxNameLength={maxNameLength}
        onSelectTemplate={handleSelectTemplate}
        onOpenGeneration={handleOpenGeneration}
        onRenameGeneration={handleRenameGeneration}
        onDeleteGeneration={(generationId) => {
          void handleDeleteGeneration(generationId)
        }}
      />
    )
  }

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
              <DiagramElementsPanel
                theme={document.theme}
                onThemeChange={setTheme}
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
              <DiagramElementsPanel
                theme={document.theme}
                onThemeChange={setTheme}
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
