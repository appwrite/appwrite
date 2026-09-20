'use client'

import { useEffect, useMemo, useState } from 'react'
import {
  AuthFlowDescription,
  AuthFlowNarrowCard,
  AuthFlowTitle,
  authFlowMetaClassName,
} from '@/components/global/auth/AuthFlowCard'
import { AuthFlowHeaderIcon } from '@/components/global/auth/AuthFlowHeaderIcon'
import { useT } from '@/lib/i18n/translate'
import { Link2Off, Smartphone, TriangleAlert } from 'lucide-react'

const CREATE_OAUTH2_SESSION_DOCS =
  'https://appwrite.io/docs/references/cloud/client-web/account#createOAuth2Session'

type OAuthError = {
  message?: string
  type?: string
  code?: number
}

type OAuth2RelayCardProps = {
  title: string
  /** Debug preview: skip the native redirect and use injected state. */
  preview?: boolean
  previewProject?: string | null
  previewError?: OAuthError | null
  previewSearch?: string
}

/**
 * Native OAuth2 callback relay. The client SDK sends the OS browser here after a
 * social login; this page bounces back into the native app via its custom
 * `appwrite-callback-<project>://` scheme, carrying the original query string.
 *
 * `title` is the only difference between the success and failure variants.
 */
export function OAuth2RelayCard({
  title,
  preview = false,
  previewProject,
  previewError,
  previewSearch = '',
}: OAuth2RelayCardProps) {
  const t = useT()
  // Client-only route (ssr: false) - read the live query string on mount.
  const [search, setSearch] = useState(preview ? previewSearch : '')
  const [project, setProject] = useState<string | null>(
    preview ? (previewProject ?? null) : null,
  )
  const [oauthError, setOauthError] = useState<OAuthError | null>(
    preview ? (previewError ?? null) : null,
  )

  useEffect(() => {
    if (preview) {
      setSearch(previewSearch)
      setProject(previewProject ?? null)
      setOauthError(previewError ?? null)
      return
    }

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
  }, [preview, previewProject, previewError, previewSearch])

  const callbackLink = useMemo(
    () => (project ? `appwrite-callback-${project}://${search}` : null),
    [project, search],
  )

  // Attempt the native redirect immediately once we know the project.
  useEffect(() => {
    if (preview || !callbackLink) return
    window.location.href = callbackLink
  }, [callbackLink, preview])

  const content = (
    <AuthFlowNarrowCard>
      {project ? (
        <div className="flex flex-col items-center gap-4 text-center">
          <AuthFlowHeaderIcon icon={Smartphone} />
          <div className="space-y-2">
          <AuthFlowTitle>{title}</AuthFlowTitle>
          <AuthFlowDescription>
            {t(
              'You will be automatically redirected back to your app shortly.',
            )}
          </AuthFlowDescription>
          <AuthFlowDescription>
            {t('If you are not redirected, please click on the following')}{' '}
            <a href={callbackLink ?? '#'} className="link-neutral">
              {t('link')}
            </a>
            .
          </AuthFlowDescription>
          </div>
        </div>
      ) : oauthError ? (
        <div className="flex flex-col items-center gap-4 text-center">
          <AuthFlowHeaderIcon icon={TriangleAlert} variant="destructive" />
          <div className="space-y-2">
          <AuthFlowTitle>{t('Login failed')}</AuthFlowTitle>
          <AuthFlowDescription>
            {oauthError.message ??
              t('An error occurred during the OAuth login flow.')}
          </AuthFlowDescription>
          {oauthError.type ? (
            <p className={authFlowMetaClassName}>
              {t('Error type:')} {oauthError.type}
            </p>
          ) : null}
          </div>
        </div>
      ) : (
        <div className="flex flex-col items-center gap-4 text-center">
          <AuthFlowHeaderIcon icon={Link2Off} variant="destructive" />
          <div className="space-y-2">
          <AuthFlowTitle>{t('Missing redirect URL')}</AuthFlowTitle>
          <AuthFlowDescription>
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
          </AuthFlowDescription>
          </div>
        </div>
      )}
    </AuthFlowNarrowCard>
  )

  return content
}
