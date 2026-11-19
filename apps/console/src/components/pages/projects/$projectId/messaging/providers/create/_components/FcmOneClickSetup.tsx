import {
  forwardRef,
  useCallback,
  useEffect,
  useImperativeHandle,
  useRef,
  useState,
} from 'react'
import { useQuery } from '@tanstack/react-query'
import { AlertCircle, Check, ExternalLink, Loader2 } from 'lucide-react'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { accountIdentitiesQueryOptions } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { createMintedCredentialTracker } from '@/lib/smtp/quick-setup'
import {
  QuickSetupReauthorizeRequiredError,
  startProviderAuthorization,
} from '@/lib/smtp/quick-setup-oauth'
import { useProviderTokens } from '@/lib/smtp/use-provider-tokens'
import {
  planProvisionCompletion,
  serviceAccountKeyId,
  type FcmProvisioningBlock,
} from '@/lib/fcm/google'
import {
  FcmProvisioningBlockedError,
  GoogleApiError,
  isScopeError,
  isServiceDisabledError,
  type FirebaseProject,
} from '@/lib/fcm/google-cloud-api'
import { FCM_QUICK_SETUP_PROVIDER } from '@/lib/fcm/fcm-provider'

type Phase =
  | 'idle'
  | 'loading'
  | 'ready'
  | 'no-projects'
  | 'enabling'
  | 'provisioning'
  | 'done'
  | 'blocked'
  | 'reauthorize'
  | 'error'

interface FcmOneClickSetupProps {
  projectName: string
  /** Current wizard values, so filling does not clobber what the user typed. */
  values: Record<string, unknown>
  onFill: (fields: {
    serviceAccountJSON: string
    /** Provider name; set only when the user left it empty. */
    name?: string
  }) => void
  /** Run the flow on mount, used when returning from authorization. */
  autoStart?: boolean
}

export interface FcmOneClickSetupHandle {
  /**
   * The wizard is creating the provider with `serviceAccountJSON`, and
   * `request` is that call. From here the minted key is decided by the request
   * alone: kept once the provider exists, revoked when creation fails, and
   * never touched by the unmount cleanup in between.
   */
  settleCredential(serviceAccountJSON: unknown, request: Promise<unknown>): void
}

/**
 * One-click setup for the messaging FCM provider: authorize Google once, then
 * let Appwrite create a dedicated service account on the chosen Firebase
 * project, mint its key, and fill the wizard's service account JSON field.
 *
 * When a gate from the design doc fails (missing scope, insufficient IAM, org
 * policy on key creation), the flow falls back to the manual JSON field below
 * with a message specific to the gate — the provider can always be created by
 * pasting the service account JSON by hand.
 *
 * The key exists at Google from the moment it is minted, but nothing depends on
 * it until the provider is created. Until the wizard hands that request over
 * through {@link FcmOneClickSetupHandle.settleCredential}, this panel owns the
 * key and revokes it when the user leaves without creating the provider.
 */
export const FcmOneClickSetup = forwardRef<
  FcmOneClickSetupHandle,
  FcmOneClickSetupProps
>(function FcmOneClickSetup(
  { projectName, values, onFill, autoStart = false },
  ref,
) {
  const t = useT()
  const { account } = useAuth()
  const accountId =
    (account as { $id?: string } | null | undefined)?.$id ?? null
  const provider = FCM_QUICK_SETUP_PROVIDER

  const { data: identitiesData, isPending: identitiesPending } = useQuery(
    accountIdentitiesQueryOptions(),
  )
  const connected = (identitiesData?.identities ?? []).some(
    (identity) => identity.provider === provider.oauth.provider,
  )

  const { callWith } = useProviderTokens()
  // Each provisioned service account's project, so cleanup deletes it on the
  // project it was created on even after the user picks a different one.
  const accountProjectsRef = useRef(new Map<string, string>())
  // Latest form values, read when provisioning completes so a name typed while
  // setup ran is not overwritten by the default.
  const valuesRef = useRef(values)
  valuesRef.current = values
  const [minted] = useState(() =>
    // The tracked id is the service account email; revoking deletes the account,
    // which cascades to its key.
    createMintedCredentialTracker<{ id: string; secret: string }>(
      (serviceAccount) =>
        callWith(provider, (accessToken) =>
          provider.api.deleteServiceAccount(
            accessToken,
            accountProjectsRef.current.get(serviceAccount) ?? '',
            serviceAccount,
          ),
        ),
    ),
  )
  const [phase, setPhase] = useState<Phase>('idle')
  const [block, setBlock] = useState<FcmProvisioningBlock>('insufficient-iam')
  const [projects, setProjects] = useState<FirebaseProject[]>([])
  const [projectId, setProjectId] = useState('')
  const [errorMessage, setErrorMessage] = useState('')
  const [errorHelpUrl, setErrorHelpUrl] = useState('')
  const startedRef = useRef(false)
  // False once unmounted, so a provision that resolves after the user leaves
  // revokes its key instead of tracking it (the unmount cleanup already ran).
  const mountedRef = useRef(true)

  const selectedProject = projects.find((p) => p.id === projectId)

  const failWith = useCallback(
    (error: unknown, fallback: string) => {
      if (error instanceof QuickSetupReauthorizeRequiredError) {
        setPhase('reauthorize')
        return
      }
      if (isScopeError(error)) {
        // The token is missing the Cloud Platform access; re-consent is the fix.
        setBlock('scope')
        setPhase('blocked')
        return
      }
      if (isServiceDisabledError(error)) {
        // Operator setup: a Google API the console needs is turned off. Point
        // straight at the activation page Google returned.
        setErrorMessage(
          t(
            'A Google API required for setup is not enabled on this console. Enable it, wait a minute, then try again.',
          ),
        )
        setErrorHelpUrl((error as GoogleApiError).helpUrl ?? '')
        setPhase('error')
        return
      }
      setErrorHelpUrl('')
      // Google's own message is actionable (missing permission, wrong project,
      // quota); surface it rather than the generic 403 copy that getErrorMessage
      // collapses every forbidden response into.
      setErrorMessage(
        error instanceof GoogleApiError
          ? error.message
          : getErrorMessage(error, fallback),
      )
      setPhase('error')
    },
    [t],
  )

  const loadProjects = useCallback(async () => {
    setPhase('loading')
    setErrorMessage('')
    try {
      const list = [
        ...(await callWith(provider, provider.api.listProjects)),
      ].sort((a, b) => a.displayName.localeCompare(b.displayName))
      setProjects(list)
      if (list.length === 0) {
        setPhase('no-projects')
        return
      }
      setProjectId(list[0]!.id)
      setPhase('ready')
    } catch (error) {
      failWith(error, 'Failed to load Firebase projects')
    }
  }, [callWith, failWith, provider])

  useEffect(() => {
    if (!autoStart || startedRef.current || !connected) return
    startedRef.current = true
    void loadProjects()
  }, [autoStart, connected, loadProjects])

  // Leaving the wizard without creating the provider abandons the minted key.
  // Revoke it rather than leave a live, unused key on the service account. The
  // mounted flag lets a provision that resolves after this point clean up its
  // own key, since track() after release() would not. Set it in the body (not
  // only the cleanup) so a remount — e.g. StrictMode's mount→unmount→mount in
  // dev — restores it to true rather than leaving it stuck false.
  useEffect(() => {
    mountedRef.current = true
    return () => {
      mountedRef.current = false
      minted.release()
    }
  }, [minted])

  useImperativeHandle(
    ref,
    () => ({
      // Settle by the key's identity, not the exact text: the user may reformat
      // the JSON in the field before creating the provider, and the created
      // provider still uses this same key, so cleanup must not revoke it.
      settleCredential: (serviceAccountJSON, request) =>
        minted.settle(serviceAccountKeyId(serviceAccountJSON), request),
    }),
    [minted],
  )

  const authorize = () => {
    if (!accountId) return
    try {
      startProviderAuthorization(provider)
    } catch (error) {
      failWith(error, 'Failed to connect Google')
    }
  }

  const generate = async () => {
    if (!selectedProject) return
    setErrorMessage('')
    try {
      // Best effort: turn on the services provisioning needs on the user's own
      // project so a SERVICE_DISABLED never interrupts the flow. Failures (no
      // permission, or the OAuth client's project) are left to the manual
      // "Enable the API" fallback that provisioning still surfaces.
      setPhase('enabling')
      try {
        await callWith(provider, (accessToken) =>
          provider.api.enableServices(accessToken, selectedProject.id),
        )
      } catch {
        // Swallowed on purpose; see above.
      }

      setPhase('provisioning')
      const preflight = await callWith(provider, (accessToken) =>
        provider.api.checkPermissions(accessToken, selectedProject.id),
      )
      if (!preflight.ok) {
        setBlock('insufficient-iam')
        setPhase('blocked')
        return
      }

      const key = await callWith(provider, (accessToken) =>
        provider.api.provisionKey(accessToken, {
          projectId: selectedProject.id,
          projectName,
        }),
      )

      const completion = planProvisionCompletion({
        mounted: mountedRef.current,
        // Latest form name, not the snapshot from when Generate was clicked.
        currentName:
          typeof valuesRef.current.name === 'string'
            ? valuesRef.current.name
            : '',
        defaultName: t('One-click FCM sender'),
      })

      // The user left while this was in flight: the unmount cleanup already ran
      // before the account existed, so delete it here and don't touch form state
      // (which could belong to a different provider now).
      if (completion.kind === 'cleanup') {
        void callWith(provider, (accessToken) =>
          provider.api.deleteServiceAccount(
            accessToken,
            selectedProject.id,
            key.serviceAccount,
          ),
        ).catch(() => {})
        return
      }

      // Track by the key's identity (its private_key_id) so settling matches
      // even if the user reformats the JSON. Provisioning again replaces the
      // credential, so the previous account (if any) is revoked here as well,
      // on its own project from the map rather than the one selected now.
      accountProjectsRef.current.set(key.serviceAccount, selectedProject.id)
      minted.track({
        id: key.serviceAccount,
        secret: serviceAccountKeyId(key.secret),
      })
      onFill({ serviceAccountJSON: key.secret, name: completion.name })
      setPhase('done')
    } catch (error) {
      if (error instanceof FcmProvisioningBlockedError) {
        setBlock(error.block)
        setPhase('blocked')
        return
      }
      failWith(error, 'Failed to create the FCM credential')
    }
  }

  const blockedMessage = (reason: FcmProvisioningBlock): string => {
    if (reason === 'scope') {
      return t(
        'Google was connected without permission to create the credential. Reconnect and allow the requested access, or paste the service account JSON below.',
      )
    }
    if (reason === 'org-policy') {
      return t(
        'This project blocks service account key creation. Paste the service account JSON from the Firebase console below.',
      )
    }
    return t(
      'Your Google account cannot create service accounts on this project. Paste the service account JSON below, or ask a project owner to run this.',
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="flex items-center gap-3 px-4 py-3">
        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
          <img
            src={provider.iconPath}
            alt=""
            aria-hidden="true"
            className={`h-4 w-4 ${PUBLIC_ICON_MUTED_CLASSES}`}
          />
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-[13px] font-medium text-foreground">
            {t('One-click setup')}
          </p>
          <p className="text-[12px] text-muted-foreground">
            {t(
              'Sets up Firebase for you instead of doing it by hand. It runs the same steps our docs walk you through.',
            )}
          </p>
          {/* The connect step triggers Google's broad-permission consent. Say
              plainly why we ask, in the voice you'd use answering it on a call:
              it's only to run the setup the user would otherwise do manually. */}
          {phase === 'idle' ? (
            <p className="mt-1 text-[12px] text-muted-foreground">
              {t(
                'The only reason we ask for Google access is to do that setup for you.',
              )}
            </p>
          ) : null}
        </div>
        {phase === 'idle' ? (
          // Same label whether or not Google is already connected; only the
          // action differs, and that is not known until the identities load.
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 shrink-0 text-[12px]"
            disabled={!accountId || identitiesPending}
            onClick={connected ? () => void loadProjects() : authorize}
          >
            {t(provider.connectLabel)}
          </Button>
        ) : null}
      </div>

      {phase !== 'idle' ? (
        <>
          <div className="border-t border-border" />
          <div className="px-4 py-3">
            {phase === 'loading' ||
            phase === 'enabling' ||
            phase === 'provisioning' ? (
              <div className="space-y-1.5">
                <p className="flex items-center gap-2 text-[12px] text-muted-foreground">
                  <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  {phase === 'loading'
                    ? t('Loading Firebase projects…')
                    : phase === 'enabling'
                      ? t('Enabling the required Google APIs…')
                      : t('Creating the service account…')}
                </p>
                {/* Enabling APIs and minting the key are several Google calls
                    and can run close to a minute; say so rather than leave the
                    user watching a silent spinner. */}
                {phase === 'enabling' || phase === 'provisioning' ? (
                  <p className="ps-[22px] text-[12px] text-muted-foreground/80">
                    {t('This can take up to a minute. Keep this tab open.')}
                  </p>
                ) : null}
              </div>
            ) : null}

            {phase === 'ready' ? (
              <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div className="min-w-0 flex-1 space-y-2">
                  <Label
                    htmlFor="fcm-one-click-project"
                    className="text-[12px] font-medium"
                  >
                    {t('Firebase project')}
                  </Label>
                  <Select value={projectId} onValueChange={setProjectId}>
                    <SelectTrigger
                      id="fcm-one-click-project"
                      className="h-9 text-[13px]"
                    >
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {projects.map((p) => (
                        <SelectItem key={p.id} value={p.id}>
                          {p.displayName}
                          {p.displayName !== p.id ? (
                            <span className="text-muted-foreground">
                              {' '}
                              ({p.id})
                            </span>
                          ) : null}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <Button
                  type="button"
                  size="sm"
                  className="h-9 shrink-0 text-[13px]"
                  disabled={!selectedProject}
                  onClick={() => void generate()}
                >
                  {t('Generate credentials')}
                </Button>
              </div>
            ) : null}

            {phase === 'no-projects' ? (
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-[12px] text-muted-foreground">
                  {t('No Firebase projects found on this account.')}
                </p>
                <div className="flex shrink-0 gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-8 text-[12px]"
                    asChild
                  >
                    <a
                      href={provider.projectsUrl}
                      target="_blank"
                      rel="noreferrer"
                    >
                      {t('Open Firebase')}
                      <ExternalLink className="ml-1.5 h-3.5 w-3.5" />
                    </a>
                  </Button>
                  <Button
                    type="button"
                    size="sm"
                    className="h-8 text-[12px]"
                    onClick={() => void loadProjects()}
                  >
                    {t('Check again')}
                  </Button>
                </div>
              </div>
            ) : null}

            {phase === 'done' ? (
              <p className="flex items-center gap-2 text-[12px] text-foreground">
                <Check className="h-3.5 w-3.5 text-green-600" />
                {t(
                  'Service account created and filled in below. Review and create the provider.',
                )}
              </p>
            ) : null}

            {phase === 'blocked' ? (
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-[12px] text-muted-foreground">
                  {blockedMessage(block)}
                </p>
                {block === 'scope' ? (
                  <Button
                    type="button"
                    size="sm"
                    className="h-8 shrink-0 text-[12px]"
                    disabled={!accountId}
                    onClick={authorize}
                  >
                    {t('Reconnect')}
                  </Button>
                ) : null}
              </div>
            ) : null}

            {phase === 'reauthorize' ? (
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-[12px] text-muted-foreground">
                  {t('Reconnect your Google account to continue.')}
                </p>
                <Button
                  type="button"
                  size="sm"
                  className="h-8 shrink-0 text-[12px]"
                  disabled={!accountId}
                  onClick={authorize}
                >
                  {t('Reconnect')}
                </Button>
              </div>
            ) : null}

            {phase === 'error' ? (
              <div className="space-y-3">
                <Alert variant="destructive" className="border-destructive/30">
                  <AlertCircle className="h-4 w-4" />
                  <AlertDescription className="text-[13px]">
                    {errorMessage}
                  </AlertDescription>
                </Alert>
                <div className="flex gap-2">
                  {errorHelpUrl ? (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-8 text-[12px]"
                      asChild
                    >
                      <a href={errorHelpUrl} target="_blank" rel="noreferrer">
                        {t('Enable the API')}
                        <ExternalLink className="ml-1.5 h-3.5 w-3.5" />
                      </a>
                    </Button>
                  ) : null}
                  <Button
                    type="button"
                    size="sm"
                    className="h-8 text-[12px]"
                    onClick={() => void loadProjects()}
                  >
                    {t('Try again')}
                  </Button>
                </div>
              </div>
            ) : null}
          </div>
        </>
      ) : null}
    </div>
  )
})
