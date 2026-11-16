import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  usePresetRecords,
  useCreatePresetRecords,
} from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

const PRESETS = [
  { id: 'google-workspace', label: 'Google Workspace' },
  { id: 'outlook', label: 'Outlook' },
  { id: 'mailgun', label: 'Mailgun' },
  { id: 'zoho', label: 'Zoho' },
  { id: 'protonmail', label: 'ProtonMail' },
  { id: 'icloud', label: 'iCloud' },
] as const

export type DnsPreset = (typeof PRESETS)[number]['id']

interface AddPresetDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  domainId: string | null | undefined
  preset: DnsPreset | null
}

export function AddPresetDialog({
  open,
  onOpenChange,
  domainId,
  preset,
}: AddPresetDialogProps) {
  const t = useT()
  const {
    data: presetRecords,
    isLoading,
    isError,
    error,
  } = usePresetRecords(domainId, open ? preset : null)
  const createPresetMutation = useCreatePresetRecords(domainId)

  const presetLabel = preset
    ? PRESETS.find((item) => item.id === preset)?.label
    : null
  const records = presetRecords?.dnsRecords ?? []
  const recordCount = records.length

  const handleAddPreset = async () => {
    if (!preset) return

    // The preset endpoint adds only the records the domain does not have yet,
    // so applying a preset again, or over matching records, is not an error.
    // It returns all of the preset's records, so the toast states no count.
    try {
      await createPresetMutation.mutateAsync(preset)
      toast.success(
        `${t('Successfully added')} ${t('DNS records from')} ${presetLabel}`,
      )
      onOpenChange(false)
    } catch (submitError) {
      toast.error(getErrorMessage(submitError))
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Add preset records')}</DialogTitle>
          <DialogDescription
            className={`text-[13px] mt-2${isError ? ' text-destructive' : ''}`}
          >
            {isError
              ? getErrorMessage(error)
              : isLoading || !presetRecords
                ? t('Loading...')
                : `${t('This will add')} ${recordCount} ${
                    recordCount === 1 ? t('DNS record') : t('DNS records')
                  } ${t('for')} ${presetLabel}`}
          </DialogDescription>
        </DialogHeader>
        {records.length > 0 ? (
          <>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <ul className="max-h-72 overflow-y-auto rounded-lg border border-border divide-y divide-border">
                {records.map((record, index) => {
                  const showPriority =
                    typeof record.priority === 'number' &&
                    (record.type === 'MX' || record.type === 'SRV')

                  return (
                    <li
                      key={`${record.type}-${record.name}-${record.value}-${index}`}
                      className="px-3 py-2 text-[12px]"
                    >
                      <div className="flex items-center gap-2">
                        <span className="font-medium text-muted-foreground">
                          {record.type}
                        </span>
                        <code className="font-mono text-foreground">
                          {record.name || '@'}
                        </code>
                        {showPriority ? (
                          <span className="ms-auto text-muted-foreground">
                            {t('Priority')} {record.priority}
                          </span>
                        ) : null}
                      </div>
                      <p className="mt-0.5 break-all text-foreground">
                        {record.value}
                      </p>
                    </li>
                  )
                })}
              </ul>
            </div>
          </>
        ) : null}

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={createPresetMutation.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            onClick={handleAddPreset}
            disabled={
              !preset ||
              createPresetMutation.isPending ||
              isLoading ||
              isError ||
              recordCount === 0
            }
          >
            {t('Add preset')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
