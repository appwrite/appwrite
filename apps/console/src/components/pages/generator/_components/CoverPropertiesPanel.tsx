import { useState } from 'react'
import { CoverEditorForm } from '@/components/pages/generator/_components/CoverEditorForm'
import { CoverThemeSelect } from '@/components/pages/generator/_components/CoverThemeSelect'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Button } from '@/components/ui/button'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import type { CoverTheme } from '@/lib/cover-generator/constants'
import { getCoverTemplateDefinition } from '@/lib/cover-generator/template-config'

type CoverPropertiesPanelProps = {
  data: CoverRenderData
  imageFields: Record<string, string | undefined>
  apiUrl: string
  onChange: (next: CoverRenderData) => void
  onThemeChange: (theme: CoverTheme) => void
  onImageFieldChange: (key: string, value: string | undefined) => void
  onImageFileUpload: (key: string, file: File) => void
  onResetTemplate: () => void
}

export function CoverPropertiesPanel({
  data,
  imageFields,
  apiUrl,
  onChange,
  onThemeChange,
  onImageFieldChange,
  onImageFileUpload,
  onResetTemplate,
}: CoverPropertiesPanelProps) {
  const [resetOpen, setResetOpen] = useState(false)
  const templateDefinition = getCoverTemplateDefinition(data.template)

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="flex shrink-0 items-start justify-between gap-3 border-b border-border px-4 py-3">
        <div className="min-w-0">
          <p className="text-[13px] font-medium text-foreground">Properties</p>
          <p className="mt-0.5 text-[12px] text-muted-foreground">
            {templateDefinition?.label ?? data.template}
          </p>
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8 shrink-0 text-[12px]"
          onClick={() => setResetOpen(true)}
        >
          Reset
        </Button>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4">
        <div className="mb-4">
          <CoverThemeSelect theme={data.theme} onThemeChange={onThemeChange} />
        </div>
        <CoverEditorForm
          data={data}
          imageFields={imageFields}
          onChange={onChange}
          onImageFieldChange={onImageFieldChange}
          onImageFileUpload={onImageFileUpload}
        />
      </div>

      <div className="shrink-0 border-t border-border bg-muted/20 px-4 py-3">
        <p className="text-[12px] font-medium text-foreground">API URL</p>
        <code className="mt-2 block max-h-24 overflow-y-auto rounded-md bg-background px-2 py-1.5 text-[10px] leading-5 text-muted-foreground">
          {apiUrl}
        </code>
      </div>

      <AlertDialog open={resetOpen} onOpenChange={setResetOpen}>
        <AlertDialogContent className="sm:max-w-md p-0">
          <AlertDialogHeader className="px-6 pt-6 pb-4 text-start">
            <AlertDialogTitle>Reset template fields</AlertDialogTitle>
            <AlertDialogDescription className="mt-2 text-[13px]">
              Restore all fields for this template to their defaults. Uploaded
              images for this template will be removed. Canvas size, cover theme,
              and export format stay the same.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter className="px-6 py-4 border-t border-border bg-muted/30 flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <AlertDialogCancel className="mt-0">Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                onResetTemplate()
                setResetOpen(false)
              }}
            >
              Reset
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
