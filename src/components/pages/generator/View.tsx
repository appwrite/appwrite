import { useMemo, useState } from 'react'
import { toast } from 'sonner'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { StandaloneCommandCenterScope } from '@/components/global/providers/KeyboardShortcuts'
import { CoverApiDocsDrawer } from '@/components/pages/generator/_components/CoverApiDocsDrawer'
import { CoverCanvas } from '@/components/pages/generator/_components/CoverCanvas'
import { CoverEditorToolbar } from '@/components/pages/generator/_components/CoverEditorToolbar'
import { CoverPropertiesPanel } from '@/components/pages/generator/_components/CoverPropertiesPanel'
import { CoverTemplatePanel } from '@/components/pages/generator/_components/CoverTemplatePanel'
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

export function View() {
  const [apiDocsOpen, setApiDocsOpen] = useState(false)
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

  return (
    <StandaloneCommandCenterScope context="account">
      <ConsoleLayout
        fixedLayout
        header={{
          marketingNav: true,
        }}
        showFooter={false}
      >
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
          <CoverEditorToolbar
            data={data}
            recommendsPost={recommendsPost}
            onChange={setData}
            onCopyApiUrl={handleCopyApiUrl}
            onOpenApiDocs={() => setApiDocsOpen(true)}
            onOpenImage={handleOpenImage}
            onDownload={handleDownload}
          />

          <div className="grid min-h-0 flex-1 grid-cols-1 overflow-hidden xl:grid-cols-[280px_minmax(0,1fr)_320px]">
            <div className="hidden min-h-0 overflow-hidden border-border xl:block xl:border-r">
              <CoverTemplatePanel
                selectedTemplate={data.template}
                theme={data.theme}
                categoryFilter={typeFilter}
                onSelectTemplate={selectTemplate}
                onCategoryFilterChange={setCategoryFilter}
                onThemeChange={setTheme}
              />
            </div>

            <div className="flex min-h-0 min-w-0 flex-col overflow-hidden">
              <div className="shrink-0 border-b border-border px-3 py-2 xl:hidden">
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
              <CoverCanvas
                data={exportData}
                onCanvasSizeChange={(width, height) => setData({ ...data, width, height })}
              />
            </div>

            <div className="max-h-[42dvh] min-h-0 overflow-hidden border-border max-xl:border-t xl:max-h-none xl:border-l">
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
          </div>
        </div>

        <CoverApiDocsDrawer
          open={apiDocsOpen}
          onOpenChange={setApiDocsOpen}
          data={exportData}
        />
      </ConsoleLayout>
    </StandaloneCommandCenterScope>
  )
}
