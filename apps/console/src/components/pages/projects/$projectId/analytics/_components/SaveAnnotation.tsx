import { useEffect, useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { DateTimePicker } from '@/components/global/shared/DateTimePicker'
import { useSaveAnalyticsAnnotation } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

const CONTENT_MAX_LENGTH = 512

/** Add a note to the analytics chart, or edit one when `annotation` is set. */
export function SaveAnnotation({
  open,
  onOpenChange,
  projectId,
  propertyId,
  annotation,
  date,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  propertyId: string
  annotation?: Models.AnalyticsAnnotation
  /** Date a new note starts on. */
  date: string
}) {
  const t = useT()
  const mutation = useSaveAnalyticsAnnotation(projectId, propertyId)
  const [draftDate, setDraftDate] = useState<string | null>(date)
  const [content, setContent] = useState('')

  useEffect(() => {
    if (!open) return
    setDraftDate(annotation?.date ?? date)
    setContent(annotation?.content ?? '')
  }, [open, annotation, date])

  const canSave = !!draftDate && content.trim() !== '' && !mutation.isPending

  const save = () => {
    if (!draftDate || !canSave) return
    mutation.mutate(
      { annotationId: annotation?.$id, date: draftDate, content },
      {
        onSuccess: () => {
          toast.success(annotation ? t('Note updated') : t('Note added'))
          onOpenChange(false)
        },
        onError: (error) => toast.error(getErrorMessage(error)),
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="p-0 sm:max-w-md">
        <form
          onSubmit={(event) => {
            event.preventDefault()
            save()
          }}
        >
          <DialogHeader className="px-6 pb-4 pt-6 text-left">
            <DialogTitle>
              {annotation ? t('Edit note') : t('Add note')}
            </DialogTitle>
            <DialogDescription className="mt-2 text-[13px]">
              {t(
                'Notes mark releases, campaigns or incidents on the chart for everyone in the project.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="space-y-4 px-6 py-4">
            <div className="space-y-2">
              <Label htmlFor="annotation-date">{t('Date')}</Label>
              <DateTimePicker
                id="annotation-date"
                value={draftDate}
                onChange={setDraftDate}
                timeZoneMode="preferred"
                className="w-full"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="annotation-content">{t('Note text')}</Label>
              <Textarea
                id="annotation-content"
                value={content}
                onChange={(event) => setContent(event.target.value)}
                maxLength={CONTENT_MAX_LENGTH}
                placeholder={t('Launched the new pricing page')}
                rows={3}
                autoFocus
              />
              <p className="text-end text-[12px] text-muted-foreground tabular-nums">
                {content.length}/{CONTENT_MAX_LENGTH}
              </p>
            </div>
          </div>
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
            >
              {t('Cancel')}
            </Button>
            <Button type="submit" disabled={!canSave}>
              {annotation ? t('Update') : t('Add')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
