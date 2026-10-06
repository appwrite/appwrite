import type { Models } from '@appwrite.io/console'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { useT } from '@/lib/i18n/translate'

export function DeleteSubtitle({
  open,
  onOpenChange,
  subtitle,
  onConfirm,
  isConfirming,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  subtitle: Pick<Models.VideoCaption, 'name' | 'embedded'> | null
  onConfirm: () => void
  isConfirming: boolean
}) {
  const t = useT()
  return (
    <ConfirmActionDialog
      open={open}
      onOpenChange={onOpenChange}
      title={t('Delete caption')}
      description={`${t('Delete')} "${subtitle?.name ?? ''}"? ${
        subtitle?.embedded
          ? t(
              'Embedded tracks are extracted only once, so this track will not come back. This action cannot be undone.',
            )
          : t('The Storage file is kept. This action cannot be undone.')
      }`}
      confirmLabel={t('Delete')}
      confirmVariant="destructive"
      onConfirm={onConfirm}
      isConfirming={isConfirming}
    />
  )
}
