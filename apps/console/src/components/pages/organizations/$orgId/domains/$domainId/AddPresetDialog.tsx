import { useState } from 'react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { usePresetRecords, useCreateDnsRecord } from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

interface AddPresetDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  domainId: string | null | undefined
  onAdd: () => void
}

const PRESETS = [
  { id: 'google-workspace', label: 'Google Workspace' },
  { id: 'outlook', label: 'Outlook' },
  { id: 'mailgun', label: 'Mailgun' },
  { id: 'zoho', label: 'Zoho' },
  { id: 'protonmail', label: 'ProtonMail' },
  { id: 'icloud', label: 'iCloud' },
] as const

type PresetType = (typeof PRESETS)[number]['id']

export function AddPresetDialog({
  open,
  onOpenChange,
  domainId,
  onAdd,
}: AddPresetDialogProps) {
  const t = useT()
  const [selectedPreset, setSelectedPreset] = useState<PresetType | null>(null)

  const { data: presetRecords, isLoading: isLoadingPresets } = usePresetRecords(
    domainId,
    selectedPreset,
  )

  const createRecordMutation = useCreateDnsRecord(domainId)

  const handleAddPreset = async () => {
    if (!selectedPreset || !presetRecords?.dnsRecords) {
      return
    }

    try {
      // Create all records from the preset
      await Promise.all(
        presetRecords.dnsRecords.map((record) =>
          createRecordMutation.mutateAsync({
            type: record.type,
            data: {
              name: record.name || '@',
              value: record.value,
              ttl: record.ttl,
              priority: record.priority,
              weight: record.weight,
              port: record.port,
              comment: record.comment,
            },
          }),
        ),
      )

      toast.success(
        `${t('Successfully added')} ${presetRecords.dnsRecords.length} ${t('DNS records from')} ${PRESETS.find((p) => p.id === selectedPreset)?.label}`,
      )
      onAdd()
      onOpenChange(false)
      setSelectedPreset(null)
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  const handleOpenChange = (newOpen: boolean) => {
    if (!newOpen) {
      setSelectedPreset(null)
    }
    onOpenChange(newOpen)
  }

  const selectedPresetLabel = selectedPreset
    ? PRESETS.find((p) => p.id === selectedPreset)?.label
    : null

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Add preset records')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Select an email provider preset to automatically add the required DNS records.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          <div className="space-y-3">
            {PRESETS.map((preset) => (
              <Button
                key={preset.id}
                variant={selectedPreset === preset.id ? 'default' : 'outline'}
                className="w-full justify-start h-auto py-3 px-4"
                onClick={() => setSelectedPreset(preset.id)}
              >
                <span className="text-[13px] font-medium">{preset.label}</span>
              </Button>
            ))}
          </div>

          {selectedPreset && presetRecords && (
            <div className="mt-4 rounded-lg border border-border bg-muted/30 p-3">
              <p className="text-[12px] text-muted-foreground mb-1">
                {t('This will add')} {presetRecords.dnsRecords?.length || 0}{' '}
                {(presetRecords.dnsRecords?.length || 0) !== 1
                  ? t('DNS records')
                  : t('DNS record')}{' '}
                {t('for')} {selectedPresetLabel}
              </p>
            </div>
          )}
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => handleOpenChange(false)}
            disabled={createRecordMutation.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            onClick={handleAddPreset}
            disabled={
              !selectedPreset ||
              createRecordMutation.isPending ||
              isLoadingPresets
            }
            className="gap-2"
          >
            {t('Add preset')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
