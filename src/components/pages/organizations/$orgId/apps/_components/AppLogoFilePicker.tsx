import { useMemo, useRef, useState } from 'react'
import { ID } from '@appwrite.io/console'
import { ImageIcon, Loader2, Upload, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  APPS_LOGO_BUCKET_ID,
  buildAppLogoFilePermissions,
  getAppLogoFilePreviewUrl,
  getAppsLogoConsoleStorageSdk,
  parseAppLogoFileId,
  resolveAppsLogoConsoleRegion,
} from '@/lib/appwrite/apps-logo'
import { useProjectsForTeam } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

interface AppLogoFilePickerProps {
  teamId: string
  value: string
  onChange: (logoUri: string) => void
  disabled?: boolean
  /** Prefer the current project's region when known (avoids an extra team projects lookup). */
  region?: string | null
}

export function AppLogoFilePicker({
  teamId,
  value,
  onChange,
  disabled = false,
  region,
}: AppLogoFilePickerProps) {
  const t = useT()
  const uploadInputRef = useRef<HTMLInputElement>(null)
  const { projects } = useProjectsForTeam(teamId, 0, 1)
  const consoleRegion = resolveAppsLogoConsoleRegion(
    region ?? projects[0]?.region,
  )
  const consoleStorageSdk = useMemo(
    () => getAppsLogoConsoleStorageSdk(consoleRegion),
    [consoleRegion],
  )
  const [uploading, setUploading] = useState(false)

  const selectedFromValue = useMemo(() => parseAppLogoFileId(value), [value])

  const previewUrl = useMemo(() => {
    if (!value.trim()) return null
    if (selectedFromValue) {
      return getAppLogoFilePreviewUrl(selectedFromValue, {
        width: 128,
        height: 128,
        region: consoleRegion,
      })
    }
    return value
  }, [selectedFromValue, value, consoleRegion])

  const handleUpload = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    // Clear immediately so the same file can be re-selected after an error.
    event.target.value = ''
    if (!file) return

    if (!file.name.toLowerCase().endsWith('.png')) {
      toast.error(t('Only PNG logos are supported'))
      return
    }

    setUploading(true)
    try {
      const uploaded = await consoleStorageSdk.storage.createFile({
        bucketId: APPS_LOGO_BUCKET_ID,
        fileId: ID.unique(),
        file,
        permissions: buildAppLogoFilePermissions(teamId),
      })

      onChange(
        getAppLogoFilePreviewUrl(uploaded.$id, {
          width: 256,
          height: 256,
          region: consoleRegion,
        }),
      )
      toast.success(t('Logo uploaded'))
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to upload logo')))
    } finally {
      setUploading(false)
      // Native file dialogs can leave focus/layout odd inside sheets; blur after.
      if (document.activeElement instanceof HTMLElement) {
        document.activeElement.blur()
      }
    }
  }

  return (
    <div className="rounded-lg border border-dashed border-border bg-muted/20 p-4">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
        <div className="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border bg-card">
          {previewUrl ? (
            <img
              src={previewUrl}
              alt={t('App logo preview')}
              className="h-full w-full object-contain"
            />
          ) : (
            <ImageIcon className="h-8 w-8 text-muted-foreground" />
          )}
        </div>

        <div className="min-w-0 flex-1 space-y-2">
          <p className="text-[13px] text-muted-foreground">
            {t('Upload a PNG logo for the consent screen and marketplace.')}
          </p>
          <div className="flex flex-wrap gap-2">
            <input
              ref={uploadInputRef}
              type="file"
              accept="image/png,.png"
              className="sr-only"
              tabIndex={-1}
              disabled={disabled || uploading}
              onChange={handleUpload}
            />
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={disabled || uploading}
              onClick={() => uploadInputRef.current?.click()}
            >
              {uploading ? (
                <Loader2 className="me-1.5 h-3.5 w-3.5 animate-spin" />
              ) : (
                <Upload className="me-1.5 h-3.5 w-3.5" />
              )}
              {t('Upload PNG')}
            </Button>
            {value ? (
              <Button
                type="button"
                size="sm"
                variant="ghost"
                disabled={disabled || uploading}
                onClick={() => onChange('')}
              >
                <X className="me-1.5 h-3.5 w-3.5" />
                {t('Remove')}
              </Button>
            ) : null}
          </div>
        </div>
      </div>
    </div>
  )
}
