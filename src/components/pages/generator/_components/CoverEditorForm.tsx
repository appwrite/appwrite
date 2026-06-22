import { useRef } from 'react'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Slider } from '@/components/ui/slider'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { Button } from '@/components/ui/button'
import { CodeEditor } from '@/components/global/shared/CodeEditor'
import {
  CoverBuiltInIconPicker,
} from '@/components/pages/generator/_components/CoverBuiltInIconPicker'
import { CoverCardsAngledIconGridEditor } from '@/components/pages/generator/_components/CoverCardsAngledIconGridEditor'
import { CoverTableGridEditor } from '@/components/pages/generator/_components/CoverTableGridEditor'
import { CoverChartDataEditor } from '@/components/pages/generator/_components/CoverChartDataEditor'
import type {
  CoverFieldDefinition,
  CoverRenderData,
} from '@/lib/cover-generator/types'
import { getCoverTemplateDefinition } from '@/lib/cover-generator/template-config'
import { formatCoverEyebrow } from '@/lib/cover-generator/text-utils'
import { mapCoverCodeSnippetLanguageToCodeEditorLanguage } from '@/lib/cover-generator/code-snippet/constants'
import { getCoverCardsAngledLayoutResetFields } from '@/lib/cover-generator/cards-angled/constants'
import { getCoverScreenshotAngledLayoutResetFields } from '@/lib/cover-generator/cover-screenshot-angled-frame'
import { isCoverUploadedImageValue } from '@/lib/cover-generator/editor-image-fields'

type CoverEditorFormProps = {
  data: CoverRenderData
  imageFields: Record<string, string | undefined>
  onChange: (next: CoverRenderData) => void
  onImageFieldChange: (key: string, value: string | undefined) => void
  onImageFileUpload: (key: string, file: File) => void
}

function snapCoverRangeValue(
  value: number,
  min: number,
  max: number,
  step: number,
): number {
  if (!Number.isFinite(value)) return min
  const clamped = Math.min(max, Math.max(min, value))
  const snapped = Math.round(clamped / step) * step
  return Number(Math.min(max, Math.max(min, snapped)).toFixed(4))
}

function formatCoverRangeValue(value: number, step: number): string {
  if (step >= 1) return String(Math.round(value))
  const decimals = String(step).includes('.')
    ? String(step).split('.')[1]?.length ?? 2
    : 2
  return value.toFixed(decimals).replace(/\.?0+$/, '')
}

function CoverRangeField({
  field,
  value,
  onValueChange,
}: {
  field: CoverFieldDefinition
  value: string | boolean | number | undefined
  onValueChange: (value: number) => void
}) {
  const min = field.min ?? 0
  const max = field.max ?? 100
  const step = field.step ?? 1
  const numericValue =
    typeof value === 'number' && Number.isFinite(value)
      ? snapCoverRangeValue(value, min, max, step)
      : min

  const setValue = (next: number) => {
    onValueChange(snapCoverRangeValue(next, min, max, step))
  }

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between gap-2">
        <Label htmlFor={field.key} className="text-[13px]">
          {field.label}
        </Label>
        <span className="text-[12px] tabular-nums text-muted-foreground">
          {formatCoverRangeValue(numericValue, step)}
          {field.unit ? ` ${field.unit}` : ''}
        </span>
      </div>
      {field.description ? (
        <p className="text-[12px] text-muted-foreground">{field.description}</p>
      ) : null}
      <Slider
        id={field.key}
        min={min}
        max={max}
        step={step}
        value={[numericValue]}
        onValueChange={([next]) => setValue(next ?? numericValue)}
        className="py-1"
      />
    </div>
  )
}

function CoverFieldInput({
  field,
  value,
  editorData,
  onValueChange,
  onImageChange,
  onImageFileUpload,
  onClearImageField,
  onSelectBuiltInIcon,
}: {
  field: CoverFieldDefinition
  value: string | boolean | number | undefined
  editorData: CoverRenderData
  onValueChange: (value: string | boolean | number) => void
  onImageChange: (value: string | undefined) => void
  onImageFileUpload: (file: File) => void
  onClearImageField: () => void
  onSelectBuiltInIcon: (path: string) => void
}) {
  const fileInputRef = useRef<HTMLInputElement>(null)

  if (field.type === 'boolean') {
    return (
      <div className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2.5">
        <div>
          <Label htmlFor={field.key} className="text-[13px]">
            {field.label}
          </Label>
          {field.description ? (
            <p className="mt-1 text-[12px] text-muted-foreground">{field.description}</p>
          ) : null}
        </div>
        <Switch
          id={field.key}
          checked={Boolean(value)}
          onCheckedChange={(checked) => onValueChange(checked)}
        />
      </div>
    )
  }

  if (field.type === 'select') {
    return (
      <div className="space-y-2">
        <Label htmlFor={field.key} className="text-[13px]">
          {field.label}
        </Label>
        <Select
          value={String(value ?? field.options?.[0]?.value ?? '')}
          onValueChange={(next) => onValueChange(next)}
        >
          <SelectTrigger id={field.key} className="h-9 text-[13px]">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {(field.options ?? []).map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
    )
  }

  if (field.type === 'image') {
    const stringValue = typeof value === 'string' ? value : ''
    const isUploadedImage = stringValue.length > 0 && isCoverUploadedImageValue(stringValue)
    const showBuiltInPicker = field.imagePicker === 'builtin-icons'

    return (
      <div className="space-y-3">
        {showBuiltInPicker ? (
          <CoverBuiltInIconPicker
            id={field.key}
            label={field.label}
            description={field.description}
            value={stringValue}
            isCustomImage={isUploadedImage}
            onSelectBuiltIn={onSelectBuiltInIcon}
          />
        ) : (
          <>
            <Label htmlFor={field.key} className="text-[13px]">
              {field.label}
            </Label>
            {field.description ? (
              <p className="text-[12px] text-muted-foreground">{field.description}</p>
            ) : null}
          </>
        )}

        {isUploadedImage ? (
          <div className="flex items-center gap-3 rounded-lg border border-border bg-muted/20 p-3">
            <img
              src={stringValue}
              alt=""
              className="h-14 w-14 shrink-0 rounded-md border border-border object-cover"
            />
            <div className="min-w-0 flex-1">
              <p className="text-[13px] font-medium text-foreground">Uploaded image</p>
              <p className="text-[12px] text-muted-foreground">
                Saved locally in this browser for preview and export
              </p>
            </div>
          </div>
        ) : null}

        <div className="space-y-2">
          {showBuiltInPicker ? (
            <p className="text-[12px] font-medium text-muted-foreground">Custom image</p>
          ) : null}
          <Input
            id={field.key}
            value={isUploadedImage ? '' : stringValue}
            onChange={(event) => {
              onClearImageField()
              onImageChange(event.target.value || undefined)
            }}
            placeholder={field.placeholder ?? 'Paste an image URL or path'}
            className="h-9 text-[13px]"
            disabled={isUploadedImage}
          />
          <div className="flex flex-wrap gap-2">
            <input
              ref={fileInputRef}
              type="file"
              accept="image/*,.svg"
              className="hidden"
              onChange={async (event) => {
                const file = event.target.files?.[0]
                if (!file) return
                onImageFileUpload(file)
                event.target.value = ''
              }}
            />
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 text-[12px]"
              onClick={() => fileInputRef.current?.click()}
            >
              Upload image
            </Button>
            {stringValue ? (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 text-[12px]"
                onClick={() => {
                  onClearImageField()
                  onImageChange(undefined)
                }}
              >
                Clear
              </Button>
            ) : null}
          </div>
        </div>
      </div>
    )
  }

  if (field.type === 'range') {
    return (
      <CoverRangeField field={field} value={value} onValueChange={onValueChange} />
    )
  }

  if (field.type === 'code') {
    const languageKey = field.codeLanguageField ?? 'language'
    const languageValue = (editorData as Record<string, unknown>)[languageKey]
    const monacoLanguage =
      editorData.template === 'code-snippet'
        ? mapCoverCodeSnippetLanguageToCodeEditorLanguage(
            typeof languageValue === 'string' ? languageValue : undefined,
          )
        : 'plaintext'

    return (
      <div className="space-y-2">
        <Label htmlFor={field.key} className="text-[13px]">
          {field.label}
        </Label>
        {field.description ? (
          <p className="text-[12px] text-muted-foreground">{field.description}</p>
        ) : null}
        <CodeEditor
          value={String(value ?? '')}
          onChange={(next) => onValueChange(next)}
          language={monacoLanguage}
          height={280}
          minimap={false}
          lineNumbers="on"
          modelPath={`cover-generator/${editorData.template}/${field.key}`}
          className="min-h-[280px] rounded-lg"
        />
      </div>
    )
  }

  const commonProps = {
    id: field.key,
    placeholder: field.placeholder,
    className: 'text-[13px]',
  }

  if (field.type === 'textarea') {
    return (
      <div className="space-y-2">
        <Label htmlFor={field.key} className="text-[13px]">
          {field.label}
        </Label>
        <Textarea
          {...commonProps}
          value={String(value ?? '')}
          onChange={(event) => onValueChange(event.target.value)}
          className="min-h-[96px] text-[13px]"
        />
      </div>
    )
  }

  return (
    <div className="space-y-2">
      <Label htmlFor={field.key} className="text-[13px]">
        {field.label}
      </Label>
      {field.description ? (
        <p className="text-[12px] text-muted-foreground">{field.description}</p>
      ) : null}
      <Input
        {...commonProps}
        type={field.type === 'number' ? 'number' : 'text'}
        min={field.min}
        max={field.max}
        step={field.step}
        value={value == null ? '' : String(value)}
        onChange={(event) => {
          if (field.type === 'number') {
            onValueChange(Number(event.target.value))
            return
          }
          onValueChange(event.target.value)
        }}
        className="h-9 text-[13px]"
      />
    </div>
  )
}

function getFieldValue(data: CoverRenderData, key: string) {
  return (data as Record<string, unknown>)[key] as
    | string
    | boolean
    | number
    | undefined
}

function setFieldValue(data: CoverRenderData, key: string, value: unknown): CoverRenderData {
  return {
    ...data,
    [key]: value,
  } as CoverRenderData
}

export function CoverEditorForm({
  data,
  imageFields,
  onChange,
  onImageFieldChange,
  onImageFileUpload,
}: CoverEditorFormProps) {
  const templateDefinition = getCoverTemplateDefinition(data.template)
  const fields = templateDefinition?.fields ?? []

  const handleResetPerspectiveLayout = () => {
    if (data.template === 'screenshot-angled') {
      onChange({
        ...data,
        ...getCoverScreenshotAngledLayoutResetFields(),
      })
      return
    }

    if (data.template === 'cards-angled') {
      onChange({
        ...data,
        ...getCoverCardsAngledLayoutResetFields(),
      })
    }
  }

  return (
    <div className="space-y-4">
      {fields.flatMap((field) => {
        const items = []

        if (
          (data.template === 'screenshot-angled' || data.template === 'cards-angled') &&
          field.key === 'displayScale'
        ) {
          items.push(
            <div
              key="perspective-layout-reset"
              className="flex items-center justify-between gap-2 rounded-lg border border-border bg-muted/20 px-3 py-2"
            >
              <p className="text-[12px] text-muted-foreground">3D position and layout</p>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 shrink-0 text-[12px]"
                onClick={handleResetPerspectiveLayout}
              >
                Reset
              </Button>
            </div>,
          )
        }

        items.push(
          <div key={field.key}>
            <CoverFieldInput
              field={field}
              editorData={data}
              value={
                field.type === 'image'
                  ? imageFields[field.key] ?? getFieldValue(data, field.key)
                  : getFieldValue(data, field.key)
              }
              onValueChange={(value) => {
                const nextValue =
                  field.key === 'eyebrow' && typeof value === 'string'
                    ? formatCoverEyebrow(value) ?? value
                    : value
                onChange(setFieldValue(data, field.key, nextValue))
              }}
              onImageChange={(value) => {
                if (value && isCoverUploadedImageValue(value)) {
                  onImageFieldChange(field.key, value)
                  return
                }

                onImageFieldChange(field.key, undefined)
                onChange(setFieldValue(data, field.key, value))
              }}
              onImageFileUpload={(file) => {
                onImageFileUpload(field.key, file)
              }}
              onClearImageField={() => {
                onImageFieldChange(field.key, undefined)
              }}
              onSelectBuiltInIcon={(path) => {
                onImageFieldChange(field.key, undefined)
                onChange(setFieldValue(data, field.key, path))
              }}
            />
          </div>,
        )

        if (data.template === 'cards-angled' && field.key === 'gap') {
          items.push(
            <CoverCardsAngledIconGridEditor
              key="cards-angled-icon-grid"
              data={data}
              imageFields={imageFields}
              onChange={onChange}
              onImageFieldChange={onImageFieldChange}
              onImageFileUpload={onImageFileUpload}
            />,
          )
        }

        if (data.template === 'table' && field.key === 'frameWidthPercent') {
          items.push(
            <CoverTableGridEditor
              key="table-grid-editor"
              data={data}
              onChange={onChange}
            />,
          )
        }

        if (
          (data.template === 'bar-chart' || data.template === 'line-chart') &&
          field.key === 'frameWidthPercent'
        ) {
          items.push(
            <CoverChartDataEditor
              key="chart-data-editor"
              data={data}
              onChange={onChange}
            />,
          )
        }

        return items
      })}
    </div>
  )
}
