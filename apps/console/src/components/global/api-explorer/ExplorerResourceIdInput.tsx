import { useMemo } from 'react'
import { useT } from '@/lib/i18n/translate'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  EventResourceIdSelector,
  type ResourceIdType,
} from '@/components/global/shared/EventEditor/EventResourceIdSelector'
import { resolveResourceIdContext } from '@/lib/api-explorer/field-helpers'
import type { FormValue } from '@/lib/api-explorer/request-form'
import {
  REQUEST_BUILDER_ID_SELECTOR_POPOVER_CLASS,
  REQUEST_BUILDER_INPUT,
} from './request-form-table'

type ExplorerResourceIdValueProps = {
  inputId: string
  value: string
  onChange: (value: string) => void
}

export function ExplorerResourceIdValue({
  inputId,
  value,
  onChange,
}: ExplorerResourceIdValueProps) {
  return (
    <Input
      id={inputId}
      type="text"
      placeholder="// enter or select ID"
      value={value}
      onChange={(event) => onChange(event.target.value)}
      className={REQUEST_BUILDER_INPUT}
      spellCheck={false}
      autoComplete="off"
    />
  )
}

type ExplorerResourceIdHelperProps = {
  resourceType: ResourceIdType
  value: string
  onChange: (value: string) => void
  projectId?: string
  formValues?: Record<string, FormValue>
}

export function ExplorerResourceIdHelper({
  resourceType,
  value,
  onChange,
  projectId,
  formValues = {},
}: ExplorerResourceIdHelperProps) {
  const t = useT()
  const context = useMemo(
    () => resolveResourceIdContext(formValues),
    [formValues],
  )

  const missingContext =
    (resourceType === 'file' && !context.bucketId) ||
    (['row', 'column', 'index'].includes(resourceType) &&
      (!context.databaseId || !context.tableId))

  if (!projectId) return null

  if (missingContext) {
    return (
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled
        className="inline-flex h-8 cursor-not-allowed px-3 text-[12px] font-normal text-muted-foreground/70 opacity-100"
        title={t('Set parent resource IDs first')}
      >
        {t('Set parent ID')}
      </Button>
    )
  }

  return (
    <div className="inline-block min-w-0">
      <EventResourceIdSelector
        projectId={projectId}
        type={resourceType}
        databaseId={context.databaseId}
        tableId={context.tableId}
        bucketId={context.bucketId}
        value={value.trim() || undefined}
        onSelect={(next) => onChange(next === '*' ? '' : next)}
        placeholder={t('Select')}
        allowAllOption={false}
        triggerClassName="inline-flex h-8 w-auto max-w-[240px] items-center justify-start gap-1.5 px-3 text-[12px] font-normal leading-normal"
        contentClassName={REQUEST_BUILDER_ID_SELECTOR_POPOVER_CLASS}
      />
    </div>
  )
}

/** @deprecated Use ExplorerResourceIdValue + ExplorerResourceIdHelper */
export function ExplorerResourceIdInput({
  inputId,
  resourceType,
  value,
  onChange,
  projectId,
  formValues = {},
}: ExplorerResourceIdValueProps & ExplorerResourceIdHelperProps) {
  return (
    <div className="flex min-h-[44px] w-full items-center gap-2 px-4">
      <ExplorerResourceIdValue
        inputId={inputId}
        value={value}
        onChange={onChange}
      />
      <ExplorerResourceIdHelper
        resourceType={resourceType}
        value={value}
        onChange={onChange}
        projectId={projectId}
        formValues={formValues}
      />
    </div>
  )
}
