import { Badge } from '@/components/ui/badge'
import {
  getFormFieldOpenApiTypeLabel,
  type RequestFormField,
} from '@/lib/api-explorer/request-form'
import type { ApiReferenceResponse } from '@/lib/docs/references/types'
import { cn } from '@/lib/utils'
import { MethodDescriptionMarkdown } from '@/components/global/api-explorer/MethodDescriptionMarkdown'
import {
  REQUEST_BUILDER_VALUE_INNER_COMPLEX,
} from '@/components/global/api-explorer/request-form-table'
import { ApiReferenceCollapsibleModels } from './ApiReferenceCollapsibleModels'
import { ApiReferenceCollapsibleCard } from './ApiReferenceCollapsibleCard'
import { ApiReferenceCopyableName } from './_components/ApiReferenceCopyableName'
import { getFormFieldTypeBadgeVariant, getResponseStatusVariant, REFERENCE_TYPE_PILL_CLASS } from './explorer-styles'

/** Always table layout in the reference panel (not viewport-stacked like the live explorer). */
const REFERENCE_PARAM_ROW =
  'group grid min-w-[520px] items-center border-b border-border/50 last:border-b-0 hover:bg-muted/[0.07] min-h-[44px] grid-cols-[minmax(120px,22%)_minmax(84px,auto)_80px_minmax(0,1fr)]'

const REFERENCE_PARAM_NAME_CELL =
  'flex min-h-[44px] min-w-0 items-center px-4 py-2'

const REFERENCE_PARAM_TYPE_CELL =
  'flex min-h-[44px] items-center justify-center border-s border-border/50 px-3 py-2'

const REFERENCE_PARAM_REQUIRED_CELL =
  'flex min-h-[44px] items-center justify-center border-s border-border/50 px-3 py-2 text-center'

const REFERENCE_PARAM_VALUE_CELL =
  'flex min-h-[44px] min-w-0 items-center border-s border-border/50'

const REFERENCE_PARAM_VALUE_INNER =
  'flex w-full items-center px-4 py-2'

type ApiReferenceParameterFieldsProps = {
  fields: RequestFormField[]
}

function ReferenceParameterRow({ field }: { field: RequestFormField }) {
  const typeLabel = getFormFieldOpenApiTypeLabel(field.kind)
  const hasDescription = Boolean(field.description?.trim())
  const isComplex = field.kind === 'json' || field.kind === 'array-string'

  return (
    <div className={REFERENCE_PARAM_ROW}>
      <div className={REFERENCE_PARAM_NAME_CELL}>
        <ApiReferenceCopyableName
          name={field.name}
          textClassName="text-[13px] text-foreground"
        />
      </div>
      <div className={REFERENCE_PARAM_TYPE_CELL}>
        <Badge
          variant={getFormFieldTypeBadgeVariant(field.kind)}
          className={REFERENCE_TYPE_PILL_CLASS}
        >
          {typeLabel}
        </Badge>
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
            <span className="text-muted-foreground/60">-</span>
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

const REFERENCE_RESPONSE_HEADER =
  'grid min-w-[480px] border-b border-border/50 bg-muted/20 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground grid-cols-[88px_minmax(0,1fr)]'

const REFERENCE_RESPONSE_META_ROW =
  'grid min-w-[480px] items-start border-b border-border/50 grid-cols-[88px_minmax(0,1fr)]'

const REFERENCE_RESPONSE_STATUS_CELL =
  'flex min-h-[44px] items-center px-4 py-2'

const REFERENCE_RESPONSE_TYPE_CELL =
  'flex min-h-[44px] items-center border-s border-border/50 px-4 py-2'

const REFERENCE_RESPONSE_MODELS_SECTION =
  'space-y-4 border-b border-border/50 px-4 py-4 last:border-b-0'

type ApiReferenceResponsesPanelProps = {
  responses: ApiReferenceResponse[]
}

function ReferenceResponseModels({
  models,
}: {
  models: ApiReferenceResponse['models']
}) {
  if (models.length === 0) {
    return (
      <span className="text-[13px] text-muted-foreground/60">No response body</span>
    )
  }

  if (models.length > 1) {
    return (
      <div className="space-y-2">
        <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
          Response types ({models.length})
        </p>
        <ApiReferenceCollapsibleModels models={models} />
      </div>
    )
  }

  return <ApiReferenceCollapsibleModels models={models} />
}

export function ApiReferenceResponsesPanel({
  responses,
}: ApiReferenceResponsesPanelProps) {
  if (responses.length === 0) return null

  return (
    <ApiReferenceCollapsibleCard title="Responses" cardId="responses">
      <div className="overflow-x-auto">
        <div className={REFERENCE_RESPONSE_HEADER}>
          <div className={REFERENCE_RESPONSE_STATUS_CELL}>Status</div>
          <div className={REFERENCE_RESPONSE_TYPE_CELL}>Content type</div>
        </div>
        {responses.map((response) => (
          <div key={response.code}>
            <div className={REFERENCE_RESPONSE_META_ROW}>
              <div className={REFERENCE_RESPONSE_STATUS_CELL}>
                <Badge
                  variant={getResponseStatusVariant(String(response.code))}
                  className={REFERENCE_TYPE_PILL_CLASS}
                >
                  {response.code}
                </Badge>
              </div>
              <div className={REFERENCE_RESPONSE_TYPE_CELL}>
                <span className="text-[13px] text-muted-foreground">
                  {response.contentType ?? '-'}
                </span>
              </div>
            </div>
            {response.models.length > 0 ? (
              <div className={REFERENCE_RESPONSE_MODELS_SECTION}>
                <ReferenceResponseModels models={response.models} />
              </div>
            ) : null}
          </div>
        ))}
      </div>
    </ApiReferenceCollapsibleCard>
  )
}
