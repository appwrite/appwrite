import { useRef, useState } from 'react'
import { useT } from '@/lib/i18n/translate'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  PermissionsEditor,
  type PermissionsEditorHandle,
} from '@/components/pages/projects/$projectId/auth/PermissionsEditor'
import {
  getFormFieldPlaceholder,
  type FormValue,
  type RequestFormField,
} from '@/lib/api-explorer/request-form'
import {
  ExplorerArrayItemHelperRows,
  ExplorerArrayItemInputs,
  ExplorerCombinedArrayField,
} from './ExplorerArrayItemInputs'

type ExplorerPermissionsFieldProps = {
  field: RequestFormField
  value: FormValue
  onChange: (value: FormValue) => void
  inputId: string
  projectId?: string
  part: 'value' | 'helper' | 'combined'
}

export function ExplorerPermissionsField({
  field,
  value,
  onChange,
  inputId,
  projectId,
  part,
}: ExplorerPermissionsFieldProps) {
  const items = Array.isArray(value) ? value.map(String) : []
  const helper = field.helper?.type === 'permissions' ? field.helper : undefined

  const setItems = (next: string[]) => {
    onChange(next)
  }

  if (part === 'combined') {
    return (
      <ExplorerCombinedArrayField
        idPrefix={inputId}
        items={items}
        onChange={setItems}
        placeholder={getFormFieldPlaceholder('string')}
        renderItemHelper={(index) => (
          <ExplorerPermissionsItemHelper
            index={index}
            items={items}
            onChange={setItems}
            withCreate={helper?.withCreate}
            withWrite={helper?.withWrite}
            executeOnly={helper?.executeOnly}
            projectId={projectId}
          />
        )}
      />
    )
  }

  if (part === 'value') {
    return (
      <ExplorerArrayItemInputs
        idPrefix={inputId}
        items={items}
        onChange={setItems}
        emptyState="add-control"
        placeholder={getFormFieldPlaceholder('string')}
      />
    )
  }

  if (items.length === 0) {
    return null
  }

  return (
    <ExplorerArrayItemHelperRows
      count={items.length}
      renderItemHelper={(index) => (
        <ExplorerPermissionsItemHelper
          index={index}
          items={items}
          onChange={setItems}
          withCreate={helper?.withCreate}
          withWrite={helper?.withWrite}
          executeOnly={helper?.executeOnly}
          projectId={projectId}
        />
      )}
    />
  )
}

/** @deprecated Use ExplorerPermissionsField with part="value" */
export function ExplorerPermissionsValue({ value }: { value: FormValue }) {
  return (
    <ExplorerArrayItemInputs
      idPrefix="permissions"
      items={Array.isArray(value) ? value.map(String) : []}
      onChange={() => undefined}
      emptyLabel="No permissions"
      placeholder="// permission string"
    />
  )
}

/** @deprecated Use ExplorerPermissionsField with part="helper" */
export function ExplorerPermissionsHelper(props: Omit<ExplorerPermissionsFieldProps, 'part' | 'inputId'>) {
  return (
    <ExplorerPermissionsField
      {...props}
      inputId="permissions"
      part="helper"
    />
  )
}

type ExplorerPermissionsItemHelperProps = {
  index: number
  items: string[]
  onChange: (items: string[]) => void
  withCreate?: boolean
  withWrite?: boolean
  executeOnly?: boolean
  projectId?: string
}

function applyPermissionEntryAtIndex(
  items: string[],
  index: number,
  next: string[],
): string[] {
  const copy = [...items]
  const filtered = next.map((entry) => entry.trim()).filter(Boolean)

  if (filtered.length === 0) {
    copy[index] = ''
  } else if (filtered.length === 1) {
    copy[index] = filtered[0]
  } else {
    copy.splice(index, 1, ...filtered)
  }

  return copy
}

function ExplorerPermissionsItemHelper({
  index,
  items,
  onChange,
  withCreate,
  withWrite,
  executeOnly,
  projectId,
}: ExplorerPermissionsItemHelperProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const [editorKey, setEditorKey] = useState(0)
  const [sessionPermissions, setSessionPermissions] = useState<string[]>([])
  const editorRef = useRef<PermissionsEditorHandle>(null)
  const current = items[index] ?? ''

  const openDialog = () => {
    setSessionPermissions(current.trim() ? [current.trim()] : [])
    setEditorKey((key) => key + 1)
    setOpen(true)
  }

  const handleDone = () => {
    const next = editorRef.current?.getPermissions() ?? []
    onChange(applyPermissionEntryAtIndex(items, index, next))
    setOpen(false)
  }

  return (
    <>
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="h-8 shrink-0 px-3 text-[12px] leading-none"
        onClick={openDialog}
      >
        {t('Build')}
      </Button>

      <Dialog
        open={open}
        onOpenChange={(nextOpen) => {
          if (!nextOpen) {
            setOpen(false)
          }
        }}
      >
        <DialogContent className="flex max-h-[min(90dvh,720px)] w-[min(96vw,720px)] flex-col gap-0 overflow-hidden p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Build permission')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Build a permission string for this entry. Changes apply when you click Done.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="min-h-0 flex-1 overflow-y-auto px-6 py-4">
            {open ? (
              <PermissionsEditor
                ref={editorRef}
                key={editorKey}
                deferChanges
                permissions={sessionPermissions}
                withCreate={withCreate}
                withWrite={withWrite}
                executeOnly={executeOnly}
                projectId={projectId}
                compact
              />
            ) : null}
          </div>
          <div className="border-t border-border bg-muted/30 px-6 py-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setOpen(false)}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleDone}
            >
              {t('Done')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
