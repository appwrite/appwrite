import { useState } from 'react'
import { Copy } from 'lucide-react'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { useT } from '@/lib/i18n/translate'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
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

const RECIPIENT_ID_MAX_LENGTH = 36

interface CreateTransferLinkProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** The created link; the dialog shows it in place of the form. */
  link: string | null
  isPending: boolean
  /** The resource can't be transferred right now, as the card explains. */
  blocked: boolean
  /** The organization that owns the resource, which can't receive it. */
  ownerId: string
  /** Copy that names the resource, translated by the caller. */
  copy: {
    description: string
    recipientHint: string
    alreadyOwned: string
  }
  onCreate: (params: { expire: string; recipientId?: string }) => void
}

export function CreateTransferLink({
  open,
  onOpenChange,
  link,
  isPending,
  blocked,
  ownerId,
  copy,
  onCreate,
}: CreateTransferLinkProps) {
  const t = useT()
  const [expiration, setExpiration] = useState<string>('1')
  const [recipientId, setRecipientId] = useState('')

  const trimmedRecipientId = recipientId.trim()
  const recipientError =
    trimmedRecipientId === ownerId ? copy.alreadyOwned : null

  const close = (next: boolean) => {
    onOpenChange(next)
    if (!next) {
      setExpiration('1')
      setRecipientId('')
    }
  }

  const create = () => {
    const seconds =
      EXPIRATIONS.find((option) => option.value === expiration)?.seconds ??
      EXPIRATIONS[0].seconds
    onCreate({
      expire: new Date(Date.now() + seconds * 1000).toISOString(),
      recipientId: trimmedRecipientId || undefined,
    })
  }

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Create transfer link')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {link
              ? t(
                  'Share this link with the recipient organization. You can copy it again from the list.',
                )
              : copy.description}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        {link ? (
          <div className="px-6 pb-4 pt-0 space-y-3">
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label
                  htmlFor="transfer-link"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('Transfer link')}
                </Label>
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 text-[12px]"
                  onClick={() => copyToClipboard(t('Transfer link'), link)}
                >
                  <Copy className="h-3 w-3 me-1" />
                  {t('Copy')}
                </Button>
              </div>
              <Textarea
                id="transfer-link"
                readOnly
                value={link}
                className="font-mono text-[12px] min-h-[80px] resize-none break-all"
                onClick={(event) => event.currentTarget.select()}
              />
            </div>
          </div>
        ) : (
          <div className="px-6 pb-4 pt-0 space-y-4">
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
                htmlFor="transfer-recipient"
                className="text-[13px] font-medium text-foreground"
              >
                {t('Recipient organization ID (optional)')}
              </Label>
              <Input
                id="transfer-recipient"
                value={recipientId}
                onChange={(event) => setRecipientId(event.target.value)}
                placeholder={t('Any organization')}
                maxLength={RECIPIENT_ID_MAX_LENGTH}
                aria-invalid={recipientError !== null}
                className="mt-2 h-9"
              />
              {recipientError ? (
                <p className="text-[12px] text-destructive mt-1.5">
                  {recipientError}
                </p>
              ) : (
                <p className="text-[12px] text-muted-foreground mt-1.5">
                  {copy.recipientHint}
                </p>
              )}
            </div>
          </div>
        )}
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          {link ? (
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => close(false)}
            >
              {t('Done')}
            </Button>
          ) : (
            <>
              <Button
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => close(false)}
              >
                {t('Cancel')}
              </Button>
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={isPending || recipientError !== null || blocked}
                onClick={create}
              >
                {t('Create link')}
              </Button>
            </>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
