import { useEffect, useRef, useState } from 'react'
import { toast } from 'sonner'
import { Plus, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useUpdatePasskeyPolicy } from '@/lib/react-query/hooks/auth'
import {
  MAX_PASSKEY_ORIGINS,
  normalizePasskeyOrigin,
  passkeyOriginError,
  passkeyRpIdError,
  type PasskeyPolicy,
} from '@/lib/passkey-policy'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const PASSKEYS_DOCS_URL = '/docs/products/auth/passkeys'

function originRows(origins: string[]): string[] {
  return origins.length > 0 ? origins : ['']
}

function cleanOrigins(rows: string[]): string[] {
  return rows.map(normalizePasskeyOrigin).filter((origin) => origin !== '')
}

function sameOrigins(a: string[], b: string[]): boolean {
  return a.length === b.length && a.every((origin, i) => origin === b[i])
}

export function PasskeyRelyingPartyCard({
  projectId,
  currentPolicy,
}: {
  projectId: string
  currentPolicy: PasskeyPolicy
}) {
  const t = useT()
  const [rpId, setRpId] = useState(currentPolicy.rpId)
  const [origins, setOrigins] = useState(originRows(currentPolicy.origins))
  const mutation = useUpdatePasskeyPolicy(projectId)
  const isSubmitting = useRef(false)

  useEffect(() => {
    // While a submit is in flight the values on screen win; once it settles the
    // server wins, since it normalises origins.
    if (mutation.isPending || isSubmitting.current) return
    setRpId(currentPolicy.rpId)
    setOrigins(originRows(currentPolicy.origins))
  }, [currentPolicy, mutation.isPending])

  const nextRpId = rpId.trim()
  const nextOrigins = cleanOrigins(origins)
  const rpIdChanged = nextRpId !== currentPolicy.rpId
  const originsChanged = !sameOrigins(
    nextOrigins,
    cleanOrigins(currentPolicy.origins),
  )
  const hasChanges = rpIdChanged || originsChanged

  const rpIdError = passkeyRpIdError(nextRpId)
  const originErrors = origins.map((origin) => {
    const value = normalizePasskeyOrigin(origin)
    return value === '' ? null : passkeyOriginError(value, nextRpId)
  })
  const hasErrors = rpIdError !== null || originErrors.some(Boolean)

  const updateOrigin = (index: number, value: string) => {
    setOrigins((prev) =>
      prev.map((origin, i) => (i === index ? value : origin)),
    )
  }

  const removeOrigin = (index: number) => {
    setOrigins((prev) => originRows(prev.filter((_, i) => i !== index)))
  }

  const addOrigin = () => {
    setOrigins((prev) => [...prev, ''])
  }

  const handleSubmit = () => {
    isSubmitting.current = true
    mutation.mutate(
      {
        ...(rpIdChanged ? { rpId: nextRpId } : {}),
        ...(originsChanged ? { origins: nextOrigins } : {}),
      },
      {
        onSuccess: () => {
          toast.success(t('Updated passkey settings.'))
        },
        onError: (error: Error) => {
          toast.error(error.message || t('Failed to update passkey settings'))
        },
        onSettled: () => {
          isSubmitting.current = false
        },
      },
    )
  }

  return (
    <div
      data-testid="passkey-relying-party-card"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Relying party')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-1">
          {t(
            'Passkeys are bound to the domain of your app. Users can only sign in with a passkey once a relying party ID and at least one origin are set, and the Passkey auth method is enabled.',
          )}{' '}
          <DocsRouteLink className="link-neutral" href={PASSKEYS_DOCS_URL}>
            {t('Learn more')}
          </DocsRouteLink>
          .
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-6">
        <div className="space-y-2 max-w-[420px]">
          <Label htmlFor="passkey-rp-id" className="text-[13px]">
            {t('Relying party ID')}
          </Label>
          <Input
            id="passkey-rp-id"
            value={rpId}
            placeholder="example.com"
            autoComplete="off"
            spellCheck={false}
            onChange={(e) => setRpId(e.target.value)}
            disabled={mutation.isPending}
            aria-invalid={rpIdError !== null}
          />
          <p
            className={cn(
              'text-[12px]',
              rpIdError ? 'text-destructive' : 'text-muted-foreground',
            )}
          >
            {rpIdError
              ? t(rpIdError)
              : t(
                  'The domain of the app where users sign in, not your Appwrite endpoint. Use localhost for local development.',
                )}
          </p>
        </div>
        <div className="space-y-2 max-w-[420px]">
          <Label className="text-[13px]">{t('Allowed origins')}</Label>
          <p className="text-[12px] text-muted-foreground">
            {t(
              'The exact origins your app is served from, on the relying party ID or one of its subdomains.',
            )}
          </p>
          <div className="space-y-2">
            {origins.map((origin, index) => {
              const error = originErrors[index]
              return (
                <div key={index} className="space-y-1">
                  <div className="flex items-center gap-2">
                    <Input
                      value={origin}
                      placeholder="https://example.com"
                      autoComplete="off"
                      spellCheck={false}
                      aria-label={`${t('Origin')} ${index + 1}`}
                      onChange={(e) => updateOrigin(index, e.target.value)}
                      disabled={mutation.isPending}
                      aria-invalid={error !== null}
                    />
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      aria-label={`${t('Remove origin')} ${index + 1}`}
                      onClick={() => removeOrigin(index)}
                      disabled={
                        mutation.isPending ||
                        (origins.length === 1 && origin === '')
                      }
                    >
                      <Trash2 />
                    </Button>
                  </div>
                  {error && (
                    <p className="text-[12px] text-destructive">{t(error)}</p>
                  )}
                </div>
              )
            })}
          </div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 text-[13px]"
            onClick={addOrigin}
            disabled={
              mutation.isPending || origins.length >= MAX_PASSKEY_ORIGINS
            }
          >
            <Plus />
            {t('Add origin')}
          </Button>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || hasErrors || mutation.isPending}
          onClick={handleSubmit}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
