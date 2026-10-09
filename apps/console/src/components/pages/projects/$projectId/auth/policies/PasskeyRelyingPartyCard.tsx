import { useEffect, useId, useRef, useState } from 'react'
import { Link } from '@tanstack/react-router'
import { toast } from 'sonner'
import { Plus, Trash2 } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useUpdatePasskeyPolicy } from '@/lib/react-query/hooks/auth'
import { useConsoleVariables, usePlatforms } from '@/lib/react-query/hooks'
import {
  MAX_PASSKEY_ORIGINS,
  MAX_PASSKEY_RP_ID_LENGTH,
  hasLocalhostPlatform,
  isPasskeyReady,
  normalizePasskeyOrigin,
  passkeyOriginError,
  passkeyRpIdError,
  platformPasskeyOrigins,
  suggestPasskeyRpIds,
  type PasskeyPolicy,
} from '@/lib/passkey-policy'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const PASSKEYS_DOCS_URL = '/docs/products/auth/passkeys'

type OriginRow = { id: number; value: string }

let nextRowId = 0

function originRows(origins: string[]): OriginRow[] {
  return origins.map((value) => ({ id: nextRowId++, value }))
}

function cleanOrigins(values: string[]): string[] {
  return values.map(normalizePasskeyOrigin).filter((origin) => origin !== '')
}

function sameOrigins(a: string[], b: string[]): boolean {
  return a.length === b.length && a.every((origin, i) => origin === b[i])
}

export function PasskeyRelyingPartyCard({
  projectId,
  currentPolicy,
  methodEnabled,
}: {
  projectId: string
  currentPolicy: PasskeyPolicy
  /** Whether the Passkey auth method is on, from the project's auth methods. */
  methodEnabled: boolean
}) {
  const t = useT()
  const fieldId = useId()
  const [rpId, setRpId] = useState(currentPolicy.rpId)
  const [origins, setOrigins] = useState(() =>
    originRows(currentPolicy.origins),
  )
  const mutation = useUpdatePasskeyPolicy(projectId)
  const syncedPolicy = useRef(currentPolicy)
  const { platforms } = usePlatforms(projectId)
  const { sitesDomain, functionsDomain } = useConsoleVariables()
  const localhost = hasLocalhostPlatform(platforms)

  useEffect(() => {
    // Adopt the stored policy only when it changes, so a refused save keeps the
    // edits on screen while a successful one picks up the server's normalised
    // origins once the refetch lands (the mutation stays pending until then).
    if (mutation.isPending || syncedPolicy.current === currentPolicy) return
    syncedPolicy.current = currentPolicy
    setRpId(currentPolicy.rpId)
    setOrigins(originRows(currentPolicy.origins))
  }, [currentPolicy, mutation.isPending])

  const nextRpId = rpId.trim()
  const nextOrigins = cleanOrigins(origins.map((row) => row.value))
  const rpIdChanged = nextRpId !== currentPolicy.rpId
  const originsChanged = !sameOrigins(
    nextOrigins,
    cleanOrigins(currentPolicy.origins),
  )
  const hasChanges = rpIdChanged || originsChanged

  const rpIdError = passkeyRpIdError(nextRpId)
  const originErrors = origins.map((row, index) => {
    const value = normalizePasskeyOrigin(row.value)
    if (value === '') return null
    const isDuplicate = origins
      .slice(0, index)
      .some((earlier) => normalizePasskeyOrigin(earlier.value) === value)
    if (isDuplicate) return 'This origin is already in the list.'
    return passkeyOriginError(value, nextRpId)
  })
  const hasErrors = rpIdError !== null || originErrors.some(Boolean)
  const customOrigins = origins.length > 0
  const platformOrigins = platformPasskeyOrigins(nextRpId, platforms).filter(
    (origin) => origin !== 'http://localhost',
  )
  const suggestions =
    nextRpId === ''
      ? suggestPasskeyRpIds(
          platforms,
          [sitesDomain, functionsDomain].filter((domain): domain is string =>
            Boolean(domain),
          ),
        )
      : []
  // Passkeys fail closed, so clearing the relying party stops sign-in for everyone.
  const breaksSignIn =
    methodEnabled &&
    !isPasskeyReady({ rpId: nextRpId, origins: nextOrigins }, platforms)

  const updateOrigin = (id: number, value: string) => {
    setOrigins((prev) =>
      prev.map((row) => (row.id === id ? { ...row, value } : row)),
    )
  }

  const removeOrigin = (id: number) => {
    setOrigins((prev) => prev.filter((row) => row.id !== id))
  }

  const addOrigin = () => {
    setOrigins((prev) => [...prev, ...originRows([''])])
  }

  const handleSubmit = () => {
    mutation.mutate(
      {
        ...(rpIdChanged ? { rpId: nextRpId } : {}),
        ...(originsChanged ? { origins: nextOrigins } : {}),
      },
      {
        onSuccess: () => {
          toast.success(t('Updated passkey settings'))
        },
        onError: (error: Error) => {
          toast.error(error.message || t('Failed to update passkey settings'))
        },
      },
    )
  }

  const rpIdHelpId = `${fieldId}-rp-id-help`
  const originsLabelId = `${fieldId}-origins-label`
  const originsHelpId = `${fieldId}-origins-help`

  return (
    <div
      data-testid="passkey-relying-party-card"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Relying party')}
          </h3>
          <Link
            to="/projects/$projectId/auth/settings"
            params={{ projectId }}
            data-testid="passkey-method-status"
            className="inline-flex items-center gap-1.5 text-[12px] text-muted-foreground hover:text-foreground"
          >
            {t('Passkey auth method')}
            <Badge
              variant={methodEnabled ? 'success' : 'info'}
              className="text-[10px] shrink-0"
            >
              {methodEnabled ? t('Enabled') : t('Disabled')}
            </Badge>
          </Link>
        </div>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Passkeys are bound to the domain of your app, and work on your web platforms on that domain or its subdomains. A localhost web platform works without a domain for local development.',
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
            maxLength={MAX_PASSKEY_RP_ID_LENGTH}
            onChange={(e) => setRpId(e.target.value)}
            disabled={mutation.isPending}
            aria-invalid={rpIdError !== null}
            aria-describedby={rpIdHelpId}
          />
          <p
            id={rpIdHelpId}
            className={cn(
              'text-[12px]',
              rpIdError ? 'text-destructive' : 'text-muted-foreground',
            )}
          >
            {rpIdError
              ? t(rpIdError)
              : t(
                  'The domain of the app where users sign in, not your Appwrite endpoint. It cannot change once users have passkeys.',
                )}
          </p>
          {suggestions.length > 0 && (
            <div
              className="flex flex-wrap items-center gap-1.5"
              data-testid="passkey-rp-id-suggestions"
            >
              <span className="text-[12px] text-muted-foreground">
                {t('From your platforms:')}
              </span>
              {suggestions.map((domain) => (
                <Button
                  key={domain}
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-6 px-2 text-[12px] font-mono"
                  onClick={() => setRpId(domain)}
                  disabled={mutation.isPending}
                >
                  {domain}
                </Button>
              ))}
            </div>
          )}
        </div>
        <div className="space-y-2 max-w-[420px]">
          <p className="text-[13px] font-medium">{t('Where passkeys work')}</p>
          {customOrigins ? (
            <p className="text-[12px] text-muted-foreground">
              {t('The custom origins below replace your web platforms.')}
            </p>
          ) : platformOrigins.length > 0 || localhost ? (
            <ul
              className="space-y-1 text-[12px] font-mono text-muted-foreground"
              data-testid="passkey-platform-origins"
            >
              {platformOrigins.map((origin) => (
                <li key={origin}>{origin}</li>
              ))}
              {localhost && (
                <li>
                  http://localhost{' '}
                  <span className="font-sans">{t('(any port)')}</span>
                </li>
              )}
            </ul>
          ) : (
            <p className="text-[12px] text-muted-foreground">
              {nextRpId
                ? t('No web platform on this domain yet.')
                : t('Set a relying party ID to see where passkeys work.')}
            </p>
          )}
          <Link
            to="/projects/$projectId/apps"
            params={{ projectId }}
            className="link-neutral text-[12px]"
          >
            {t('Manage platforms')}
          </Link>
        </div>
        <div
          role="group"
          aria-labelledby={originsLabelId}
          aria-describedby={originsHelpId}
          className="space-y-2 max-w-[420px]"
        >
          <Label id={originsLabelId} className="text-[13px]">
            {t('Custom origins')}
          </Label>
          <p id={originsHelpId} className="text-[12px] text-muted-foreground">
            {t(
              'Optional. List exact origins to use instead of your web platforms, on the relying party ID or one of its subdomains.',
            )}
          </p>
          <div className="space-y-2">
            {origins.map((row, index) => {
              const error = originErrors[index]
              const errorId = `${fieldId}-origin-${row.id}-error`
              return (
                <div key={row.id} className="space-y-1">
                  <div className="flex items-center gap-2">
                    <Input
                      value={row.value}
                      placeholder="https://example.com"
                      autoComplete="off"
                      spellCheck={false}
                      aria-label={`${t('Origin URL')} ${index + 1}`}
                      onChange={(e) => updateOrigin(row.id, e.target.value)}
                      disabled={mutation.isPending}
                      aria-invalid={error !== null}
                      aria-describedby={error ? errorId : undefined}
                    />
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      aria-label={`${t('Remove origin')} ${index + 1}`}
                      onClick={() => removeOrigin(row.id)}
                      disabled={mutation.isPending}
                    >
                      <Trash2 />
                    </Button>
                  </div>
                  {error && (
                    <p id={errorId} className="text-[12px] text-destructive">
                      {t(error)}
                    </p>
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
        {breaksSignIn && (
          <p
            data-testid="passkey-policy-warning"
            className="max-w-[420px] rounded-lg border border-border bg-muted/30 px-3 py-2 text-[12px] text-muted-foreground"
          >
            {t(
              'The Passkey auth method is enabled. Without a relying party ID that has a web platform or custom origin, users cannot sign in with a passkey.',
            )}
          </p>
        )}
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
