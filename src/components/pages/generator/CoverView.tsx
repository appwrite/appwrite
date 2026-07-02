import { useCallback, useEffect, useMemo, useRef } from 'react'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { GeneratorColumnsResizableLayout } from '@/components/pages/generator/_components/GeneratorColumnsResizableLayout'
import { CoverCanvas } from '@/components/pages/generator/_components/CoverCanvas'
import { CoverPropertiesPanel } from '@/components/pages/generator/_components/CoverPropertiesPanel'
import { CoverStartView } from '@/components/pages/generator/_components/CoverStartView'
import { useGeneratorLayout } from '@/components/pages/generator/GeneratorLayoutContext'
import { useIsXlUp } from '@/hooks/use-mobile'
import { buildCoverApiUrl } from '@/lib/cover-generator/parse-params'
import {
  buildCoverDownloadData,
  downloadCoverImageBlob,
  fetchCoverImage,
  shouldPostCoverRenderRequest,
} from '@/lib/cover-generator/fetch-cover-image'
import {
  captureCoverDomPreviewBlob,
  shouldCaptureCoverDomPreviewClientSide,
} from '@/lib/cover-generator/capture-cover-dom-preview'
import type { CoverDownloadScale } from '@/lib/cover-generator/download-scale'
import {
  applyCoverGenerationName,
  getCoverGenerationDisplayName,
  resolveCoverEditorDocumentName,
  resolveCoverGenerationPersistName,
} from '@/lib/cover-generator/cover-generation-prefs'
import { DEFAULT_COVER_THEME_ID } from '@/lib/cover-generator/themes'
import { createDefaultCoverData } from '@/lib/cover-generator/parse-params'
import { useCoverGeneratorState } from '@/lib/cover-generator/use-cover-generator-state'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import type { CoverImageFormat, CoverTemplateId } from '@/lib/cover-generator/constants'
import {
  useCoverGeneratorColumnsLayout,
  type ConsoleAccountCache,
} from '@/lib/react-query/hooks/auth'
import { useCoverGenerations } from '@/lib/react-query/hooks/cover-generations'
import { useRouteGenerationEditor } from '@/lib/generator/use-route-generation-editor'
import { cn } from '@/lib/utils'

const RESIZE_HANDLE_CLASS = cn(
  'relative z-[45] w-[0.5px] bg-border',
  'before:pointer-events-none before:absolute before:inset-y-0 before:left-1/2 before:w-2 before:-translate-x-1/2 before:bg-border before:opacity-0 before:transition-opacity',
  'hover:before:opacity-100 data-[resize-handle-state=drag]:before:opacity-100',
  'after:w-2 after:left-1/2 after:-translate-x-1/2',
)

function mergeImageFieldsIntoData(
  data: CoverRenderData,
  imageFields: Record<string, string | undefined>,
): CoverRenderData {
  const next = { ...data } as Record<string, unknown>
  for (const [key, value] of Object.entries(imageFields)) {
    if (value) next[key] = value
  }
  return next as CoverRenderData
}

type CoverViewProps = {
  generationId?: string
}

export function CoverView({ generationId: routeGenerationId }: CoverViewProps = {}) {
  const {
    setCoverExportData,
    setApiDocsOpen,
    setDocumentChrome,
    setEditorTitle,
    setLeftPanelOpen,
    rightPanelOpen,
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
  } = useCoverGenerations(consoleAccount)

  const loadCoverRef = useRef<(coverData: CoverRenderData) => void>(() => {})
  const documentNameRef = useRef('')

  const loadSavedCoverGeneration = useCallback((generation: SavedCoverGeneration) => {
    loadCoverRef.current(generation.data)
  }, [])

  const getCoverGenerationId = useCallback((generation: SavedCoverGeneration) => generation.id, [])

  const {
    phase,
    activeGenerationId,
    activeGenerationIdRef,
    isRouteSyncing,
    openEditorRoute,
    backToStart,
  } = useRouteGenerationEditor({
    routeGenerationId,
    startTo: '/generator',
    editorTo: '/generator/$generationId',
    generations,
    getGenerationId: getCoverGenerationId,
    loadGeneration: loadSavedCoverGeneration,
    migrateLegacyIfNeeded,
    onEnterEditor: () => setLeftPanelOpen(false),
    notFoundMessage: 'Cover not found',
  })

  const persistActiveGeneration = useCallback(
    (coverData: CoverRenderData) => {
      const generationId = activeGenerationIdRef.current
      if (!generationId) return

      void saveGeneration({
        id: generationId,
        name: resolveCoverGenerationPersistName(coverData, documentNameRef.current),
        updatedAt: Date.now(),
        templateId: coverData.template,
        data: coverData,
      }).catch(() => {
        toast.error('Could not save cover')
      })
    },
    [saveGeneration],
  )

  const {
    data,
    imageFields,
    imagesHydrated,
    setData,
    setImageField,
    setImageFile,
    setFormat,
    setTheme,
    resetCurrentTemplate,
    loadCover,
    flushPersist,
    cancelPersistDebounce,
  } = useCoverGeneratorState({
    generationId: activeGenerationId,
    onDocumentPersist: persistActiveGeneration,
  })

  loadCoverRef.current = loadCover

  const activeGeneration = useMemo(
    () =>
      activeGenerationId
        ? generations.find((item) => item.id === activeGenerationId)
        : undefined,
    [activeGenerationId, generations],
  )

  useEffect(() => {
    if (!activeGeneration) {
      documentNameRef.current = ''
      return
    }

    documentNameRef.current = activeGeneration.name
  }, [activeGeneration])

  const isXlUp = useIsXlUp()
  const migratedLegacyRef = useRef(false)

  useEffect(() => {
    if (migratedLegacyRef.current) return
    migratedLegacyRef.current = true
    void migrateLegacyIfNeeded().catch(() => {
      /* ignore migration errors */
    })
  }, [migrateLegacyIfNeeded])

  const exportData = useMemo(
    () => mergeImageFieldsIntoData(data, imageFields),
    [data, imageFields],
  )

  const editorDocumentName = useMemo(
    () => resolveCoverEditorDocumentName(exportData, activeGeneration?.name),
    [activeGeneration?.name, exportData],
  )

  const apiUrl = useMemo(() => {
    if (typeof window === 'undefined') return buildCoverApiUrl(exportData)
    return buildCoverApiUrl(exportData, window.location.origin)
  }, [exportData])

  const recommendsPost = useMemo(() => {
    if (typeof window === 'undefined') return false
    return shouldPostCoverRenderRequest(exportData, window.location.origin)
  }, [exportData])

  const handleBackToStart = useCallback(() => {
    backToStart(flushPersist)
    setLeftPanelOpen(false)
  }, [backToStart, flushPersist, setLeftPanelOpen])

  const handleBackToStartRef = useRef(handleBackToStart)
  handleBackToStartRef.current = handleBackToStart

  useEffect(() => {
    setDocumentChrome({
      phase: phase === 'editor' ? 'editor' : 'start',
      resource: 'covers',
      showLeftPanelToggle: false,
      leftPanelLabel: 'Toggle elements panel',
      onNewDocument: () => handleBackToStartRef.current(),
      onBrowseDocuments: () => handleBackToStartRef.current(),
      newDocumentLabel: 'New cover',
      browseDocumentsLabel: 'All covers',
    })
    return () => setDocumentChrome(null)
  }, [phase, setDocumentChrome])

  useEffect(() => {
    if (phase !== 'editor') {
      setCoverExportData(null)
      return
    }

    setCoverExportData(exportData)
    return () => setCoverExportData(null)
  }, [exportData, phase, setCoverExportData])

  const openEditor = useCallback(
    async (generationId: string, coverData: CoverRenderData) => {
      try {
        await saveGeneration({
          id: generationId,
          name: getCoverGenerationDisplayName(coverData),
          updatedAt: Date.now(),
          templateId: coverData.template,
          data: coverData,
        })
      } catch {
        toast.error('Could not save cover')
        return
      }

      openEditorRoute(generationId)
    },
    [openEditorRoute, saveGeneration],
  )

  const handleSelectTemplate = useCallback(
    (templateId: CoverTemplateId) => {
      const coverData = createDefaultCoverData(templateId, DEFAULT_COVER_THEME_ID)
      void openEditor(crypto.randomUUID(), coverData)
    },
    [openEditor],
  )

  const handleOpenGeneration = useCallback(
    (generationId: string) => {
      if (!generations.some((item) => item.id === generationId)) {
        toast.error('Cover not found')
        return
      }

      openEditorRoute(generationId)
    },
    [generations, openEditorRoute],
  )

  const handleDeleteGeneration = useCallback(
    async (generationId: string) => {
      try {
        await deleteGeneration(generationId)
        if (activeGenerationId === generationId) {
          handleBackToStart()
        }
        toast.success('Cover deleted')
      } catch {
        toast.error('Could not delete cover')
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

      documentNameRef.current = trimmed
      cancelPersistDebounce()
      setData((current) => applyCoverGenerationName(current, trimmed))
      await handleRenameGeneration(activeGenerationId, trimmed)
    },
    [
      activeGenerationId,
      cancelPersistDebounce,
      handleRenameGeneration,
      maxNameLength,
      setData,
    ],
  )

  const handleEditorTitleChangeRef = useRef(handleEditorTitleChange)
  handleEditorTitleChangeRef.current = handleEditorTitleChange

  useEffect(() => {
    if (phase !== 'editor' || !activeGenerationId) {
      setEditorTitle(null)
      return
    }

    setEditorTitle({
      name: editorDocumentName,
      maxLength: maxNameLength,
      isSaving: isRenaming,
      onChange: (name) => handleEditorTitleChangeRef.current(name),
    })
    return () => setEditorTitle(null)
  }, [
    activeGenerationId,
    editorDocumentName,
    isRenaming,
    maxNameLength,
    phase,
    setEditorTitle,
  ])

  const handleCopyApiUrl = async () => {
    if (recommendsPost) {
      setApiDocsOpen(true)
      toast.message('Use POST for this cover', {
        description: 'Open the API drawer for JSON and cURL examples.',
      })
      return
    }

    try {
      await navigator.clipboard.writeText(apiUrl)
      toast.success('API URL copied')
    } catch {
      toast.error('Could not copy API URL')
    }
  }

  const handleOpenImage = async () => {
    try {
      const blob = await fetchCoverImage(exportData)
      const objectUrl = URL.createObjectURL(blob)
      window.open(objectUrl, '_blank', 'noopener,noreferrer')
      window.setTimeout(() => URL.revokeObjectURL(objectUrl), 60_000)
    } catch {
      toast.error('Could not open cover image')
    }
  }

  const handleDownload = async (format: CoverImageFormat, scale: CoverDownloadScale) => {
    setFormat(format)
    const downloadData = buildCoverDownloadData({ ...exportData, format }, scale)
    if (!downloadData) {
      toast.error('This size exceeds the maximum export dimensions')
      return
    }

    try {
      const blob = shouldCaptureCoverDomPreviewClientSide(downloadData)
        ? await captureCoverDomPreviewBlob(
            { ...exportData, format },
            {
              renderWidth: exportData.width,
              renderHeight: exportData.height,
              format,
              pixelRatio: scale,
            },
          )
        : await fetchCoverImage(downloadData)
      downloadCoverImageBlob(blob, downloadData, scale)
    } catch {
      toast.error('Could not download cover')
    }
  }

  if (phase === 'start') {
    return (
      <CoverStartView
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

  if (phase === 'editor' && (isRouteSyncing || activeGenerationId !== routeGenerationId)) {
    return (
      <div className="flex h-full items-center justify-center text-[13px] text-muted-foreground">
        Loading cover…
      </div>
    )
  }

  if (!imagesHydrated) {
    return (
      <div className="flex h-full items-center justify-center text-[13px] text-muted-foreground">
        Loading cover…
      </div>
    )
  }

  const propertiesPanel = (
    <CoverPropertiesPanel
      data={data}
      imageFields={imageFields}
      apiUrl={apiUrl}
      onChange={setData}
      onThemeChange={setTheme}
      onImageFieldChange={setImageField}
      onImageFileUpload={setImageFile}
      onResetTemplate={resetCurrentTemplate}
    />
  )

  const canvas = (
    <CoverCanvas
      data={exportData}
      recommendsPost={recommendsPost}
      onCanvasSizeChange={(width, height) => setData({ ...data, width, height })}
      onCopyApiUrl={handleCopyApiUrl}
      onOpenImage={handleOpenImage}
      onDownload={handleDownload}
    />
  )

  return (
    <div className="flex h-full min-h-0 flex-col overflow-hidden">
      <div className="min-h-0 flex-1 overflow-hidden">
        {!isXlUp ? (
          <div className="flex h-full min-h-0 flex-col overflow-hidden">
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
            leftOpen={false}
            rightOpen={rightPanelOpen}
            onLeftOpenChange={setLeftPanelOpen}
            onRightOpenChange={setRightPanelOpen}
            handleClassName={RESIZE_HANDLE_CLASS}
            className="h-full min-h-0"
            templates={<div className="hidden" />}
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
