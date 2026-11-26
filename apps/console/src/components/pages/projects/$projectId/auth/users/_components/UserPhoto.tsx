import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { PhotoAvatar } from '@/components/global/shared/Avatar'
import { ImageFilePicker } from '@/components/global/shared/ImageFilePicker'
import { sdk } from '@/lib/appwrite/sdk'
import { useT } from '@/lib/i18n/translate'
import { bumpUserPhotoVersion } from '@/lib/user-photo'
import { getErrorMessage } from '@/lib/utils/error-formatting'

const PHOTO_ACCEPT = 'image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp'
const PHOTO_MIME_TYPES = new Set(['image/png', 'image/jpeg', 'image/webp'])
/** Mirrors the API limit so oversized files fail before the upload starts. */
const PHOTO_MAX_BYTES = 5 * 1024 * 1024

interface UserPhotoProps {
  projectId: string
  userId: string
  name: string
}

export function UserPhoto({ projectId, userId, name }: UserPhotoProps) {
  const t = useT()
  const [removeDialogOpen, setRemoveDialogOpen] = useState(false)

  const uploadMutation = useMutation({
    mutationFn: (file: File) =>
      sdk.forProject(projectId).avatars.updateUserPhoto({ userId, file }),
    onSuccess: () => {
      bumpUserPhotoVersion()
      toast.success(t('User photo updated'))
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error, t('Failed to update user photo')))
    },
  })

  const removeMutation = useMutation({
    mutationFn: () =>
      sdk.forProject(projectId).avatars.deleteUserPhoto({ userId }),
    onSuccess: () => {
      bumpUserPhotoVersion()
      setRemoveDialogOpen(false)
      toast.success(t('User photo removed'))
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error, t('Failed to remove user photo')))
    },
  })

  const handleFile = (file: File) => {
    if (!PHOTO_MIME_TYPES.has(file.type)) {
      toast.error(t('Photo must be a PNG, JPEG, or WebP image'))
      return
    }
    if (file.size > PHOTO_MAX_BYTES) {
      toast.error(t('Photo must be at most 5MB'))
      return
    }
    uploadMutation.mutate(file)
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('User photo')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t("Shown next to the user's name in your app and the Console.")}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <ImageFilePicker
          preview={
            <PhotoAvatar
              userId={userId}
              projectId={projectId}
              name={name}
              placeholder="blank"
              size="xl"
            />
          }
          accept={PHOTO_ACCEPT}
          description={t('PNG, JPEG, or WebP up to 5MB.')}
          uploadLabel={t('Upload photo')}
          removeLabel={t('Remove photo')}
          onRemove={() => setRemoveDialogOpen(true)}
          onFile={handleFile}
          uploading={uploadMutation.isPending}
          disabled={removeMutation.isPending}
        />
      </div>
      <ConfirmActionDialog
        open={removeDialogOpen}
        onOpenChange={setRemoveDialogOpen}
        title={t('Remove user photo')}
        description={t(
          'The photo is replaced with a placeholder. You can upload a new photo at any time.',
        )}
        confirmLabel={t('Remove')}
        confirmVariant="destructive"
        onConfirm={() => removeMutation.mutate()}
        isConfirming={removeMutation.isPending}
      />
    </div>
  )
}
