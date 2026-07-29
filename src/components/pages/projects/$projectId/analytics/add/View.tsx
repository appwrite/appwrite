import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { Check, Loader2 } from 'lucide-react'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { IdInput } from '@/components/ui/id-input'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import {
  useAnalyticsFirstEvent,
  useAnalyticsProperty,
  useCreateAnalyticsProperty,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canCreateAnalyticsProperty } from '@/lib/console-access-checks'
import {
  ANALYTICS_PLATFORM_META,
  isAnalyticsPlatform,
  type AnalyticsPlatform,
} from '@/lib/analytics-wizard/snippets'
import { IntegrationSnippets } from '../_components/IntegrationSnippets'
import { WizardProgress, type WizardStage } from './_components/WizardProgress'
import { PlatformCards } from './_components/PlatformCards'
import { EventAside } from './_components/EventAside'

export type AddPropertySearchState = {
  step?: 'configure' | 'setup'
  platform?: AnalyticsPlatform
  propertyId?: string
  /** Step within configure: pick platform vs property details form */
  configureStep?: 'platform' | 'details'
}

type ViewProps = {
  projectId: string
  search: AddPropertySearchState
}

function normalizePlatform(
  value: AnalyticsPlatform | undefined,
): AnalyticsPlatform {
  return isAnalyticsPlatform(value) ? value : 'web'
}

function normalizeConfigureStep(
  raw: string | undefined,
  phase: 'configure' | 'setup',
): 'platform' | 'details' {
  if (phase === 'setup') return 'details'
  return raw === 'details' ? 'details' : 'platform'
}

/** Browser IANA timezone, used as the property's daily boundary by default. */
function resolveBrowserTimezone(): string | undefined {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || undefined
  } catch {
    return undefined
  }
}

function SetupStep({
  number,
  label,
  children,
}: {
  number: number
  label: string
  children: React.ReactNode
}) {
  return (
    <div className="flex gap-3 sm:gap-4">
      <span
        className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-border bg-background text-[11px] font-semibold text-muted-foreground"
        aria-hidden
      >
        {number}
      </span>
      <div className="min-w-0 flex-1 pt-0.5">
        <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
          {label}
        </p>
        <div className="space-y-2">{children}</div>
      </div>
    </div>
  )
}

export function View({ projectId, search }: ViewProps) {
  const t = useT()
  const navigate = useNavigate()

  const step = search.step ?? 'configure'
  const platform = normalizePlatform(search.platform)
  const configureStep = normalizeConfigureStep(search.configureStep, step)

  const wizardStage: WizardStage = useMemo(() => {
    if (step === 'setup') return 'setup'
    return configureStep === 'details' ? 'details' : 'platform'
  }, [step, configureStep])

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const mayCreate = canCreateAnalyticsProperty(access, features)

  const [name, setName] = useState('')
  const [domain, setDomain] = useState('')
  const [propertyId, setPropertyId] = useState<string | undefined>(undefined)
  const [nameError, setNameError] = useState<string | null>(null)
  const [createError, setCreateError] = useState<string | null>(null)

  const createMutation = useCreateAnalyticsProperty(projectId)

  // The property created in step 2 (or deep-linked via ?propertyId=).
  const { property } = useAnalyticsProperty(
    projectId,
    step === 'setup' ? search.propertyId : undefined,
  )

  const { eventReceived, firstEventName } = useAnalyticsFirstEvent(
    projectId,
    search.propertyId,
    step === 'setup',
  )

  useEffect(() => {
    if (!mayCreate) {
      navigate({
        to: '/projects/$projectId/analytics',
        params: { projectId },
        replace: true,
      })
    }
  }, [mayCreate, navigate, projectId])

  // Landing on the setup step without a property is meaningless; send the user
  // back to the start of the wizard.
  useEffect(() => {
    if (step === 'setup' && !search.propertyId) {
      navigate({
        to: '/projects/$projectId/analytics/add',
        params: { projectId },
        search: {
          step: 'configure',
          platform,
          configureStep: 'platform',
        },
        replace: true,
      })
    }
  }, [step, search.propertyId, navigate, projectId, platform])

  function updateSearch(patch: Partial<AddPropertySearchState>) {
    navigate({
      to: '/projects/$projectId/analytics/add',
      params: { projectId },
      search: {
        step: search.step,
        platform: search.platform,
        propertyId: search.propertyId,
        configureStep: search.configureStep,
        ...patch,
      },
      replace: true,
    })
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setCreateError(null)

    if (!name.trim()) {
      setNameError(t('Name is required'))
      return
    }
    setNameError(null)

    createMutation.mutate(
      {
        propertyId,
        name: name.trim(),
        domain: domain.trim() || undefined,
        timezone: resolveBrowserTimezone(),
      },
      {
        onSuccess: (created) => {
          updateSearch({
            step: 'setup',
            propertyId: created.$id,
            configureStep: 'details',
          })
        },
        onError: (error) => setCreateError(getErrorMessage(error)),
      },
    )
  }

  const platformMeta = ANALYTICS_PLATFORM_META[platform]

  const useWizardSidebar = step === 'setup'
  const sidebar =
    step === 'setup' ? (
      <EventAside
        platformSlug={platformMeta.iconSlug}
        eventReceived={eventReceived}
        firstEventName={firstEventName}
      />
    ) : null

  return (
    <WizardLayout
      title={t('Add analytics property')}
      headerBottom={<WizardProgress stage={wizardStage} />}
      fallbackPath={`/projects/${projectId}/analytics`}
      fullscreen
      useSidebar={useWizardSidebar}
      sidebar={sidebar}
      constrainWidth
      maxWidth="max-w-7xl"
      footerAlign="right"
      footer={
        step === 'configure' && configureStep === 'platform' ? (
          <Button
            key="wizard-step-platform"
            type="button"
            onClick={() => {
              // Defer so the click fully completes before the footer button
              // swaps to type="submit" on the next stage.
              requestAnimationFrame(() =>
                updateSearch({ configureStep: 'details', platform }),
              )
            }}
          >
            {t('Continue')}
          </Button>
        ) : step === 'configure' && configureStep === 'details' ? (
          <Button
            key="wizard-step-details"
            type="submit"
            form="add-property-configure"
            disabled={createMutation.isPending}
          >
            {t('Create and continue')}
          </Button>
        ) : (
          <>
            <Button
              key="wizard-step-setup-add-another"
              type="button"
              variant="outline"
              onClick={() =>
                navigate({
                  to: '/projects/$projectId/analytics/add',
                  params: { projectId },
                  search: {
                    step: 'configure',
                    platform,
                    configureStep: 'platform',
                  },
                  replace: true,
                })
              }
            >
              {t('Add another property')}
            </Button>
            <Button
              key="wizard-step-setup-done"
              type="button"
              onClick={() =>
                search.propertyId
                  ? navigate({
                      to: '/projects/$projectId/analytics/$propertyId',
                      params: { projectId, propertyId: search.propertyId },
                    })
                  : navigate({
                      to: '/projects/$projectId/analytics',
                      params: { projectId },
                    })
              }
            >
              {t('Done')}
            </Button>
          </>
        )
      }
    >
      {step === 'configure' && configureStep === 'platform' ? (
        <div className="w-full space-y-8">
          <section className="space-y-3">
            <div>
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Choose your platform')}
              </h3>
              <p className="mt-1 text-[13px] text-muted-foreground">
                {t(
                  'Pick where you are tracking from. You can change this later.',
                )}
              </p>
            </div>
            <PlatformCards
              value={platform}
              onChange={(next) => updateSearch({ platform: next })}
              disabled={createMutation.isPending}
            />
          </section>
        </div>
      ) : step === 'configure' && configureStep === 'details' ? (
        <div className="mx-auto w-full max-w-2xl space-y-6">
          <div className="flex items-center justify-between gap-3 rounded-xl border border-border bg-card/50 px-4 py-3">
            <div className="min-w-0">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Platform')}
              </p>
              <p className="mt-0.5 truncate text-[13px] font-medium text-foreground">
                {t(platformMeta.label)}
              </p>
            </div>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 shrink-0 text-[12px]"
              disabled={createMutation.isPending}
              onClick={() => updateSearch({ configureStep: 'platform' })}
            >
              {t('Change')}
            </Button>
          </div>

          <form
            id="add-property-configure"
            onSubmit={handleSubmit}
            className="space-y-8"
          >
            <section className="space-y-4">
              <div>
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('Property details')}
                </h3>
                <p className="mt-1 text-[13px] text-muted-foreground">
                  {t(
                    'Daily boundaries use your current timezone. You can change every value later in settings.',
                  )}
                </p>
              </div>

              <div className="space-y-2">
                <Label htmlFor="add-property-name">
                  {t('Name')} <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="add-property-name"
                  type="text"
                  placeholder={t('Enter property name')}
                  value={name}
                  onChange={(e) => {
                    setName(e.target.value)
                    if (nameError) setNameError(null)
                  }}
                  disabled={createMutation.isPending}
                  className={nameError ? 'border-destructive' : ''}
                />
                {nameError && (
                  <p className="text-[12px] text-destructive">{nameError}</p>
                )}
              </div>

              <div className="space-y-2">
                <Label htmlFor="add-property-domain">{t('Domain')}</Label>
                <Input
                  id="add-property-domain"
                  type="text"
                  placeholder={t('example.com')}
                  value={domain}
                  onChange={(e) => setDomain(e.target.value)}
                  disabled={createMutation.isPending}
                />
                <p className="text-[12px] text-muted-foreground">
                  {t('Optional for native apps.')}
                </p>
              </div>

              <div className="space-y-2">
                <Label htmlFor="add-property-id">{t('Property ID')}</Label>
                <IdInput
                  id="add-property-id"
                  value={propertyId}
                  onChange={setPropertyId}
                  maxLength={36}
                  disabled={createMutation.isPending}
                  placeholder={t('Leave blank to auto-generate')}
                />
              </div>

              {createError && (
                <p className="text-[12px] text-destructive">{createError}</p>
              )}
            </section>
          </form>
        </div>
      ) : (
        <div className="w-full space-y-6">
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Install tracking')}
              </h3>
              <p className="mt-2 text-[13px] text-muted-foreground">
                {t(
                  'Add this to your app, then load a page so the first event reaches Appwrite.',
                )}
              </p>
              {property && (
                <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2">
                  <span className="inline-flex items-center gap-2 text-[12px] text-muted-foreground">
                    {t('Property ID')}
                    <CopyableId id={property.$id} size="xs" />
                  </span>
                  {property.snippetId && (
                    <span className="inline-flex items-center gap-2 text-[12px] text-muted-foreground">
                      {t('Snippet ID')}
                      <CopyableId id={property.snippetId} size="xs" />
                    </span>
                  )}
                </div>
              )}
            </div>
            <div className="border-t border-border" />
            <div className="space-y-5 px-6 py-5">
              {property ? (
                <>
                  <SetupStep number={1} label={t('Add tracking to your app')}>
                    <IntegrationSnippets
                      projectId={projectId}
                      property={property}
                      platform={platform}
                      onPlatformChange={(next) =>
                        updateSearch({ platform: next })
                      }
                    />
                  </SetupStep>
                  <SetupStep number={2} label={t('Verify the first event')}>
                    <div
                      className={cn(
                        'flex items-center gap-2 rounded-md border px-3 py-2 text-[13px]',
                        eventReceived
                          ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                          : 'border-border bg-muted/30 text-muted-foreground',
                      )}
                    >
                      {eventReceived ? (
                        <span
                          className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-emerald-500/30 bg-emerald-500/10"
                          aria-hidden
                        >
                          <Check className="h-3 w-3 text-emerald-600 dark:text-emerald-400" />
                        </span>
                      ) : (
                        <Loader2 className="h-4 w-4 shrink-0 animate-spin" />
                      )}
                      <span className={cn(eventReceived && 'font-medium')}>
                        {eventReceived
                          ? firstEventName
                            ? `${t('Event received')}: ${firstEventName}`
                            : t('Event received')
                          : t('Waiting for the first event from your site…')}
                      </span>
                    </div>
                  </SetupStep>
                </>
              ) : (
                <p className="text-[13px] text-muted-foreground">
                  {t('Loading property...')}
                </p>
              )}
            </div>
          </div>
        </div>
      )}
    </WizardLayout>
  )
}
