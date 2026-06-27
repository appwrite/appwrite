import { useEffect, useMemo } from 'react'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { GeneratorColumnsResizableLayout } from '@/components/pages/generator/_components/GeneratorColumnsResizableLayout'
import { CoverCanvas } from '@/components/pages/generator/_components/CoverCanvas'
import { CoverPropertiesPanel } from '@/components/pages/generator/_components/CoverPropertiesPanel'
import { CoverTemplatePanel } from '@/components/pages/generator/_components/CoverTemplatePanel'
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
import { useCoverGeneratorState } from '@/lib/cover-generator/use-cover-generator-state'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import type { CoverImageFormat } from '@/lib/cover-generator/constants'
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

export function CoverView() {
  const {
    setCoverExportData,
    setApiDocsOpen,
    leftPanelOpen,
    rightPanelOpen,
    setLeftPanelOpen,
    setRightPanelOpen,
  } = useGeneratorLayout()
  const { account } = useAuth()
  const consoleAccount = account as ConsoleAccountCache | undefined
  const { layout, persistLayout } = useCoverGeneratorColumnsLayout(consoleAccount)
  const {
    data,
    imageFields,
    typeFilter,
    setData,
    setImageField,
    setImageFile,
    setCategoryFilter,
    selectTemplate,
    setFormat,
    setTheme,
    resetCurrentTemplate,
  } = useCoverGeneratorState()
  const isXlUp = useIsXlUp()

  const exportData = useMemo(
    () => mergeImageFieldsIntoData(data, imageFields),
    [data, imageFields],
  )

  const apiUrl = useMemo(() => {
    if (typeof window === 'undefined') return buildCoverApiUrl(exportData)
    return buildCoverApiUrl(exportData, window.location.origin)
  }, [exportData])

  const recommendsPost = useMemo(() => {
    if (typeof window === 'undefined') return false
    return shouldPostCoverRenderRequest(exportData, window.location.origin)
  }, [exportData])

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

  useEffect(() => {
    setCoverExportData(exportData)
    return () => setCoverExportData(null)
  }, [exportData, setCoverExportData])

  return (
    <div className="flex h-full min-h-0 flex-col overflow-hidden">
        <div className="min-h-0 flex-1 overflow-hidden">
          {!isXlUp ? (
          <div className="flex h-full min-h-0 flex-col overflow-hidden">
            {leftPanelOpen ? (
              <div className="shrink-0 border-b border-border px-4 py-3">
                <CoverTemplatePanel
                  selectedTemplate={data.template}
                  theme={data.theme}
                  categoryFilter={typeFilter}
                  onSelectTemplate={selectTemplate}
                  onCategoryFilterChange={setCategoryFilter}
                  onThemeChange={setTheme}
                  variant="compact"
                />
              </div>
            ) : null}
            <CoverCanvas
              data={exportData}
              recommendsPost={recommendsPost}
              onCanvasSizeChange={(width, height) => setData({ ...data, width, height })}
              onCopyApiUrl={handleCopyApiUrl}
              onOpenImage={handleOpenImage}
              onDownload={handleDownload}
            />
            {rightPanelOpen ? (
              <div className="max-h-[42dvh] min-h-0 shrink-0 overflow-hidden border-t border-border">
                <CoverPropertiesPanel
                  data={data}
                  imageFields={imageFields}
                  apiUrl={apiUrl}
                  onChange={setData}
                  onImageFieldChange={setImageField}
                  onImageFileUpload={setImageFile}
                  onResetTemplate={resetCurrentTemplate}
                />
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
                <CoverTemplatePanel
                  selectedTemplate={data.template}
                  theme={data.theme}
                  categoryFilter={typeFilter}
                  onSelectTemplate={selectTemplate}
                  onCategoryFilterChange={setCategoryFilter}
                  onThemeChange={setTheme}
                />
              </div>
            }
            canvas={
              <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden">
                <CoverCanvas
                  data={exportData}
                  recommendsPost={recommendsPost}
                  onCanvasSizeChange={(width, height) =>
                    setData({ ...data, width, height })
                  }
                  onCopyApiUrl={handleCopyApiUrl}
                  onOpenImage={handleOpenImage}
                  onDownload={handleDownload}
                />
              </div>
            }
            properties={
              <div className="flex h-full min-h-0 flex-col overflow-hidden border-l border-border bg-background">
                <CoverPropertiesPanel
                  data={data}
                  imageFields={imageFields}
                  apiUrl={apiUrl}
                  onChange={setData}
                  onImageFieldChange={setImageField}
                  onImageFileUpload={setImageFile}
                  onResetTemplate={resetCurrentTemplate}
                />
              </div>
            }
          />
          )}
        </div>
    </div>
  )
}
