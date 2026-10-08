import { useState, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { IdInput } from '@/components/ui/id-input'
import { Checkbox } from '@/components/ui/checkbox'
import {
  fetchTableStructureForCopy,
  createProjectTableWithStructure,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'

function validateTableId(id: string): boolean {
  if (!id || id.length === 0) return true
  if (id.length > 36) return false
  if (!/^[a-zA-Z0-9_]/.test(id)) return false
  return /^[a-zA-Z0-9][a-zA-Z0-9._-]*$/.test(id)
}

interface CreateTableSimilarProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  databaseId: string
  sourceTable: { $id: string; name?: string }
  onCreated?: (tableId: string) => void | Promise<void>
}

export function CreateTableSimilar({
  open,
  onOpenChange,
  projectId,
  databaseId,
  sourceTable,
  onCreated,
}: CreateTableSimilarProps) {
  const t = useT()
  const defaultName = `${t('Copy of')} ${sourceTable.name ?? sourceTable.$id}`
  const [name, setName] = useState(defaultName)
  const [tableId, setTableId] = useState<string | undefined>(undefined)
  const [copyStructure, setCopyStructure] = useState(true)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [isSubmitting, setIsSubmitting] = useState(false)

  const { data: structure, isLoading: structureLoading } = useQuery({
    queryKey: [
      'table-structure-for-copy',
      projectId,
      databaseId,
      sourceTable.$id,
    ],
    queryFn: () =>
      fetchTableStructureForCopy(projectId, databaseId, sourceTable.$id),
    enabled: open && !!projectId && !!databaseId && !!sourceTable.$id,
  })

  useEffect(() => {
    if (open) {
      setName(`${t('Copy of')} ${sourceTable.name ?? sourceTable.$id}`)
      setTableId(undefined)
      setCopyStructure(true)
      setErrors({})
    }
  }, [open, sourceTable.$id, sourceTable.name, t])

  const handleOpenChange = (newOpen: boolean) => {
    if (!isSubmitting) {
      onOpenChange(newOpen)
    }
  }

  const validate = (): boolean => {
    const newErrors: Record<string, string> = {}
    if (!name.trim()) newErrors.name = t('Name is required')
    if (tableId && tableId.length > 0 && !validateTableId(tableId)) {
      newErrors.tableId = t(
        'Table ID must be 1–36 characters, alphanumeric, underscore, hyphen, or period. Cannot start with a special character.',
      )
    }
    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!validate()) return
    setIsSubmitting(true)
    try {
      const columns =
        copyStructure && structure?.columns?.length
          ? structure.columns
          : undefined
      const indexes =
        copyStructure && structure?.indexes?.length
          ? structure.indexes
          : undefined
      const newTable = await createProjectTableWithStructure(
        projectId,
        databaseId,
        {
          tableId: tableId || undefined,
          name: name.trim(),
          columns,
          indexes,
        },
      )
      toast.success(`${newTable.name} ${t('has been created')}`)
      onOpenChange(false)
      await onCreated?.(newTable.$id)
    } catch (err) {
      toast.error(getErrorMessage(err) ?? t('Failed to create table'))
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>
            {t('Duplicate structure:')} "{sourceTable.name ?? sourceTable.$id}"
          </DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Create a new table with the same column structure as the source table.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="name">
                {t('Name')} <span className="text-destructive">*</span>
              </Label>
              <Input
                id="name"
                type="text"
                placeholder={t('Enter table name')}
                value={name}
                onChange={(e) => {
                  setName(e.target.value)
                  if (errors.name) setErrors((prev) => ({ ...prev, name: '' }))
                }}
                disabled={isSubmitting}
                className={errors.name ? 'border-destructive' : ''}
              />
              {errors.name && (
                <p className="text-[12px] text-destructive">{errors.name}</p>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="table-id">{t('Table ID')}</Label>
              <IdInput
                id="table-id"
                value={tableId}
                onChange={setTableId}
                maxLength={36}
                disabled={isSubmitting}
                placeholder={t('Leave blank to auto-generate')}
              />
              {errors.tableId && (
                <p className="text-[12px] text-destructive">{errors.tableId}</p>
              )}
            </div>

            <div className="flex items-start gap-2">
              <Checkbox
                id="copy-structure"
                checked={copyStructure}
                onCheckedChange={(v) => setCopyStructure(v === true)}
                disabled={isSubmitting || structureLoading}
                className="mt-0.5"
              />
              <div className="flex-1 min-w-0">
                <Label
                  htmlFor="copy-structure"
                  className="text-[13px] font-normal cursor-pointer"
                >
                  {t('Copy column and index structure')}
                </Label>
                <p
                  className="text-[12px] text-muted-foreground mt-0.5 min-h-[1.25rem] flex items-center gap-1.5"
                  aria-busy={structureLoading}
                >
                  {structureLoading ? (
                    <>
                      <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin" />
                      <span>{t('Loading structure…')}</span>
                    </>
                  ) : structure?.columns?.length != null &&
                    structure.columns.length > 0 ? (
                    <span>
                      {structure.columns.length} {t('columns')}
                      {structure.indexes?.length != null &&
                      structure.indexes.length > 0
                        ? `, ${structure.indexes.length} ${t('indexes')}`
                        : ''}
                    </span>
                  ) : null}
                </p>
              </div>
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
              disabled={isSubmitting}
            >
              {t('Cancel')}
            </Button>
            <Button type="submit" disabled={isSubmitting || !name.trim()}>
              {t('Create')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
