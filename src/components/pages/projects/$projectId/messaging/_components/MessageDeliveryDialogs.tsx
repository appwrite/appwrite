import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { DateTimePicker } from '@/components/global/shared/DateTimePicker'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'

function estimateRecipientTargets(
  message: Models.Message,
  topics: Models.Topic[],
): number {
  let total = message.targets?.length ?? 0
  const topicMap = new Map(topics.map((t) => [t.$id, t]))
  for (const topicId of message.topics || []) {
    const topic = topicMap.get(topicId)
    if (!topic) continue
    if (message.providerType === 'push') {
      total += topic.pushTotal || 0
    } else if (message.providerType === 'email') {
      total += topic.emailTotal || 0
    } else if (message.providerType === 'sms') {
      total += topic.smsTotal || 0
    }
  }
  total += message.users?.length ?? 0
  return total
}

export function MessageSendDialog({
  open,
  onOpenChange,
  projectId,
  message,
  topics,
  onSuccess,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  message: Models.Message
  topics: Models.Topic[]
  onSuccess: () => void
}) {
  const t = useT()
  const queryClient = useQueryClient()
  const totalTargets = useMemo(
    () => estimateRecipientTargets(message, topics),
    [message, topics],
  )

  const sendMutation = useMutation({
    mutationFn: async () => {
      const projectSdk = sdk.forProject(projectId)
      if (message.providerType === 'email') {
        return projectSdk.messaging.updateEmail({
          messageId: message.$id,
          draft: false,
        })
      }
      if (message.providerType === 'sms') {
        return projectSdk.messaging.updateSMS({
          messageId: message.$id,
          draft: false,
        })
      }
      return projectSdk.messaging.updatePush({
        messageId: message.$id,
        draft: false,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['message', 'project', projectId, message.$id],
      })
      await queryClient.refetchQueries({
        queryKey: ['messages', 'project', projectId],
      })
      toast.success(
        `${t('The message has been sent to an estimated')} ${totalTargets} ${t('targets.')}`,
      )
      onOpenChange(false)
      onSuccess()
    },
    onError: (e: Error) => {
      toast.error(getErrorMessage(e) || t('Failed to send message'))
    },
  })

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="sm:max-w-md p-0"
>
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Confirm sending message')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Please confirm you want to send this message now. It will be delivered to an estimated',
            )}{' '}
            <span className="font-medium text-foreground">{totalTargets}</span>{' '}
            {t('targets.')}
            <span className="mt-3 block font-medium text-foreground">
              {t('This action cannot be undone.')}
            </span>
          </DialogDescription>
        </DialogHeader>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={sendMutation.isPending}
>
            {t('Cancel')}
          </Button>
          <Button
            onClick={() => sendMutation.mutate()}
            disabled={sendMutation.isPending}
>
            {t('Send')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

export function MessageScheduleDialog({
  open,
  onOpenChange,
  projectId,
  message,
  topics,
  onSuccess,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  message: Models.Message
  topics: Models.Topic[]
  onSuccess: () => void
}) {
  const t = useT()
  const queryClient = useQueryClient()
  const totalTargets = useMemo(
    () => estimateRecipientTargets(message, topics),
    [message, topics],
  )

  const buildDefaultIso = (iso?: string) => {
    if (iso) {
      const d = new Date(iso)
      if (!Number.isNaN(d.getTime())) {
        return d.toISOString()
      }
    }
    const now = new Date()
    now.setMinutes(now.getMinutes() + 5)
    return now.toISOString()
  }

  const [localValue, setLocalValue] = useState(() =>
    buildDefaultIso(message.scheduledAt),
  )

  useEffect(() => {
    if (open) {
      setLocalValue(buildDefaultIso(message.scheduledAt))
    }
  }, [open, message.scheduledAt])

  const scheduleMutation = useMutation({
    mutationFn: async (scheduledAt: string) => {
      const projectSdk = sdk.forProject(projectId)
      if (message.providerType === 'email') {
        return projectSdk.messaging.updateEmail({
          messageId: message.$id,
          scheduledAt,
        })
      }
      if (message.providerType === 'sms') {
        return projectSdk.messaging.updateSMS({
          messageId: message.$id,
          scheduledAt,
        })
      }
      return projectSdk.messaging.updatePush({
        messageId: message.$id,
        draft: false,
        scheduledAt,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['message', 'project', projectId, message.$id],
      })
      await queryClient.refetchQueries({
        queryKey: ['messages', 'project', projectId],
      })
      toast.success(
        `${t('The message has been scheduled and will be sent to an estimated')} ${totalTargets} ${t('targets.')}`,
      )
      onOpenChange(false)
      onSuccess()
    },
    onError: (e: Error) => {
      toast.error(getErrorMessage(e) || t('Failed to schedule message'))
    },
  })

  const handleSubmit = () => {
    const dt = new Date(localValue)
    if (Number.isNaN(dt.getTime())) {
      toast.error(t('Enter a valid date and time'))
      return
    }
    if (dt.getTime() <= Date.now()) {
      toast.error(t('Schedule a time in the future'))
      return
    }
    scheduleMutation.mutate(dt.toISOString())
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="sm:max-w-md p-0"
>
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Schedule message')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Choose when this message should be delivered. Time uses your local timezone.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-0 space-y-3">
          <div className="space-y-2">
            <Label htmlFor="schedule-local" className="text-[13px]">
              {t('Send at')}
            </Label>
            <DateTimePicker
              id="schedule-local"
              value={localValue || null}
              onChange={(value) => setLocalValue(value ?? '')}
              clearable={false}
              className="h-9"
            />
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={scheduleMutation.isPending}
>
            {t('Cancel')}
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={scheduleMutation.isPending}
>
            {t('Schedule')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

export function MessageCancelScheduleDialog({
  open,
  onOpenChange,
  projectId,
  message,
  onSuccess,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  message: Models.Message
  onSuccess: () => void
}) {
  const t = useT()
  const queryClient = useQueryClient()

  const cancelMutation = useMutation({
    mutationFn: async () => {
      const projectSdk = sdk.forProject(projectId)
      if (message.providerType === 'email') {
        return projectSdk.messaging.updateEmail({
          messageId: message.$id,
          draft: true,
        })
      }
      if (message.providerType === 'sms') {
        return projectSdk.messaging.updateSMS({
          messageId: message.$id,
          draft: true,
        })
      }
      return projectSdk.messaging.updatePush({
        messageId: message.$id,
        draft: true,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['message', 'project', projectId, message.$id],
      })
      await queryClient.refetchQueries({
        queryKey: ['messages', 'project', projectId],
      })
      toast.success(t('The scheduling has been cancelled.'))
      onOpenChange(false)
      onSuccess()
    },
    onError: (e: Error) => {
      toast.error(getErrorMessage(e) || t('Failed to cancel scheduling'))
    },
  })

  const titleLabel =
    (message.data as { title?: string; subject?: string; content?: string })
      ?.title ??
    (message.data as { subject?: string })?.subject ??
    (message.data as { content?: string })?.content ??
    t('Message')

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="sm:max-w-md p-0"
>
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Cancel scheduling')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Are you sure you want to cancel the scheduling of')}{' '}
            <span className="font-medium text-foreground">{titleLabel}</span>?{' '}
            {t('The message returns to draft.')}
          </DialogDescription>
        </DialogHeader>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={cancelMutation.isPending}
>
            {t('Keep scheduled')}
          </Button>
          <Button
            variant="secondary"
            onClick={() => cancelMutation.mutate()}
            disabled={cancelMutation.isPending}
>
            {t('Cancel scheduling')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
