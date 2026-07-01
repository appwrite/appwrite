'use client'

import { useMemo, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Check, ShieldCheck, TriangleAlert } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { sdk } from '@/lib/appwrite/sdk'
import { describeConsentScopes } from '@/lib/oauth2/scopes'

export type OAuth2Flow = 'authorization' | 'device'

/** A single parsed authorization detail entry (RFC 9396). */
interface AuthorizationDetail {
  type: string
  [key: string]: unknown
}

/** Safely parse a grant's `authorizationDetails` JSON string into entries. */
function parseAuthorizationDetails(raw: string): AuthorizationDetail[] {
  if (!raw) return []
  try {
    const parsed = JSON.parse(raw)
    return Array.isArray(parsed) ? (parsed as AuthorizationDetail[]) : []
  } catch {
    return []
  }
}

interface OAuth2ConsentCardProps {
  grant: Models.Oauth2Grant
  app: Models.App
  /** Email/name of the signed-in account, shown so the user knows who they are. */
  accountLabel?: string
  /** 'authorization' redirects back to the client; 'device' shows a done state. */
  flow: OAuth2Flow
  /** Render a terminal state after the device flow completes. */
  onDeviceDone?: (outcome: 'approved' | 'denied') => void
}

function hostnameOf(uri: string): string | null {
  try {
    return new URL(uri).hostname
  } catch {
    return null
  }
}

function AppLogo({ app }: { app: Models.App }) {
  const initial = (app.name || '?').charAt(0).toUpperCase()
  if (app.logoUri) {
    return (
      <img
        src={app.logoUri}
        alt={app.name}
        className="size-14 rounded-xl border object-cover"
      />
    )
  }
  return (
    <div className="bg-muted text-muted-foreground flex size-14 items-center justify-center rounded-xl border text-xl font-semibold">
      {initial}
    </div>
  )
}

export function OAuth2ConsentCard({
  grant,
  app,
  accountLabel,
  flow,
  onDeviceDone,
}: OAuth2ConsentCardProps) {
  const [error, setError] = useState<string | null>(null)

  const scopes = useMemo(
    () => describeConsentScopes(grant.scopes ?? []),
    [grant.scopes],
  )
  const details = useMemo(
    () => parseAuthorizationDetails(grant.authorizationDetails ?? ''),
    [grant.authorizationDetails],
  )
  const redirectHost = hostnameOf(grant.redirectUri)
  const clientLink = app.clientUri || app.privacyPolicyUrl || app.termsUrl

  const approveMutation = useMutation({
    mutationFn: () => sdk.forConsole.oauth2.approve({ grantId: grant.$id }),
    onSuccess: (result) => {
      if (flow === 'device' || !result.redirectUrl) {
        onDeviceDone?.('approved')
        return
      }
      window.location.assign(result.redirectUrl)
    },
    onError: (e: unknown) => {
      const message = getErrorMessage(e, 'Failed to authorize the application')
      setError(message)
      toast.error(message)
    },
  })

  const rejectMutation = useMutation({
    mutationFn: () => sdk.forConsole.oauth2.reject({ grantId: grant.$id }),
    onSuccess: (result) => {
      if (flow === 'device' || !result.redirectUrl) {
        onDeviceDone?.('denied')
        return
      }
      window.location.assign(result.redirectUrl)
    },
    onError: (e: unknown) => {
      const message = getErrorMessage(e, 'Failed to cancel the request')
      setError(message)
      toast.error(message)
    },
  })

  const isBusy = approveMutation.isPending || rejectMutation.isPending

  return (
    <Card className="overflow-hidden p-6 md:p-8">
      <div className="space-y-6">
        <div className="flex flex-col items-center gap-4 text-center">
          <AppLogo app={app} />
          <div className="space-y-1">
            <h1 className="text-xl font-semibold tracking-tight">
              Authorize {app.name}
            </h1>
            <p className="text-muted-foreground text-sm">
              {app.tagline ||
                `${app.name} wants to access your Appwrite account.`}
            </p>
          </div>
        </div>

        <div className="space-y-3">
          <p className="text-muted-foreground text-xs font-medium uppercase tracking-wide">
            This will allow {app.name} to
          </p>
          <ul className="space-y-3">
            {scopes.map((scope) => {
              const Icon = scope.icon
              return (
                <li key={scope.id} className="flex items-start gap-3">
                  <span className="bg-muted text-muted-foreground mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg">
                    <Icon className="size-4" />
                  </span>
                  <span className="space-y-0.5">
                    <span className="block text-sm font-medium">
                      {scope.title}
                    </span>
                    <span className="text-muted-foreground block text-xs">
                      {scope.description}
                    </span>
                  </span>
                </li>
              )
            })}
          </ul>
        </div>

        {details.length > 0 && (
          <div className="space-y-2">
            <p className="text-muted-foreground text-xs font-medium uppercase tracking-wide">
              Requested resources
            </p>
            <ul className="space-y-1.5">
              {details.map((detail, index) => (
                <li
                  key={`${detail.type}-${index}`}
                  className="bg-muted/50 flex items-center gap-2 rounded-lg px-3 py-2 text-sm"
                >
                  <ShieldCheck className="text-muted-foreground size-4 shrink-0" />
                  <span className="font-medium">{detail.type}</span>
                </li>
              ))}
            </ul>
          </div>
        )}

        {error && (
          <div className="border-destructive/20 bg-destructive/10 flex items-start gap-2 rounded-md border p-3">
            <TriangleAlert className="text-destructive mt-0.5 size-4 shrink-0" />
            <p className="text-destructive text-sm">{error}</p>
          </div>
        )}

        <div className="space-y-3">
          <div className="flex flex-col gap-2">
            <Button
              variant="brandCta"
              className="w-full"
              disabled={isBusy}
              onClick={() => {
                setError(null)
                approveMutation.mutate()
              }}
            >
              <Check className="me-1.5 size-4" />
              {approveMutation.isPending ? 'Authorizing…' : 'Authorize'}
            </Button>
            <Button
              variant="outline"
              className="w-full"
              disabled={isBusy}
              onClick={() => {
                setError(null)
                rejectMutation.mutate()
              }}
            >
              {rejectMutation.isPending ? 'Cancelling…' : 'Cancel'}
            </Button>
          </div>

          <p className="text-muted-foreground text-center text-xs">
            {accountLabel ? (
              <>
                Signed in as <span className="font-medium">{accountLabel}</span>
                .{' '}
              </>
            ) : null}
            {flow === 'authorization' && redirectHost ? (
              <>You'll be redirected to {redirectHost}.</>
            ) : null}
            {flow === 'device'
              ? 'After authorizing, return to your device.'
              : null}
          </p>

          {(clientLink || app.privacyPolicyUrl || app.termsUrl) && (
            <p className="text-muted-foreground text-center text-xs">
              {app.privacyPolicyUrl && (
                <a
                  href={app.privacyPolicyUrl}
                  target="_blank"
                  rel="noreferrer"
                  className="link-neutral"
                >
                  Privacy Policy
                </a>
              )}
              {app.privacyPolicyUrl && app.termsUrl ? ' · ' : null}
              {app.termsUrl && (
                <a
                  href={app.termsUrl}
                  target="_blank"
                  rel="noreferrer"
                  className="link-neutral"
                >
                  Terms of Service
                </a>
              )}
            </p>
          )}
        </div>
      </div>
    </Card>
  )
}
