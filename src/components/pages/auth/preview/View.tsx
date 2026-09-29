import { useEffect, useRef, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { AppwriteException, Query } from '@appwrite.io/console'
import { Loader2, Lock, TriangleAlert } from 'lucide-react'
import { AuthFlowAccountSwitcherStatic } from '@/components/global/auth/AuthFlowAccountSwitcherStatic'
import {
  AuthFlowDescription,
  AuthFlowNarrowCard,
  AuthFlowTitle,
} from '@/components/global/auth/AuthFlowCard'
import { AuthFlowHeaderIcon } from '@/components/global/auth/AuthFlowHeaderIcon'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import { useAuthAccountSwitch } from '@/components/global/auth/useAuthAccountSwitch'
import { Button } from '@/components/ui/button'
import { listConsoleProjects } from '@/lib/appwrite/console-projects'
import { sdk } from '@/lib/appwrite/sdk'
import { useT } from '@/lib/i18n/translate'
import { isValidRelativeRedirect } from '@/lib/post-auth-navigation'
import { fetchOrganizations } from '@/lib/react-query/hooks/organizations'

export type SitesAuthPreviewStatus = 'checking' | 'denied' | 'error' | 'invalid'

type ViewProps = {
  projectId?: string
  origin?: string
  path?: string
  accountLabel: string
  preview?: boolean
  previewStatus?: SitesAuthPreviewStatus
}

export function View({
  projectId,
  origin,
  path = '/',
  accountLabel,
  preview = false,
  previewStatus = 'checking',
}: ViewProps) {
  const t = useT()
  const navigate = useNavigate()
  const [status, setStatus] = useState<'checking' | 'denied' | 'error'>(
    'checking',
  )
  const [attempt, setAttempt] = useState(0)
  const [isSwitchingAccount, setIsSwitchingAccount] = useState(false)
  // React StrictMode must not create two JWTs for the same attempt.
  const startedAttempt = useRef<number | null>(null)

  let previewUrl: URL | null = null
  try {
    previewUrl = origin ? new URL(origin) : null
  } catch {
    previewUrl = null
  }
  // The JWT is handed to `origin`, so it must be a bare http(s) origin. Appwrite
  // builds it from the request hostname, which never carries a port.
  const previewHostname =
    projectId &&
    previewUrl &&
    (previewUrl.protocol === 'https:' || previewUrl.protocol === 'http:') &&
    !previewUrl.port &&
    previewUrl.origin === origin &&
    isValidRelativeRedirect(path)
      ? previewUrl.hostname
      : null

  useEffect(() => {
    if (preview) return
    if (
      !projectId ||
      !origin ||
      !previewHostname ||
      startedAttempt.current === attempt
    ) {
      return
    }
    startedAttempt.current = attempt
    void (async () => {
      try {
        const organizations = await fetchOrganizations()
        const lists = await Promise.all(
          (organizations.teams ?? []).map((organization) =>
            listConsoleProjects({
              organizationId: organization.$id,
              queries: [
                Query.equal('$id', projectId),
                Query.limit(1),
                Query.select(['$id', 'region']),
              ],
              total: false,
            }).catch((error: unknown) => {
              if (
                error instanceof AppwriteException &&
                [401, 403, 404].includes(error.code)
              ) {
                return null
              }
              throw error
            }),
          ),
        )
        const project = lists.find((list) => list?.projects[0])?.projects[0]
        if (!project) {
          setStatus('denied')
          return
        }

        // Only the site preview domains Appwrite gates may receive a console JWT.
        const { rules } = await sdk
          .forProject(projectId, project.region)
          .proxy.listRules({
            queries: [
              Query.equal('domain', [previewHostname]),
              Query.equal('trigger', ['deployment']),
              Query.equal('deploymentResourceType', ['site']),
              Query.limit(1),
            ],
            total: false,
          })
        if (rules.length === 0) {
          setStatus('denied')
          return
        }

        const { jwt } = await sdk.forConsole.account.createJWT()
        window.location.replace(
          `${origin}/_appwrite/authorize?${new URLSearchParams({ jwt, path })}`,
        )
      } catch (error) {
        // Still fail closed, but only a missing permission reads as private.
        setStatus(
          error instanceof AppwriteException &&
            [401, 403, 404].includes(error.code)
            ? 'denied'
            : 'error',
        )
      }
    })()
  }, [preview, projectId, origin, path, previewHostname, attempt])

  const handleRetry = () => {
    if (preview) return
    setStatus('checking')
    setAttempt((current) => current + 1)
  }

  const switchAccount = useAuthAccountSwitch({ preview })
  const handleSwitchAccount = () => {
    if (isSwitchingAccount) return
    setIsSwitchingAccount(true)
    void switchAccount().finally(() => setIsSwitchingAccount(false))
  }

  const previewHostnameDisplay =
    preview && previewStatus !== 'invalid'
      ? 'example.com'
      : previewHostname
  const denied = preview ? previewStatus === 'denied' : status === 'denied'
  const failed = preview ? previewStatus === 'error' : status === 'error'
  const invalidPreview = preview && previewStatus === 'invalid'
  const checking = preview
    ? previewStatus === 'checking'
    : !denied && !failed && !!previewHostname

  return (
    <AuthFlowShell
      width="narrow"
      accountSwitcher={
        denied && accountLabel ? (
          <AuthFlowAccountSwitcherStatic
            accountLabel={accountLabel}
            preview={preview}
            onSwitchAccount={handleSwitchAccount}
            disabled={isSwitchingAccount}
          />
        ) : null
      }
    >
      <AuthFlowNarrowCard>
            <div className="space-y-6" aria-live="polite">
              <div className="flex flex-col items-center gap-4 text-center">
                {invalidPreview || !previewHostnameDisplay || failed ? (
                  <AuthFlowHeaderIcon icon={TriangleAlert} variant="destructive" />
                ) : checking ? (
                  <Loader2 className="h-8 w-8 animate-spin text-muted-foreground motion-reduce:animate-none" />
                ) : (
                  <AuthFlowHeaderIcon icon={Lock} />
                )}
                <div className="flex w-full max-w-sm flex-col items-center gap-3">
                  <AuthFlowTitle>
                    {invalidPreview || !previewHostnameDisplay
                      ? t('Invalid preview link')
                      : denied
                        ? t('Preview is private')
                        : failed
                          ? t("Couldn't open preview")
                          : t('Opening preview…')}
                  </AuthFlowTitle>
                  {previewHostnameDisplay ? (
                    <div dir="ltr" className="w-full flex justify-center">
                      <span className="inline-block max-w-full break-all rounded-lg border border-border bg-muted/30 px-3 py-2 text-center font-mono text-[13px] leading-snug text-foreground">
                        {previewHostnameDisplay}
                      </span>
                    </div>
                  ) : null}
                  <AuthFlowDescription className="w-full">
                    {invalidPreview || !previewHostnameDisplay
                      ? t(
                          'This link is missing or has a malformed preview address. Open the preview URL again to start over.',
                        )
                      : denied
                        ? t(
                            "Your account isn't in the organization that owns this site. Ask an organization member to invite you.",
                          )
                        : failed
                          ? t(
                              'Something went wrong while checking your access to this preview. Try again in a moment.',
                            )
                          : t(
                              'Checking your access to this preview deployment.',
                            )}
                  </AuthFlowDescription>
                </div>
              </div>
              {invalidPreview || !previewHostnameDisplay || denied || failed ? (
                <div className="flex flex-col gap-2">
                  {denied ? (
                    <Button
                      variant="brandCta"
                      className="w-full"
                      onClick={handleSwitchAccount}
                      disabled={isSwitchingAccount}
                    >
                      {t('Use a different account')}
                    </Button>
                  ) : null}
                  {failed ? (
                    <Button
                      variant="brandCta"
                      className="w-full"
                      onClick={handleRetry}
                      disabled={preview}
                    >
                      {t('Try again')}
                    </Button>
                  ) : null}
                  <Button
                    variant="outline"
                    className="w-full"
                    onClick={() => {
                      if (preview) return
                      navigate({ to: '/', replace: true })
                    }}
                    disabled={isSwitchingAccount}
                  >
                    {t('Go to console')}
                  </Button>
                </div>
              ) : null}
            </div>
      </AuthFlowNarrowCard>
    </AuthFlowShell>
  )
}
