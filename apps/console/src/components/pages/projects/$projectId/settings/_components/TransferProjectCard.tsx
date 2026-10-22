import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Copy, Link2, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { buildConsoleUrl, copyToClipboard } from '@/lib/utils/context-menu'
import { useT } from '@/lib/i18n/translate'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

const EXPIRATIONS = [
  { value: '1', label: '24 hours', seconds: 24 * 60 * 60 },
  { value: '7', label: '7 days', seconds: 7 * 24 * 60 * 60 },
  { value: '30', label: '30 days', seconds: 30 * 24 * 60 * 60 },
] as const

export function buildTransferUrl(transfer: Models.Transfer): string {
  const params = new URLSearchParams({
    transferId: transfer.$id,
    secret: transfer.secret,
  })
  return buildConsoleUrl(`/transfer?${params.toString()}`)
}

interface TransferProjectCardProps {
  projectId: string
  teamId: string
}

export function TransferProjectCard({
  projectId,
  teamId,
}: TransferProjectCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [dialogOpen, setDialogOpen] = useState(false)
  const [expiration, setExpiration] = useState<string>('1')
  const [recipientId, setRecipientId] = useState('')
  const [link, setLink] = useState<string | null>(null)

  const organization = sdk.forConsole.organization(teamId)
  const queryKey = ['projectTransfers', teamId, projectId]

  const transfers = useQuery({
    queryKey,
    queryFn: () => organization.listProjectTransfers({ projectId }),
  })

  const createMutation = useMutation({
    mutationFn: async () => {
      const seconds =
        EXPIRATIONS.find((option) => option.value === expiration)?.seconds ??
        EXPIRATIONS[0].seconds
      return organization.createProjectTransfer({
        projectId,
        recipientId: recipientId.trim() || undefined,
        expire: new Date(Date.now() + seconds * 1000).toISOString(),
      })
    },
    onSuccess: async (transfer) => {
      setLink(buildTransferUrl(transfer))
      await queryClient.invalidateQueries({ queryKey })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error, t('Failed to create transfer link')))
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (transferId: string) =>
      organization.deleteProjectTransfer({ transferId }),
    onSuccess: async () => {
      toast.success(t('Transfer link has been deleted'))
      await queryClient.invalidateQueries({ queryKey })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error, t('Failed to delete transfer link')))
    },
  })

  const closeDialog = (open: boolean) => {
    setDialogOpen(open)
    if (!open) {
      setLink(null)
      setExpiration('1')
      setRecipientId('')
    }
  }

  const activeTransfers = transfers.data?.transfers ?? []

  return (
    <>
      <div
        data-card-id="transfer-project"
        className="rounded-xl border border-border bg-card/50 overflow-hidden"
      >
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Transfer project')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              'Create a link to move this project to another organization. Whoever opens the link can claim the project for an organization they manage, even if they are not a member of yours. Each link works once.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-2">
            {t('Active links')}
          </p>
          {transfers.isError ? (
            <p className="text-[13px] text-muted-foreground">
              {t('Failed to load transfer links')}
            </p>
          ) : activeTransfers.length === 0 ? (
            <p className="text-[13px] text-muted-foreground">
              {transfers.isLoading
                ? t('Loading...')
                : t('No active transfer links')}
            </p>
          ) : (
            <ul className="divide-y divide-border rounded-lg border border-border">
              {activeTransfers.map((transfer) => (
                <li
                  key={transfer.$id}
                  className="flex items-center justify-between gap-3 px-3 py-2.5"
                >
                  <div className="flex min-w-0 items-center gap-2.5">
                    <Link2 className="h-4 w-4 shrink-0 text-muted-foreground" />
                    <div className="min-w-0">
                      <p className="text-[13px] text-foreground">
                        {transfer.recipientId
                          ? `${t('Only organization')} ${transfer.recipientId}`
                          : t('Any organization')}
                      </p>
                      <p className="text-[12px] text-muted-foreground">
                        {t('Expires')} <DateTooltip date={transfer.expire} />
                      </p>
                    </div>
                  </div>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 text-[13px]"
                    disabled={deleteMutation.isPending}
                    onClick={() => deleteMutation.mutate(transfer.$id)}
                  >
                    <Trash2 className="h-3.5 w-3.5 mr-1.5" />
                    {t('Delete')}
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => setDialogOpen(true)}
          >
            {t('Create transfer link')}
          </Button>
        </div>
      </div>

      <Dialog open={dialogOpen} onOpenChange={closeDialog}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Create transfer link')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {link
                ? t(
                    'Share this link with the person who should receive the project.',
                  )
                : t(
                    'Choose how long the link works and, optionally, which organization can claim the project.',
                  )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          {link ? (
            <div className="px-6 py-4 space-y-3">
              <div className="flex gap-2">
                <Input
                  readOnly
                  value={link}
                  className="h-9 font-mono text-[12px]"
                  onFocus={(event) => event.currentTarget.select()}
                />
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px] shrink-0"
                  onClick={() => copyToClipboard(t('Transfer link'), link)}
                >
                  <Copy className="h-3.5 w-3.5 mr-1.5" />
                  {t('Copy')}
                </Button>
              </div>
              <Alert>
                <AlertTriangle className="h-4 w-4" />
                <AlertDescription className="text-[13px]">
                  {t(
                    'Copy the link now. It is only shown once, and anyone with it can claim the project.',
                  )}
                </AlertDescription>
              </Alert>
            </div>
          ) : (
            <div className="px-6 py-4 space-y-4">
              <div>
                <Label
                  htmlFor="transfer-expiration"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('Expires in')}
                </Label>
                <Select value={expiration} onValueChange={setExpiration}>
                  <SelectTrigger id="transfer-expiration" className="mt-2 h-9">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {EXPIRATIONS.map((option) => (
                      <SelectItem key={option.value} value={option.value}>
                        {t(option.label)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label
                  htmlFor="transfer-team"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('Organization ID (optional)')}
                </Label>
                <Input
                  id="transfer-team"
                  value={recipientId}
                  onChange={(event) => setRecipientId(event.target.value)}
                  placeholder={t('Any organization')}
                  className="mt-2 h-9"
                />
                <p className="text-[12px] text-muted-foreground mt-1.5">
                  {t(
                    'Only this organization will be able to claim the project.',
                  )}
                </p>
              </div>
            </div>
          )}
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            {link ? (
              <Button
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => closeDialog(false)}
              >
                {t('Done')}
              </Button>
            ) : (
              <>
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => closeDialog(false)}
                >
                  {t('Cancel')}
                </Button>
                <Button
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={createMutation.isPending}
                  onClick={() => createMutation.mutate()}
                >
                  {t('Create link')}
                </Button>
              </>
            )}
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
