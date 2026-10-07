import { useEffect, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { ArrowRight } from 'lucide-react'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { IdInput } from '@/components/ui/id-input'
import { CopyableId } from '@/components/global/shared/CopyableId'
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
import { PlatformCards } from './_components/PlatformCards'
import { EventAside } from './_components/EventAside'

export type AddPropertySearchState = {
  step?: 'configure' | 'setup'
  platform?: AnalyticsPlatform
  propertyId?: string
  /** Legacy two-part configure step; ignored (configure is one page now). */
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

/** Browser IANA timezone, used as the property's daily boundary by default. */
function resolveBrowserTimezone(): string | undefined {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || undefined
  } catch {
    return undefined
  }
}

/**
 * Two stages, no progress bar:
 *
 * 1. Configure - name, domain, platform and an optional custom ID on one page.
 * 2. Install - the snippet for the chosen platform while we wait for the
 *    first event. The user can skip at any point; tracking keeps working and
 *    the snippet is always available again in the property's settings.
 */
export function View({ projectId, search }: ViewProps) {
  const t = useT()
  const navigate = useNavigate()

  const step = search.step === 'setup' && search.propertyId ? 'setup' : 'configure'
  const platform = normalizePlatform(search.platform)

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

  // The property created in stage 1 (or deep-linked via ?propertyId=).
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

  function updateSearch(patch: Partial<AddPropertySearchState>) {
    navigate({
      to: '/projects/$projectId/analytics/add',
      params: { projectId },
      search: {
        step: search.step,
        platform: search.platform,
        propertyId: search.propertyId,
        ...patch,
      },
      replace: true,
    })
  }

  const openProperty = () => {
    if (!search.propertyId) return
    navigate({
      to: '/projects/$projectId/analytics/$propertyId',
      params: { projectId, propertyId: search.propertyId },
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
          updateSearch({ step: 'setup', propertyId: created.$id, platform })
        },
        onError: (error) => setCreateError(getErrorMessage(error)),
      },
    )
  }

  const platformMeta = ANALYTICS_PLATFORM_META[platform]

  return (
    <WizardLayout
      title={step === 'setup' ? t('Install tracking') : t('Create property')}
      fallbackPath={`/projects/${projectId}/analytics`}
      fullscreen
      // Single column: the layout defaults to a 2/3 + sidebar grid.
      useSidebar={false}
      constrainWidth
      maxWidth="max-w-3xl"
      footerAlign="right"
      footer={
        step === 'configure' ? (
          <Button
            key="wizard-create"
            type="submit"
            form="add-property-configure"
            disabled={createMutation.isPending}
          >
            {createMutation.isPending ? t('Creating...') : t('Create property')}
          </Button>
        ) : eventReceived ? (
          <Button key="wizard-open" type="button" onClick={openProperty}>
            {t('Open dashboard')}
            <ArrowRight className="ms-1.5 h-4 w-4 rtl:rotate-180" />
          </Button>
        ) : (
          // Tracking doesn't depend on this page: skipping is always safe.
          <Button
            key="wizard-skip"
            type="button"
            variant="outline"
            onClick={openProperty}
          >
            {t('Skip for now')}
          </Button>
        )
      }
    >
      {step === 'configure' ? (
        <form
          id="add-property-configure"
          onSubmit={handleSubmit}
          className="w-full space-y-8"
        >
          <section className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="add-property-name">
                {t('Name')} <span className="text-destructive">*</span>
              </Label>
              <Input
                id="add-property-name"
                type="text"
                placeholder={t('My website')}
                value={name}
                autoFocus
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
              <Label htmlFor="add-property-domain">
                {t('Domain')}{' '}
                <span className="text-muted-foreground">({t('optional')})</span>
              </Label>
              <Input
                id="add-property-domain"
                type="text"
                placeholder={t('example.com')}
                value={domain}
                onChange={(e) => setDomain(e.target.value)}
                disabled={createMutation.isPending}
              />
            </div>
          </section>

          <section className="space-y-3">
            <div>
              <Label>{t('Platform')}</Label>
              <p className="mt-1 text-[12px] text-muted-foreground">
                {t('Decides which install snippet you get next. You can switch any time.')}
              </p>
            </div>
            <PlatformCards
              value={platform}
              onChange={(next) => updateSearch({ platform: next })}
              disabled={createMutation.isPending}
            />
          </section>

          {/* Same pattern as the other create forms: label + collapsed ID chip. */}
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

          <p className="border-t border-border pt-4 text-[12px] text-muted-foreground">
            {t(
              'Daily totals use your current timezone. You can change every value later in settings.',
            )}
          </p>

          {createError && (
            <p className="text-[12px] text-destructive">{createError}</p>
          )}
        </form>
      ) : (
        <div className="w-full space-y-6">
          {/* Live status: waits for the first event, turns green when it lands. */}
          <EventAside
            platformSlug={platformMeta.iconSlug}
            eventReceived={eventReceived}
            firstEventName={firstEventName}
          />

          <div className="overflow-hidden rounded-xl border border-border bg-card/50">
            <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
              <div className="min-w-0">
                <h3 className="truncate text-[14px] font-semibold text-foreground">
                  {property?.name ?? t('Loading property...')}
                </h3>
                <p className="mt-0.5 text-[12px] text-muted-foreground">
                  {t('Add this to your app, then open it so the first event reaches Appwrite.')}
                </p>
              </div>
              {property ? (
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
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
              ) : null}
            </div>
            {property ? (
              <div className="border-t border-border px-5 py-5">
                <IntegrationSnippets
                  projectId={projectId}
                  property={property}
                  platform={platform}
                  onPlatformChange={(next) => updateSearch({ platform: next })}
                />
              </div>
            ) : null}
          </div>
        </div>
      )}
    </WizardLayout>
  )
}
