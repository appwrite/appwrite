import { useEffect, useId, useMemo, useState } from 'react'
import { Link } from '@tanstack/react-router'
import { toast } from 'sonner'
import { Globe, Loader2, TriangleAlert } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { useUpdatePasskeyPolicy } from '@/lib/react-query/hooks/auth'
import {
  MAX_PASSKEY_RP_ID_LENGTH,
  passkeyRpIdError,
  platformPasskeyOrigins,
  suggestPasskeyRpIds,
  type PasskeyPlatform,
  type PasskeyPolicy,
} from '@/lib/passkey-policy'
import { cn } from '@/lib/utils'
import { PasskeyOrigins } from './PasskeyOrigins'
import { useT } from '@/lib/i18n/translate'

const OTHER = 'other'

export function EnablePasskeys({
  projectId,
  open,
  onOpenChange,
  policy,
  platforms,
  sharedDomains,
  onEnable,
}: {
  projectId: string
  open: boolean
  onOpenChange: (open: boolean) => void
  policy: PasskeyPolicy
  platforms: PasskeyPlatform[]
  /** Domains shared between projects, such as the Sites domain, never offered whole. */
  sharedDomains: string[]
  /** Turns the Passkey auth method on once the relying party is saved. */
  onEnable: () => void
}) {
  const t = useT()
  const fieldId = useId()
  const mutation = useUpdatePasskeyPolicy(projectId)

  const suggestions = useMemo(() => {
    const domains = suggestPasskeyRpIds(platforms, sharedDomains)
    return policy.rpId && !domains.includes(policy.rpId)
      ? [policy.rpId, ...domains]
      : domains
  }, [platforms, sharedDomains, policy.rpId])

  const initialChoice = policy.rpId || suggestions[0] || OTHER
  const [choice, setChoice] = useState(initialChoice)
  const [custom, setCustom] = useState('')

  useEffect(() => {
    if (open) {
      setChoice(initialChoice)
      setCustom('')
    }
    // Reset only when the dialog opens, not while the user is choosing.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  const rpId = choice === OTHER ? custom.trim() : choice
  const rpIdError = choice === OTHER ? passkeyRpIdError(rpId) : null
  const origins = platformPasskeyOrigins(rpId, platforms)
  const ready = rpIdError === null && origins.length > 0
  const pending = mutation.isPending

  const handleEnable = () => {
    if (rpId === policy.rpId) {
      onEnable()
      onOpenChange(false)
      return
    }
    mutation.mutate(
      { rpId },
      {
        onSuccess: () => {
          onEnable()
          onOpenChange(false)
        },
        onError: (error: Error) => {
          toast.error(error.message || t('Failed to update passkey settings'))
        },
      },
    )
  }

  const optionClassName =
    'flex items-center gap-3 rounded-lg border border-border p-3 cursor-pointer transition-colors hover:bg-muted/30 has-[[data-state=checked]]:border-foreground/40 has-[[data-state=checked]]:bg-muted/30'

  return (
    <Dialog open={open} onOpenChange={(next) => !pending && onOpenChange(next)}>
      <DialogContent
        className="sm:max-w-lg p-0"
        data-testid="enable-passkeys-dialog"
      >
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Enable passkeys')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Passkeys are bound to the domain of your app. Users can sign in with them on that domain and all of its subdomains.',
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="px-6 pb-4 space-y-4">
          <div className="space-y-2">
            <p id={`${fieldId}-domain`} className="text-[13px] font-medium">
              {t('Domain')}
            </p>
            <RadioGroup
              value={choice}
              onValueChange={setChoice}
              aria-labelledby={`${fieldId}-domain`}
              className="space-y-2"
              disabled={pending}
            >
              {suggestions.map((domain) => (
                <label key={domain} className={optionClassName}>
                  <RadioGroupItem value={domain} />
                  <Globe className="h-4 w-4 text-muted-foreground" />
                  <span className="text-[13px] font-mono">{domain}</span>
                </label>
              ))}
              <label className={cn(optionClassName, 'flex-wrap')}>
                <RadioGroupItem value={OTHER} />
                <span className="text-[13px]">
                  {suggestions.length > 0
                    ? t('Another domain')
                    : t('Your app domain')}
                </span>
                {choice === OTHER && (
                  <Input
                    autoFocus
                    value={custom}
                    placeholder="example.com"
                    autoComplete="off"
                    spellCheck={false}
                    maxLength={MAX_PASSKEY_RP_ID_LENGTH}
                    aria-label={t('Domain')}
                    aria-invalid={rpIdError !== null}
                    onChange={(e) => setCustom(e.target.value)}
                    className="basis-full h-8 text-[13px]"
                  />
                )}
              </label>
            </RadioGroup>
            {rpIdError && (
              <p className="text-[12px] text-destructive">{t(rpIdError)}</p>
            )}
          </div>

          <div
            className="rounded-lg border border-border bg-muted/30 px-3 py-2.5 space-y-1.5"
            data-testid="enable-passkeys-origins"
          >
            <p className="text-[12px] font-medium text-foreground">
              {t('Passkeys will work on')}
            </p>
            {origins.length === 0 ? (
              <p className="flex items-start gap-1.5 text-[12px] text-muted-foreground">
                <TriangleAlert className="h-3.5 w-3.5 mt-0.5 shrink-0" />
                <span>
                  {rpId
                    ? t(
                        'No web platform on this domain yet. Add your app as a web platform so users can sign in.',
                      )
                    : t(
                        'Add your app as a web platform to suggest its domain.',
                      )}{' '}
                  <Link
                    to="/projects/$projectId/apps/add"
                    params={{ projectId }}
                    className="link-neutral"
                  >
                    {t('Add platform')}
                  </Link>
                </span>
              </p>
            ) : (
              <PasskeyOrigins origins={origins} />
            )}
          </div>

          {rpId !== '' && rpId !== policy.rpId && (
            <p className="text-[12px] text-muted-foreground">
              {t(
                "The domain can't be changed once users have created passkeys. Pick the broadest domain you control.",
              )}
            </p>
          )}
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={pending}
          >
            {t('Cancel')}
          </Button>
          <Button onClick={handleEnable} disabled={!ready || pending}>
            {pending && <Loader2 className="animate-spin" />}
            {t('Enable')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
