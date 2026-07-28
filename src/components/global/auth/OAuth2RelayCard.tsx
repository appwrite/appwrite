'use client'

import { useEffect, useMemo, useState } from 'react'
import { Card } from '@/components/ui/card'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { useT } from '@/lib/i18n/translate'

const CREATE_OAUTH2_SESSION_DOCS =
  'https://appwrite.io/docs/references/cloud/client-web/account#createOAuth2Session'

type OAuthError = {
  message?: string
  type?: string
  code?: number
}

/**
 * Native OAuth2 callback relay. The client SDK sends the OS browser here after a
 * social login; this page bounces back into the native app via its custom
 * `appwrite-callback-<project>://` scheme, carrying the original query string.
 *
 * `title` is the only difference between the success and failure variants.
 */
export function OAuth2RelayCard({ title }: { title: string }) {
  const t = useT()
  // Client-only route (ssr: false) - read the live query string on mount.
  const [search, setSearch] = useState('')
  const [project, setProject] = useState<string | null>(null)
  const [oauthError, setOauthError] = useState<OAuthError | null>(null)

  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    setSearch(window.location.search)
    setProject(params.get('project'))

    const errorParam = params.get('error')
    if (errorParam) {
      try {
        setOauthError(JSON.parse(errorParam) as OAuthError)
      } catch {
        setOauthError({ message: errorParam })
      }
    }
  }, [])

  const callbackLink = useMemo(
    () => (project ? `appwrite-callback-${project}://${search}` : null),
    [project, search],
  )

  // Attempt the native redirect immediately once we know the project.
  useEffect(() => {
    if (callbackLink) window.location.href = callbackLink
  }, [callbackLink])

  return (
    <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
      <div className="w-full max-w-md">
        <Card className="overflow-hidden p-6 md:p-8">
          {project ? (
            <div className="space-y-3 text-center">
              <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
              <p className="text-muted-foreground text-sm">
                {t(
                  'You will be automatically redirected back to your app shortly.',
                )}
              </p>
              <p className="text-muted-foreground text-sm">
                {t('If you are not redirected, please click on the following')}{' '}
                <a href={callbackLink ?? '#'} className="link-neutral">
                  {t('link')}
                </a>
                .
              </p>
            </div>
          ) : oauthError ? (
            <div className="space-y-3 text-center">
              <h1 className="text-xl font-semibold tracking-tight">
                {t('Login failed')}
              </h1>
              <p className="text-muted-foreground text-sm">
                {oauthError.message ??
                  t('An error occurred during the OAuth login flow.')}
              </p>
              {oauthError.type ? (
                <p className="text-muted-foreground text-sm">
                  {t('Error type:')} {oauthError.type}
                </p>
              ) : null}
            </div>
          ) : (
            <div className="space-y-3 text-center">
              <h1 className="text-xl font-semibold tracking-tight">
                {t('Missing redirect URL')}
              </h1>
              <p className="text-muted-foreground text-sm">
                {t(
                  'Your OAuth login flow is missing a proper redirect URL. Please check the',
                )}{' '}
                <a
                  href={CREATE_OAUTH2_SESSION_DOCS}
                  target="_blank"
                  rel="noreferrer"
                  className="link-neutral"
                >
                  {t('OAuth docs')}
                </a>{' '}
                {t('and send request for new session with a valid callback URL.')}
              </p>
            </div>
          )}
        </Card>

        <div className="mt-10 flex justify-center md:mt-16">
          <AppwriteLogo className="h-6 w-auto" />
        </div>
      </div>
    </div>
  )
}
