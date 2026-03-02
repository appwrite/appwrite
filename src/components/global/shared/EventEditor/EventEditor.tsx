/**
 * Event Editor – Progressive disclosure pill-style builder
 * Vertical stack of selectable pill groups. Each row reveals the next.
 */
import { useState } from 'react'
import { Pencil, Copy, Check } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  EVENT_SERVICES,
  getResources,
  getActions,
  getResourceActions,
  DOCS_LINK,
} from '@/lib/events-editor/events-model'
import { useEventBuilder } from '@/lib/events-editor/use-event-builder'
import { EventResourceIdSelector } from './EventResourceIdSelector'
import type { EventEditorModalProps } from './types'
import { cn } from '@/lib/utils'

export function EventEditor({
  open,
  onOpenChange,
  initialValue,
  onCreated,
  description = 'Select events that will trigger your function or webhook.',
  projectId,
}: EventEditorModalProps) {
  const builder = useEventBuilder(initialValue)
  const [copied, setCopied] = useState(false)

  const handleConfirm = () => {
    const str = builder.customMode ? builder.customInput.trim() : builder.eventString
    if (str && builder.isValid) {
      onCreated(str)
      onOpenChange(false)
      builder.reset()
    }
  }

  const handleCopy = () => {
    navigator.clipboard.writeText(builder.eventString)
    setCopied(true)
    setTimeout(() => setCopied(false), 1500)
  }

  const resources = builder.selection.service
    ? getResources(builder.selection.service)
    : []
  const actions = builder.selection.resource
    ? getResourceActions(builder.selection.service!, builder.selection.resource)
    : getActions(builder.selection.service!)
  const selectedAction = actions.find((a) => a.name === builder.selection.action)
  const showResourceRow = resources.length > 0
  const showActionRow = builder.selection.service
  const showAttributeRow =
    selectedAction?.columns && selectedAction.columns.length > 0

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>
            {initialValue ? 'Edit event' : 'Create event'}
          </DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {description}{' '}
            <a
              href={DOCS_LINK}
              target="_blank"
              rel="noopener noreferrer"
              className="text-primary underline hover:no-underline"
            >
              Learn more
            </a>
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="min-w-0 overflow-hidden px-6 pb-4 pt-4 space-y-4">
          {builder.customMode ? (
            <div className="space-y-2">
              <Input
                value={builder.customInput}
                onChange={(e) => builder.setCustomInput(e.target.value)}
                placeholder="e.g. databases.*.tables.*.rows.*.create"
                className="font-mono text-[13px]"
                autoFocus
              />
              <div className="flex gap-2">
                <Button size="sm" variant="outline" onClick={builder.cancelCustom}>
                  Cancel
                </Button>
                <Button
                  size="sm"
                  onClick={builder.applyCustomAndExit}
                  disabled={!builder.isValid}
                >
                  <Check className="h-4 w-4 mr-1.5" />
                  Apply
                </Button>
              </div>
            </div>
          ) : (
            <>
              <PillRow
                label="Service"
                value={builder.selection.service}
                options={EVENT_SERVICES.map((s) => s.name)}
                onSelect={builder.setService}
              />
              {builder.selection.service === 'databases' && projectId && (
                <IdSelectorRow
                  label="Database (optional)"
                  projectId={projectId}
                  type="database"
                  value={builder.selection.databaseId ?? '*'}
                  onSelect={(v) =>
                    builder.setDatabaseId(v === '*' ? undefined : v)
                  }
                  placeholder="All databases"
                />
              )}
              {builder.selection.service === 'buckets' && projectId && (
                <IdSelectorRow
                  label="Bucket (optional)"
                  projectId={projectId}
                  type="bucket"
                  value={builder.selection.bucketId ?? '*'}
                  onSelect={(v) =>
                    builder.setBucketId(v === '*' ? undefined : v)
                  }
                  placeholder="All buckets"
                />
              )}
              {builder.selection.service === 'functions' && projectId && (
                <IdSelectorRow
                  label="Function (optional)"
                  projectId={projectId}
                  type="function"
                  value={builder.selection.functionId ?? '*'}
                  onSelect={(v) =>
                    builder.setFunctionId(v === '*' ? undefined : v)
                  }
                  placeholder="All functions"
                />
              )}
              {builder.selection.service === 'teams' && projectId && (
                <IdSelectorRow
                  label="Team (optional)"
                  projectId={projectId}
                  type="team"
                  value={builder.selection.teamId ?? '*'}
                  onSelect={(v) =>
                    builder.setTeamId(v === '*' ? undefined : v)
                  }
                  placeholder="All teams"
                />
              )}
              {builder.selection.service === 'users' && projectId && (
                <IdSelectorRow
                  label="User (optional)"
                  projectId={projectId}
                  type="user"
                  value={builder.selection.userId ?? '*'}
                  onSelect={(v) =>
                    builder.setUserId(v === '*' ? undefined : v)
                  }
                  placeholder="All users"
                />
              )}
              {builder.selection.service === 'topics' && projectId && (
                <IdSelectorRow
                  label="Topic (optional)"
                  projectId={projectId}
                  type="topic"
                  value={builder.selection.topicId ?? '*'}
                  onSelect={(v) =>
                    builder.setTopicId(v === '*' ? undefined : v)
                  }
                  placeholder="All topics"
                />
              )}
              {builder.selection.service === 'providers' && projectId && (
                <IdSelectorRow
                  label="Provider (optional)"
                  projectId={projectId}
                  type="provider"
                  value={builder.selection.providerId ?? '*'}
                  onSelect={(v) =>
                    builder.setProviderId(v === '*' ? undefined : v)
                  }
                  placeholder="All providers"
                />
              )}
              {showResourceRow && (
                <>
                  <PillRow
                    label="Resource (optional)"
                    value={builder.selection.resource}
                    options={resources.map((r) => r.name)}
                    onSelect={builder.setResource}
                    optional
                  />
                  {builder.selection.resource &&
                    builder.selection.service === 'databases' &&
                    projectId &&
                    builder.selection.databaseId &&
                    builder.selection.databaseId !== '*' && (
                      <IdSelectorRow
                        label="Table (optional)"
                        projectId={projectId}
                        type="table"
                        databaseId={builder.selection.databaseId}
                        value={builder.selection.tableId ?? '*'}
                        onSelect={(v) =>
                          builder.setTableId(v === '*' ? undefined : v)
                        }
                        placeholder="All tables"
                      />
                    )}
                  {builder.selection.resource === 'files' &&
                    builder.selection.service === 'buckets' &&
                    projectId &&
                    builder.selection.bucketId &&
                    builder.selection.bucketId !== '*' && (
                      <IdSelectorRow
                        label="File (optional)"
                        projectId={projectId}
                        type="file"
                        bucketId={builder.selection.bucketId}
                        value={builder.selection.fileId ?? '*'}
                        onSelect={(v) =>
                          builder.setFileId(v === '*' ? undefined : v)
                        }
                        placeholder="All files"
                      />
                    )}
                  {builder.selection.resource === 'rows' &&
                    builder.selection.service === 'databases' &&
                    projectId &&
                    builder.selection.databaseId &&
                    builder.selection.databaseId !== '*' &&
                    builder.selection.tableId &&
                    builder.selection.tableId !== '*' && (
                      <IdSelectorRow
                        label="Row (optional)"
                        projectId={projectId}
                        type="row"
                        databaseId={builder.selection.databaseId}
                        tableId={builder.selection.tableId}
                        value={builder.selection.rowId ?? '*'}
                        onSelect={(v) =>
                          builder.setRowId(v === '*' ? undefined : v)
                        }
                        placeholder="All rows"
                      />
                    )}
                  {builder.selection.resource === 'columns' &&
                    builder.selection.service === 'databases' &&
                    projectId &&
                    builder.selection.databaseId &&
                    builder.selection.databaseId !== '*' &&
                    builder.selection.tableId &&
                    builder.selection.tableId !== '*' && (
                      <IdSelectorRow
                        label="Column (optional)"
                        projectId={projectId}
                        type="column"
                        databaseId={builder.selection.databaseId}
                        tableId={builder.selection.tableId}
                        value={builder.selection.columnId ?? '*'}
                        onSelect={(v) =>
                          builder.setColumnId(v === '*' ? undefined : v)
                        }
                        placeholder="All columns"
                      />
                    )}
                  {builder.selection.resource === 'indexes' &&
                    builder.selection.service === 'databases' &&
                    projectId &&
                    builder.selection.databaseId &&
                    builder.selection.databaseId !== '*' &&
                    builder.selection.tableId &&
                    builder.selection.tableId !== '*' && (
                      <IdSelectorRow
                        label="Index (optional)"
                        projectId={projectId}
                        type="index"
                        databaseId={builder.selection.databaseId}
                        tableId={builder.selection.tableId}
                        value={builder.selection.indexId ?? '*'}
                        onSelect={(v) =>
                          builder.setIndexId(v === '*' ? undefined : v)
                        }
                        placeholder="All indexes"
                      />
                    )}
                </>
              )}
              {showActionRow && (
                <PillRow
                  label="Action (optional)"
                  value={builder.selection.action}
                  options={actions.map((a) => a.name)}
                  onSelect={builder.setAction}
                  optional
                />
              )}
              {showAttributeRow && (
                <PillRow
                  label="Attribute (optional)"
                  value={builder.selection.attribute}
                  options={selectedAction!.columns!}
                  onSelect={builder.setAttribute}
                  optional
                />
              )}
              <div className="flex min-w-0 items-center gap-2 pt-2">
                <div className="min-w-0 flex-1 overflow-x-auto overflow-y-hidden rounded-md border border-border bg-muted/30 px-3 py-2 font-mono text-[12px] text-foreground select-text">
                  <span className="whitespace-nowrap">
                    {builder.eventString || (
                      <span className="text-muted-foreground">
                        Select a service to build
                      </span>
                    )}
                  </span>
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-8 w-8 p-0 shrink-0"
                  onClick={builder.enterCustomMode}
                  title="Edit manually"
                >
                  <Pencil className="h-4 w-4" />
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-8 w-8 p-0 shrink-0"
                  onClick={handleCopy}
                  disabled={!builder.eventString}
                  title="Copy"
                >
                  {copied ? (
                    <Check className="h-4 w-4 text-green-600" />
                  ) : (
                    <Copy className="h-4 w-4" />
                  )}
                </Button>
              </div>
            </>
          )}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button onClick={handleConfirm} disabled={!builder.isValid}>
            {initialValue ? 'Update' : 'Add event'}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

function IdSelectorRow({
  label,
  projectId,
  type,
  databaseId,
  tableId,
  bucketId,
  value,
  onSelect,
  placeholder,
}: {
  label: string
  projectId: string | null | undefined
  type: import('./EventResourceIdSelector').ResourceIdType
  databaseId?: string | null
  tableId?: string | null
  bucketId?: string | null
  value: string | '*' | undefined
  onSelect: (v: string | '*') => void
  placeholder?: string
}) {
  return (
    <div>
      <p className="text-[12px] font-medium text-muted-foreground mb-2">
        {label}
      </p>
      <EventResourceIdSelector
        projectId={projectId}
        type={type}
        databaseId={databaseId}
        tableId={tableId}
        bucketId={bucketId}
        value={value}
        onSelect={onSelect}
        placeholder={placeholder}
      />
    </div>
  )
}

function PillRow({
  label,
  value,
  options,
  onSelect,
  optional,
}: {
  label: string
  value: string | null
  options: string[]
  onSelect: (v: string | null) => void
  optional?: boolean
}) {
  return (
    <div>
      <p className="text-[12px] font-medium text-muted-foreground mb-2">
        {label}
      </p>
      <div className="flex flex-wrap gap-1.5">
        {optional && (
          <button
            type="button"
            onClick={() => onSelect(null)}
            className={cn(
              'rounded-md border px-2.5 py-1 text-[12px] font-medium transition-colors',
              !value
                ? 'border-primary bg-primary/10 text-primary'
                : 'border-border bg-background hover:bg-muted',
            )}
          >
            All
          </button>
        )}
        {options.map((opt) => (
          <button
            key={opt}
            type="button"
            onClick={() => onSelect(opt === value ? (optional ? null : opt) : opt)}
            className={cn(
              'rounded-md border px-2.5 py-1 text-[12px] font-medium transition-colors',
              value === opt
                ? 'border-primary bg-primary/10 text-primary'
                : 'border-border bg-background hover:bg-muted',
            )}
          >
            {opt}
          </button>
        ))}
      </div>
    </div>
  )
}
