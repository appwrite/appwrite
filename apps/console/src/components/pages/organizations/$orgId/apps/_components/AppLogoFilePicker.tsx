import { useMemo, useState } from 'react'
import { ID } from '@appwrite.io/console'
import { toast } from 'sonner'
import { ImageFilePicker } from '@/components/global/shared/ImageFilePicker'
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
import { MarketplaceAppLogo } from '../../marketplace/_components/MarketplaceAppLogo'
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

  const handleUpload = async (file: File) => {
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
    }
  }

  return (
    <ImageFilePicker
      preview={
        previewUrl ? (
          <MarketplaceAppLogo
            src={previewUrl}
            size="xl"
            alt={t('App logo preview')}
            monochrome={false}
          />
        ) : undefined
      }
      accept="image/png,.png"
      description={t(
        'Upload a PNG logo for the consent screen and marketplace.',
      )}
      uploadLabel={t('Upload PNG')}
      removeLabel={value ? t('Remove') : undefined}
      onRemove={value ? () => onChange('') : undefined}
      onFile={handleUpload}
      uploading={uploading}
      disabled={disabled}
    />
  )
}
