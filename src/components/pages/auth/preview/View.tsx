import { useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { AppwriteException, Query } from '@appwrite.io/console'
import { Loader2, Lock, TriangleAlert } from 'lucide-react'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { AuthAccountChip } from '@/components/global/auth/AuthAccountChip'
import { Button } from '@/components/ui/button'
import { listConsoleProjects } from '@/lib/appwrite/console-projects'
import { sdk } from '@/lib/appwrite/sdk'
import { useT } from '@/lib/i18n/translate'
import { isValidRelativeRedirect } from '@/lib/post-auth-navigation'
import { performConsoleSignOut } from '@/lib/react-query/hooks/auth'
import { fetchOrganizations } from '@/lib/react-query/hooks/organizations'

type ViewProps = {
  projectId?: string
  origin?: string
  path?: string
  accountLabel: string
}

export function View({
  projectId,
  origin,
  path = '/',
  accountLabel,
}: ViewProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
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
  }, [projectId, origin, path, previewHostname, attempt])

  const handleRetry = () => {
    setStatus('checking')
    setAttempt((current) => current + 1)
  }

  const handleSwitchAccount = () => {
    if (isSwitchingAccount) return
    setIsSwitchingAccount(true)
    void performConsoleSignOut(queryClient, {
      redirect: `${window.location.pathname}${window.location.search}`,
    })
  }

  const denied = status === 'denied'
  const failed = status === 'error'

  return (
    <div className="bg-background h-full overflow-y-auto">
      <div className="flex min-h-full flex-col items-center p-6 md:p-10">
        <main className="my-auto w-full max-w-md">
          <div className="mb-8 flex justify-center">
            <AppwriteLogo className="h-7 w-auto" />
          </div>
          <section
            className="overflow-hidden rounded-xl border border-border bg-card/50 p-6 md:p-8"
            aria-live="polite"
          >
            <div className="space-y-6">
              <div className="flex flex-col items-center gap-4 text-center">
                {!previewHostname || failed ? (
                  <div className="bg-destructive/10 flex size-10 items-center justify-center rounded-xl">
                    <TriangleAlert className="text-destructive size-4" />
                  </div>
                ) : (
                  <div className="flex size-12 items-center justify-center rounded-xl border border-border bg-muted">
                    {denied ? (
                      <Lock
                        aria-hidden="true"
                        className="size-5 text-muted-foreground"
                      />
                    ) : (
                      <Loader2
                        aria-hidden="true"
                        className="size-5 animate-spin text-muted-foreground motion-reduce:animate-none"
                      />
                    )}
                  </div>
                )}
                <div className="space-y-1">
                  <h1 className="text-2xl font-semibold tracking-tight">
                    {!previewHostname
                      ? t('Invalid preview link')
                      : denied
                        ? t('Preview is private')
                        : failed
                          ? t("Couldn't open preview")
                          : t('Opening preview…')}
                  </h1>
                  {previewHostname ? (
                    <p
                      dir="ltr"
                      className="break-all font-mono text-[13px] text-foreground"
                    >
                      {previewHostname}
                    </p>
                  ) : null}
                  <p className="text-muted-foreground text-[13px] leading-relaxed">
                    {!previewHostname
                      ? t(
                          'This link is missing or has a malformed preview address. Open the preview URL again to start over.',
                        )
                      : denied
                        ? t(
                            "You don't have access to this preview. Ask a member of the project's organization to add you.",
                          )
                        : failed
                          ? t(
                              'Something went wrong while checking your access to this preview. Try again in a moment.',
                            )
                          : t(
                              'Checking your access to this preview deployment.',
                            )}
                  </p>
                </div>
                {denied && accountLabel ? (
                  <AuthAccountChip
                    accountLabel={accountLabel}
                    onSwitchAccount={handleSwitchAccount}
                    disabled={isSwitchingAccount}
                  />
                ) : null}
              </div>
              {!previewHostname || denied || failed ? (
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
                    >
                      {t('Try again')}
                    </Button>
                  ) : null}
                  <Button
                    variant="outline"
                    className="w-full"
                    onClick={() => navigate({ to: '/', replace: true })}
                    disabled={isSwitchingAccount}
                  >
                    {t('Go to console')}
                  </Button>
                </div>
              ) : null}
            </div>
          </section>
        </main>
      </div>
    </div>
  )
}
