import { useMemo, useRef, useState } from 'react'
import { ID } from '@appwrite.io/console'
import { ImagePlus, Loader2, X } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  APPS_LOGO_BUCKET_ID,
  buildAppLogoFilePermissions,
  getAppLogoFilePreviewUrl,
  getAppsLogoConsoleStorageSdk,
  resolveAppLogoDisplayUrl,
  resolveAppsLogoConsoleRegion,
} from '@/lib/appwrite/apps-logo'
import { useProjectsForTeam } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

const ACCEPTED_IMAGE_TYPES =
  'image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp'
const MAX_IMAGES = 12

type AppImagesPickerProps = {
  teamId: string
  value: string[]
  onChange: (images: string[]) => void
  disabled?: boolean
  region?: string | null
}

function isSupportedImageFile(file: File) {
  const lower = file.name.toLowerCase()
  return (
    lower.endsWith('.png') ||
    lower.endsWith('.jpg') ||
    lower.endsWith('.jpeg') ||
    lower.endsWith('.webp')
  )
}

export function AppImagesPicker({
  teamId,
  value,
  onChange,
  disabled = false,
  region,
}: AppImagesPickerProps) {
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

  const images = useMemo(
    () => value.map((item) => item.trim()).filter(Boolean),
    [value],
  )
  const atLimit = images.length >= MAX_IMAGES

  const handleUpload = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const files = Array.from(event.target.files ?? [])
    event.target.value = ''
    if (files.length === 0 || disabled || uploading || !teamId) return

    const remaining = MAX_IMAGES - images.length
    if (remaining <= 0) {
      toast.error(t('You can add up to 12 images.'))
      return
    }

    const selected = files.slice(0, remaining)
    const unsupported = selected.filter((file) => !isSupportedImageFile(file))
    if (unsupported.length > 0) {
      toast.error(t('Only PNG, JPEG, or WebP images are supported'))
      return
    }

    setUploading(true)
    try {
      const uploadedUrls: string[] = []
      for (const file of selected) {
        const uploaded = await consoleStorageSdk.storage.createFile({
          bucketId: APPS_LOGO_BUCKET_ID,
          fileId: ID.unique(),
          file,
          permissions: buildAppLogoFilePermissions(teamId),
        })
        uploadedUrls.push(
          getAppLogoFilePreviewUrl(uploaded.$id, {
            width: 1280,
            height: 720,
            region: consoleRegion,
          }),
        )
      }

      const next = [...images]
      for (const url of uploadedUrls) {
        if (!next.includes(url)) next.push(url)
      }
      onChange(next)
      toast.success(
        uploadedUrls.length === 1
          ? t('Image uploaded')
          : t('Images uploaded'),
      )
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to upload image')))
    } finally {
      setUploading(false)
      if (document.activeElement instanceof HTMLElement) {
        document.activeElement.blur()
      }
    }
  }

  const removeAt = (index: number) => {
    onChange(images.filter((_, i) => i !== index))
  }

  return (
    <div className="space-y-2">
      <p className="text-[12px] text-muted-foreground">
        {t('Optional screenshots shown on the marketplace listing.')}
      </p>

      <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
        {images.map((image, index) => {
          const src =
            resolveAppLogoDisplayUrl(image, {
              width: 320,
              height: 180,
              region: consoleRegion,
            }) ?? image
          return (
            <div
              key={`${image}-${index}`}
              className="group relative aspect-[16/10] overflow-hidden rounded-lg border border-border bg-card"
            >
              <img
                src={src}
                alt={t('Marketplace image')}
                className="h-full w-full object-cover"
              />
              <Button
                type="button"
                variant="secondary"
                size="sm"
                className={cn(
                  'absolute end-1.5 top-1.5 h-7 w-7 p-0 opacity-100 sm:opacity-0 sm:group-hover:opacity-100',
                  'bg-background/90 hover:bg-background',
                )}
                disabled={disabled || uploading}
                aria-label={t('Remove image')}
                onClick={() => removeAt(index)}
              >
                <X className="h-3.5 w-3.5" />
              </Button>
            </div>
          )
        })}

        {!atLimit ? (
          <button
            type="button"
            disabled={disabled || uploading || !teamId}
            onClick={() => uploadInputRef.current?.click()}
            className={cn(
              'flex aspect-[16/10] flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border bg-muted/20 px-2 text-center transition-colors',
              'hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              (disabled || uploading || !teamId) &&
                'cursor-not-allowed opacity-60',
            )}
          >
            {uploading ? (
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            ) : (
              <ImagePlus className="h-4 w-4 text-muted-foreground" />
            )}
            <span className="text-[11px] font-medium text-muted-foreground">
              {uploading ? t('Uploading…') : t('Add image')}
            </span>
          </button>
        ) : null}
      </div>

      <input
        ref={uploadInputRef}
        type="file"
        accept={ACCEPTED_IMAGE_TYPES}
        multiple
        className="sr-only"
        tabIndex={-1}
        disabled={disabled || uploading || !teamId || atLimit}
        onChange={handleUpload}
      />
    </div>
  )
}
