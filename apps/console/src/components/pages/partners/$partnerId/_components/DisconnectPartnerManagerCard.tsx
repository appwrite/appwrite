import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useT } from '@/lib/i18n/translate'
import {
  deletePartnerManager,
  partnerManagerDeleteErrorMessage,
} from '@/lib/react-query/hooks'

export function DisconnectPartnerManagerCard({
  partnerId,
  organizationId,
  organizationName,
  onDisconnected,
}: {
  partnerId: string
  organizationId: string
  organizationName: string
  onDisconnected?: () => void
}) {
  const t = useT()
  const queryClient = useQueryClient()
  const [confirmOpen, setConfirmOpen] = useState(false)

  const disconnectMutation = useMutation({
    mutationFn: () => deletePartnerManager(organizationId),
    onSuccess: async () => {
      toast.success(t('Partner disconnected'))
      setConfirmOpen(false)
      await queryClient.refetchQueries({
        queryKey: ['partners', partnerId, 'clients'],
      })
      await queryClient.refetchQueries({
        queryKey: ['organization', organizationId],
      })
      await queryClient.refetchQueries({
        queryKey: ['organizations', 'console'],
      })
      onDisconnected?.()
    },
    onError: (error) => {
      toast.error(t(partnerManagerDeleteErrorMessage(error)))
    },
  })

  return (
    <div className="rounded-xl border border-destructive/20 bg-destructive/5 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Disconnect partner')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Stop managing this client organization. The client keeps their projects and billing. You will lose partner access.',
          )}
        </p>
      </div>
      <div className="px-6 py-4 border-t border-destructive/20">
        <Button variant="destructive" size="sm" className="h-9 text-[13px]" onClick={() => setConfirmOpen(true)}>
          {t('Disconnect')}
        </Button>
      </div>
      <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>{t('Disconnect partner')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Disconnect')} "{organizationName}"?{' '}
              {t(
                'This organization will no longer be managed by this partner.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setConfirmOpen(false)}
              disabled={disconnectMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              disabled={disconnectMutation.isPending}
              onClick={() => disconnectMutation.mutate()}
            >
              {t('Disconnect')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
