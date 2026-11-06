import { useCallback, useEffect, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { projectQueryOptions, useUpdateSMTP } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  buildCredentialName,
  createMintedCredentialTracker,
  defaultSenderEmail,
  emailBelongsToDomain,
  pickDefaultQuickSetupDomain,
  sortQuickSetupDomains,
  type QuickSetupDomain,
} from '@/lib/smtp/quick-setup'
import type {
  AvailableSmtpQuickSetupProvider,
  SmtpQuickSetupCredential,
} from '@/lib/smtp/providers'
import { QuickSetupReauthorizeRequiredError } from '@/lib/smtp/quick-setup-oauth'

/**
 * Runs a provider API call with a valid access token, refreshing it through the
 * console session when needed. Throws {@link QuickSetupReauthorizeRequiredError}
 * when only a new OAuth2 round trip can help.
 */
export type ProviderApiCall = <T>(
  run: (accessToken: string) => Promise<T>,
) => Promise<T>

export type QuickSetupPhase =
  | 'loading'
  | 'no-domains'
  | 'form'
  | 'submitting'
  | 'success'
  | 'reauthorize'
  | 'error'

interface UseSmtpQuickSetupOptions {
  /** Loads the provider's domains when it flips to true. */
  active: boolean
  projectId: string
  project: Models.Project | undefined
  provider: AvailableSmtpQuickSetupProvider
  callProvider: ProviderApiCall
}

/**
 * The quick setup flow itself: load sending domains, pick sender details, mint
 * a credential, and save the project's SMTP settings.
 *
 * Presentation lives in {@link SmtpQuickSetupWizard}; this hook owns the
 * state so the flow can be tested and re-skinned without touching it.
 */
export function useSmtpQuickSetup({
  active,
  projectId,
  project,
  provider,
  callProvider,
}: UseSmtpQuickSetupOptions) {
  const t = useT()
  const queryClient = useQueryClient()
  const updateSMTPMutation = useUpdateSMTP(projectId)

  const [phase, setPhase] = useState<QuickSetupPhase>('loading')
  const [domains, setDomains] = useState<QuickSetupDomain[]>([])
  const [domainId, setDomainId] = useState('')
  const [senderName, setSenderName] = useState('')
  const [senderEmail, setSenderEmail] = useState('')
  const [errorMessage, setErrorMessage] = useState('')
  const [validationMessage, setValidationMessage] = useState('')

  const selectedDomain = domains.find((domain) => domain.id === domainId)

  const failWith = useCallback((error: unknown, fallback: string) => {
    if (error instanceof QuickSetupReauthorizeRequiredError) {
      setPhase('reauthorize')
      return
    }
    setErrorMessage(getErrorMessage(error, fallback))
    setPhase('error')
  }, [])

  const loadDomains = useCallback(async () => {
    setPhase('loading')
    setErrorMessage('')
    setValidationMessage('')
    try {
      const list = sortQuickSetupDomains(
        await callProvider(provider.api.listDomains),
      )
      setDomains(list)

      const initial = pickDefaultQuickSetupDomain(
        list,
        project?.smtpSenderEmail,
      )
      if (!initial) {
        setPhase('no-domains')
        return
      }

      setDomainId(initial.id)
      setSenderName(project?.smtpSenderName || project?.name || '')
      setSenderEmail(
        project?.smtpSenderEmail &&
          emailBelongsToDomain(project.smtpSenderEmail, initial.name)
          ? project.smtpSenderEmail
          : defaultSenderEmail(initial.name),
      )
      setPhase('form')
    } catch (error) {
      failWith(error, 'Failed to load domains from the email provider')
    }
  }, [callProvider, failWith, project, provider])

  useEffect(() => {
    if (!active) return
    void loadDomains()
    // Reload only when the flow opens; edits in between must not reset the form.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [active])

  const changeDomain = useCallback(
    (nextId: string) => {
      setDomainId(nextId)
      setValidationMessage('')
      const domain = domains.find((item) => item.id === nextId)
      if (domain && !emailBelongsToDomain(senderEmail, domain.name)) {
        setSenderEmail(defaultSenderEmail(domain.name))
      }
    },
    [domains, senderEmail],
  )

  const changeSenderName = useCallback((value: string) => {
    setSenderName(value)
    setValidationMessage('')
  }, [])

  const changeSenderEmail = useCallback((value: string) => {
    setSenderEmail(value)
    setValidationMessage('')
  }, [])

  const submit = useCallback(async () => {
    if (!selectedDomain) return

    const name = senderName.trim()
    const email = senderEmail.trim()
    if (!name) {
      setValidationMessage(t('Enter a sender name.'))
      return
    }
    if (!emailBelongsToDomain(email, selectedDomain.name)) {
      setValidationMessage(t('Enter a sender email on the selected domain.'))
      return
    }

    setValidationMessage('')
    setErrorMessage('')
    setPhase('submitting')

    const minted = createMintedCredentialTracker<SmtpQuickSetupCredential>(
      (credentialId) =>
        callProvider((accessToken) =>
          provider.api.deleteCredential(accessToken, credentialId),
        ),
    )
    try {
      const created = await callProvider((accessToken) =>
        provider.api.createCredential(accessToken, {
          name: buildCredentialName(
            project?.name ?? '',
            provider.credentialNameMaxLength,
          ),
          domainId: selectedDomain.id,
        }),
      )
      minted.track(created)

      const save = updateSMTPMutation.mutateAsync({
        enabled: true,
        senderName: name,
        senderEmail: email,
        replyTo: project?.smtpReplyToEmail || '',
        host: provider.smtp.host,
        port: provider.smtp.port,
        username: provider.smtp.username(selectedDomain.name),
        password: created.secret,
        secure: provider.smtp.secure,
      })
      minted.settle(created.secret, save)
      await save
    } catch (error) {
      // The project never stored the credential, so drop it at the provider
      // rather than let retries pile up unused credentials.
      minted.release()
      failWith(error, 'Failed to set up SMTP with the email provider')
      return
    }

    // The project now sends through the new credential, so nothing past this
    // point may revoke it or report a failure. Refreshing the project only
    // brings the SMTP form behind the flow up to date with what was saved:
    // best effort, since a failed refetch leaves that form stale, not the
    // setup broken. (`refetchQueries` only rejects when asked to via
    // `throwOnError`; the catch keeps the wizard off the spinner regardless.)
    await queryClient
      .refetchQueries({ queryKey: projectQueryOptions(projectId).queryKey })
      .catch(() => {})
    setPhase('success')
  }, [
    callProvider,
    failWith,
    project,
    projectId,
    provider,
    queryClient,
    selectedDomain,
    senderEmail,
    senderName,
    t,
    updateSMTPMutation,
  ])

  /** Relay settings the provider will use, resolved for the selected domain. */
  const serverSettings: ReadonlyArray<{ label: string; value: string }> = [
    { label: 'Server host', value: provider.smtp.host },
    { label: 'Server port', value: String(provider.smtp.port) },
    {
      label: 'Username',
      value: provider.smtp.username(selectedDomain?.name ?? ''),
    },
    { label: 'Secure protocol', value: provider.smtp.secure.toUpperCase() },
  ]

  return {
    phase,
    /** True while a request is in flight, so callers can hide their actions. */
    isBusy: phase === 'loading' || phase === 'submitting',
    errorMessage,
    validationMessage,
    domains,
    domainId,
    selectedDomain,
    senderName,
    senderEmail,
    serverSettings,
    changeDomain,
    changeSenderName,
    changeSenderEmail,
    reload: loadDomains,
    submit,
  }
}
