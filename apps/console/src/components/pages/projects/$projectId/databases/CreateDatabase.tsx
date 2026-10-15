import { useState, useEffect, useRef } from 'react'
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
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { AlertCircle } from 'lucide-react'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import { DEFAULT_NEW_DATABASE_NAME } from '@/lib/default-new-database-name'
import { useT } from '@/lib/i18n/translate'

/**
 * Validates Appwrite database ID: 1–36 chars, alphanumeric, underscore, hyphen, period.
 * Must not start with a special char.
 */
function validateDatabaseId(id: string): boolean {
  if (!id || id.length === 0) return true
  if (id.length > 36) return false
  if (!/^[a-zA-Z0-9_]/.test(id)) return false
  return /^[a-zA-Z0-9][a-zA-Z0-9._-]*$/.test(id)
}

interface CreateDatabaseProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreate: (data: { databaseId?: string; name: string }) => void
  isLoading?: boolean
  /** When false, a warning is shown that the database will not be backed up. Omit when unknown/loading. */
  backupsEnabled?: boolean
  /** Organization id for the upgrade wizard link in the backups warning. */
  orgId?: string | null
}

export function CreateDatabase({
  open,
  onOpenChange,
  onCreate,
  isLoading = false,
  backupsEnabled,
  orgId,
}: CreateDatabaseProps) {
  const t = useT()
  const [databaseId, setDatabaseId] = useState<string | undefined>(undefined)
  const [name, setName] = useState(DEFAULT_NEW_DATABASE_NAME)
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
    setDatabaseId(undefined)
    setName(DEFAULT_NEW_DATABASE_NAME)
    setErrors({})
  }

  const prevOpenRef = useRef(open)
  useEffect(() => {
    if (prevOpenRef.current && !open) {
      resetForm()
    }
    prevOpenRef.current = open
  }, [open])

  const validate = (): boolean => {
    const newErrors: Record<string, string> = {}

    if (!name.trim()) {
      newErrors.name = t('Name is required')
    }

    if (
      databaseId &&
      databaseId.length > 0 &&
      !validateDatabaseId(databaseId)
    ) {
      newErrors.databaseId = t(
        'Database ID must be 1–36 characters, alphanumeric, underscore, hyphen, or period. Cannot start with a special character.',
      )
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
      databaseId,
      name: name.trim(),
    })
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Create database')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Create a new database to store and organize your data in tables.',
            )}
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
                placeholder={t('Enter database name')}
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
              <Label htmlFor="database-id">{t('Database ID')}</Label>
              <IdInput
                id="database-id"
                value={databaseId}
                onChange={setDatabaseId}
                maxLength={36}
                disabled={isLoading}
                placeholder={t('Leave blank to auto-generate')}
              />
              {errors.databaseId && (
                <p className="text-[12px] text-destructive">
                  {errors.databaseId}
                </p>
              )}
            </div>

            {backupsEnabled === false && (
              <Alert
                variant="default"
                className="border-amber-500/30 bg-amber-500/5"
              >
                <AlertCircle className="h-4 w-4 text-amber-500" />
                <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                  {t('Backups not enabled')}
                </AlertTitle>
                <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                  {t('This database will not be backed up on your current plan.')}{' '}
                  <UpgradePlanLink orgId={orgId} />{' '}
                  {t('to enable automated backups.')}
                </AlertDescription>
              </Alert>
            )}
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
              disabled={isLoading}
            >
              {t('Cancel')}
            </Button>
            <Button type="submit" disabled={isLoading || !name.trim()}>
              {t('Create')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
