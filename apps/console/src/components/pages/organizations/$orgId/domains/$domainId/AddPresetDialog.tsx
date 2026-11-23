import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  useCreatePresetRecords,
  usePresetRecords,
} from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

export const DNS_PRESET_OPTIONS = [
  { id: 'google-workspace', label: 'Google Workspace' },
  { id: 'outlook', label: 'Outlook' },
  { id: 'mailgun', label: 'Mailgun' },
  { id: 'zoho', label: 'Zoho' },
  { id: 'protonmail', label: 'ProtonMail' },
  { id: 'icloud', label: 'iCloud' },
] as const

export type DnsPresetId = (typeof DNS_PRESET_OPTIONS)[number]['id']

interface AddPresetDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  domainId: string | null | undefined
  preset: DnsPresetId | null
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
  } = usePresetRecords(open ? domainId : null, open ? preset : null)
  const createPresetMutation = useCreatePresetRecords(domainId)

  const records = presetRecords?.dnsRecords ?? []
  const presetLabel = DNS_PRESET_OPTIONS.find(
    (item) => item.id === preset,
  )?.label
  const isPending = createPresetMutation.isPending

  const handleConfirm = async () => {
    if (!preset || records.length === 0) return

    // The preset endpoint adds only the records the domain does not have yet,
    // so applying a preset again, or over matching records, is not an error.
    // It returns all of the preset's records, so the toast states no count.
    try {
      await createPresetMutation.mutateAsync(preset)
      toast.success(
        `${t('Successfully added')} ${t('DNS records from')} ${presetLabel}`,
      )
      onOpenChange(false)
    } catch (confirmError) {
      toast.error(getErrorMessage(confirmError))
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Add preset records')}</DialogTitle>
          {presetLabel ? (
            <p className="text-[13px] font-medium text-foreground mt-2">
              {presetLabel}
            </p>
          ) : null}
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'These DNS records will be added. Records this domain already has are left unchanged. Nothing is created until you confirm.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 py-4">
          {isLoading ? (
            <p className="text-[13px] text-muted-foreground">
              {t('Loading...')}
            </p>
          ) : isError ? (
            <p className="text-[13px] text-destructive">
              {getErrorMessage(error, t('Failed to load preset records'))}
            </p>
          ) : records.length === 0 ? (
            <p className="text-[13px] text-muted-foreground">
              {t('No DNS records')}
            </p>
          ) : (
            <ul className="max-h-[min(40dvh,360px)] space-y-2 overflow-y-auto">
              {records.map((record, index) => {
                const showPriority =
                  (record.type === 'MX' || record.type === 'SRV') &&
                  typeof record.priority === 'number'
                return (
                  <li
                    key={
                      record.$id ||
                      `${record.type}-${record.name}-${record.value}-${index}`
                    }
                    className="rounded-md border border-border px-3 py-2"
                  >
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                      <span className="text-[11px] font-semibold text-muted-foreground">
                        {record.type}
                      </span>
                      <code className="text-[12px] font-mono text-foreground">
                        {record.name || '@'}
                      </code>
                      {showPriority ? (
                        <span className="text-[12px] text-muted-foreground">
                          {t('Priority')} {record.priority}
                        </span>
                      ) : null}
                    </div>
                    <code className="mt-1 block break-all text-[12px] font-mono text-foreground">
                      {record.value}
                    </code>
                  </li>
                )
              })}
            </ul>
          )}
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            onClick={handleConfirm}
            disabled={
              !preset ||
              isPending ||
              isLoading ||
              isError ||
              records.length === 0
            }
          >
            {t('Add preset')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
