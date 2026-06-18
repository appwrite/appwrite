import { Badge } from '@/components/ui/badge'
import {
  getFormFieldTypeLabel,
  type RequestFormField,
} from '@/lib/api-explorer/request-form'
import { cn } from '@/lib/utils'
import { MethodDescriptionMarkdown } from '@/components/global/api-explorer/MethodDescriptionMarkdown'
import {
  REQUEST_BUILDER_VALUE_INNER_COMPLEX,
} from '@/components/global/api-explorer/request-form-table'

/** Always table layout in the reference panel (not viewport-stacked like the live explorer). */
const REFERENCE_PARAM_ROW =
  'group grid min-w-[520px] items-center border-b border-border/50 last:border-b-0 hover:bg-muted/[0.07] min-h-[44px] grid-cols-[minmax(120px,22%)_72px_80px_minmax(0,1fr)]'

const REFERENCE_PARAM_NAME_CELL =
  'flex min-h-[44px] min-w-0 items-center px-4 py-2'

const REFERENCE_PARAM_TYPE_CELL =
  'flex min-h-[44px] items-center justify-center border-l border-border/50 px-3 py-2 text-center'

const REFERENCE_PARAM_REQUIRED_CELL =
  'flex min-h-[44px] items-center justify-center border-l border-border/50 px-3 py-2 text-center'

const REFERENCE_PARAM_VALUE_CELL =
  'flex min-h-[44px] min-w-0 items-center border-l border-border/50'

const REFERENCE_PARAM_VALUE_INNER =
  'flex w-full items-center px-4 py-2'

type ApiReferenceParameterFieldsProps = {
  fields: RequestFormField[]
}

function ReferenceParameterRow({ field }: { field: RequestFormField }) {
  const typeLabel = getFormFieldTypeLabel(field.kind)
  const hasDescription = Boolean(field.description?.trim())
  const isComplex = field.kind === 'json' || field.kind === 'array-string'

  return (
    <div className={REFERENCE_PARAM_ROW}>
      <div className={REFERENCE_PARAM_NAME_CELL}>
        <span className="truncate font-mono text-[13px] text-foreground">
          {field.name}
        </span>
      </div>
      <div className={REFERENCE_PARAM_TYPE_CELL}>
        <span className="text-[12px] text-muted-foreground">{typeLabel}</span>
      </div>
      <div className={REFERENCE_PARAM_REQUIRED_CELL}>
        {field.required ? (
          <span className="text-[12px] font-medium text-red-600 dark:text-red-400">
            Required
          </span>
        ) : null}
      </div>
      <div className={REFERENCE_PARAM_VALUE_CELL}>
        <div
          className={cn(
            isComplex
              ? REQUEST_BUILDER_VALUE_INNER_COMPLEX
              : REFERENCE_PARAM_VALUE_INNER,
            'text-[13px] text-muted-foreground',
          )}
        >
          {hasDescription ? (
            <MethodDescriptionMarkdown
              content={field.description!}
              className="w-full border-0 bg-transparent p-0 text-[13px] leading-relaxed text-muted-foreground [&_code]:bg-muted/50"
            />
          ) : (
            <span className="text-muted-foreground/60">—</span>
          )}
        </div>
      </div>
    </div>
  )
}

export function ApiReferenceParameterFields({
  fields,
}: ApiReferenceParameterFieldsProps) {
  if (fields.length === 0) return null

  return (
    <div className="overflow-x-auto">
      {fields.map((field) => (
        <ReferenceParameterRow key={field.name} field={field} />
      ))}
    </div>
  )
}

type ApiReferenceResponsesPanelProps = {
  responses: Array<{
    code: number
    contentType?: string
    models: Array<{ id: string; name: string }>
  }>
  version: string
}

export function ApiReferenceResponsesPanel({
  responses,
  version,
}: ApiReferenceResponsesPanelProps) {
  if (responses.length === 0) return null

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-background">
      <div className="border-b border-border px-4 py-2.5">
        <h4 className="text-[13px] font-semibold tracking-tight text-foreground">
          Responses
        </h4>
      </div>
      <ul className="divide-y divide-border/50">
        {responses.map((response) => (
          <li
            key={response.code}
            className="grid grid-cols-1 gap-2 px-4 py-3 sm:grid-cols-[80px_minmax(0,1fr)] sm:items-start"
          >
            <div className="flex items-center gap-2">
              <Badge variant="inactive" className="font-mono text-[10px] shrink-0">
                {response.code}
              </Badge>
            </div>
            <div className="min-w-0 space-y-2">
              {response.contentType ? (
                <p className="text-[12px] text-muted-foreground">
                  {response.contentType}
                </p>
              ) : null}
              {response.models.length > 0 ? (
                <ul className="space-y-1">
                  {response.models.map((model) => (
                    <li key={model.id}>
                      <a
                        href={`/docs/references/${version}/models/${model.id}`}
                        className="text-[13px] text-foreground underline-offset-4 hover:underline"
                      >
                        {model.name}
                      </a>
                    </li>
                  ))}
                </ul>
              ) : null}
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}
