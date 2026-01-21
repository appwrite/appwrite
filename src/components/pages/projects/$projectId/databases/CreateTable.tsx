import { useState, useEffect } from 'react'
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

/**
 * Validates Appwrite table ID: 1–36 chars, alphanumeric, underscore, hyphen, period.
 * Must not start with a special char.
 */
function validateTableId(id: string): boolean {
  if (!id || id.length === 0) return true
  if (id.length > 36) return false
  if (!/^[a-zA-Z0-9_]/.test(id)) return false
  return /^[a-zA-Z0-9][a-zA-Z0-9._-]*$/.test(id)
}

interface CreateTableProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreate: (data: { tableId?: string; name: string }) => void
  isLoading?: boolean
}

export function CreateTable({
  open,
  onOpenChange,
  onCreate,
  isLoading = false,
}: CreateTableProps) {
  const [tableId, setTableId] = useState<string | undefined>(undefined)
  const [name, setName] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})

  const handleOpenChange = (newOpen: boolean) => {
    if (!isLoading) {
      onOpenChange(newOpen)
      if (!newOpen) {
        resetForm()
      }
    }
  }

  const resetForm = () => {
    setTableId(undefined)
    setName('')
    setErrors({})
  }

  useEffect(() => {
    if (!open) {
      resetForm()
    }
  }, [open])

  const validate = (): boolean => {
    const newErrors: Record<string, string> = {}

    if (!name.trim()) {
      newErrors.name = 'Name is required'
    }

    if (tableId && tableId.length > 0 && !validateTableId(tableId)) {
      newErrors.tableId =
        'Table ID must be 1–36 characters, alphanumeric, underscore, hyphen, or period. Cannot start with a special character.'
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    if (!validate()) {
      return
    }

    onCreate({
      tableId,
      name: name.trim(),
    })
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>Create table</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Create a new table to store structured data with columns and rows.
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="name">
                Name <span className="text-destructive">*</span>
              </Label>
              <Input
                id="name"
                type="text"
                placeholder="Enter table name"
                value={name}
                onChange={(e) => {
                  setName(e.target.value)
                  if (errors.name) {
                    setErrors((prev) => ({ ...prev, name: '' }))
                  }
                }}
                disabled={isLoading}
                className={errors.name ? 'border-destructive' : ''}
              />
              {errors.name && (
                <p className="text-[12px] text-destructive">{errors.name}</p>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="table-id">Table ID</Label>
              <IdInput
                id="table-id"
                value={tableId}
                onChange={setTableId}
                maxLength={36}
                disabled={isLoading}
                placeholder="Leave blank to auto-generate"
              />
              {errors.tableId && (
                <p className="text-[12px] text-destructive">
                  {errors.tableId}
                </p>
              )}
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
              disabled={isLoading}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={isLoading || !name.trim()}>
              Create
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
