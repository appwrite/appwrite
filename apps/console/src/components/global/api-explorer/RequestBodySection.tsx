import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import {
  RequestBuilderSection,
  RequestFormFields,
} from './RequestFormFields'
import type { FormValue, RequestFormField } from '@/lib/api-explorer/request-form'
import type { ApiExplorerMethod } from '@/lib/api-explorer/types'
import {
  parseBodyToFormValues,
  serializeBodyFromForm,
} from '@/lib/api-explorer/request-form'

type RequestBodySectionProps = {
  title?: string
  fields: RequestFormField[]
  formValues: Record<string, FormValue>
  jsonValue: string
  inputMode: 'form' | 'json'
  onFormValuesChange: (values: Record<string, FormValue>) => void
  onJsonValueChange: (value: string) => void
  onInputModeChange: (mode: 'form' | 'json') => void
  showJsonToggle?: boolean
  embedded?: boolean
  showTopBorder?: boolean
  projectId?: string
  allFormValues?: Record<string, FormValue>
  method?: ApiExplorerMethod
}

export function RequestBodySection({
  title = 'Parameters',
  fields,
  formValues,
  jsonValue,
  inputMode,
  onFormValuesChange,
  onJsonValueChange,
  onInputModeChange,
  showJsonToggle = true,
  embedded = false,
  showTopBorder = false,
  projectId,
  allFormValues,
  method,
}: RequestBodySectionProps) {
  const t = useT()
  const switchToJson = () => {
    onJsonValueChange(serializeBodyFromForm(fields, formValues))
    onInputModeChange('json')
  }

  const switchToForm = () => {
    try {
      onFormValuesChange(parseBodyToFormValues(fields, jsonValue))
      onInputModeChange('form')
    } catch {
      toast.error(t('Invalid JSON. Fix the payload before switching to form view.'))
    }
  }

  const modeToggle = (
    <div className="flex rounded-md border border-border p-0.5">
      <Button
        type="button"
        variant={inputMode === 'form' ? 'secondary' : 'ghost'}
        size="sm"
        className="h-6 px-2.5 text-[11px] font-medium"
        onClick={switchToForm}
      >
        {t('Form')}
      </Button>
      <Button
        type="button"
        variant={inputMode === 'json' ? 'secondary' : 'ghost'}
        size="sm"
        className="h-6 px-2.5 text-[11px] font-medium"
        onClick={switchToJson}
      >
        JSON
      </Button>
    </div>
  )

  const content =
    inputMode === 'form' ? (
      fields.length > 0 ? (
        <RequestFormFields
          fields={fields}
          values={formValues}
          onChange={(name, value) =>
            onFormValuesChange({ ...formValues, [name]: value })
          }
          idPrefix="body"
          projectId={projectId}
          formValues={allFormValues}
          method={method}
        />
      ) : (
        <p className="px-4 py-3 font-mono text-[13px] text-muted-foreground/70">
          {t('No body fields for this endpoint.')}
        </p>
      )
    ) : (
      <div className="px-4 py-3">
        <Textarea
          value={jsonValue}
          onChange={(event) => onJsonValueChange(event.target.value)}
          className="min-h-[200px] w-full border-0 bg-transparent px-0 font-mono text-[13px] leading-relaxed shadow-none ring-0 focus-visible:ring-0"
          spellCheck={false}
        />
      </div>
    )

  if (embedded) {
    return (
      <RequestBuilderSection
        title={t(title)}
        action={showJsonToggle ? modeToggle : undefined}
        showTopBorder={showTopBorder}
      >
        {content}
      </RequestBuilderSection>
    )
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-background">
      <RequestBuilderSection
        title={t(title)}
        action={showJsonToggle ? modeToggle : undefined}
      >
        {content}
      </RequestBuilderSection>
    </div>
  )
}
