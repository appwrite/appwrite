import { useMemo, useState } from 'react'
import { Link } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { WarningAlert } from '@/components/global/shared/WarningAlert'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { useSmartNavigation } from '@/lib/hooks/useSmartNavigation'
import { preferredOrganizationId } from '@/lib/assistant/agent-paths'
import { useOrganizationById, useOrganizations } from '@/lib/react-query/hooks'
import {
  enterpriseCompanySizeOptions,
  enterpriseFormBullets,
  enterprisePreferredDeploymentOptions,
  enterpriseTimelineOptions,
} from '@/lib/enterprise/content'
import { GrowthError } from '@/lib/growth'
import { submitEnterpriseApplication } from '@/lib/marketing/growth-forms'
import { trackEvent } from '@/lib/analytics'
import { toast } from 'sonner'
import { CheckCircle2, ExternalLink } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const USE_CASE_MAX = 4096
const NO_SELECTION_VALUE = '__none__'
const MAIN_CONTENT_MIN_HEIGHT = 'min-h-[min(70dvh,640px)]'

function isFullWebsiteUrl(value: string): boolean {
  try {
    const url = new URL(value.trim())
    return url.protocol === 'http:' || url.protocol === 'https:'
  } catch {
    return false
  }
}

function buildSalesFormDefaults(
  account: Models.User | null | undefined,
  organization: { name?: string } | null | undefined,
  organizations: readonly { name?: string }[],
) {
  const nameParts = account?.name?.trim().split(/\s+/) ?? []
  return {
    firstName: nameParts[0] ?? '',
    lastName: nameParts.slice(1).join(' '),
    email: account?.email ?? '',
    companyName:
      organization?.name?.trim() || organizations[0]?.name?.trim() || '',
  }
}

export function SalesWizardFullscreen() {
  const t = useT()
  const handleCancel = useSmartNavigation()
  const { account } = useAuth()
  const preferredOrgId = useMemo(
    () =>
      preferredOrganizationId(
        account?.prefs as Record<string, unknown> | undefined,
      ),
    [account?.prefs],
  )
  const { organization } = useOrganizationById(preferredOrgId)
  const { organizations } = useOrganizations()

  const formDefaults = useMemo(
    () => buildSalesFormDefaults(account, organization, organizations),
    [account, organization, organizations],
  )

  const [firstName, setFirstName] = useState(formDefaults.firstName)
  const [lastName, setLastName] = useState(formDefaults.lastName)
  const [email, setEmail] = useState(formDefaults.email)
  const [companyName, setCompanyName] = useState(formDefaults.companyName)
  const [companySize, setCompanySize] = useState(NO_SELECTION_VALUE)
  const [companyWebsite, setCompanyWebsite] = useState('')
  const [preferredDeployment, setPreferredDeployment] =
    useState(NO_SELECTION_VALUE)
  const [timeline, setTimeline] = useState(NO_SELECTION_VALUE)
  const [useCase, setUseCase] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [submitted, setSubmitted] = useState(false)
  const [submitError, setSubmitError] = useState(false)

  const canSubmit =
    firstName.trim().length > 0 &&
    lastName.trim().length > 0 &&
    email.trim().length > 0 &&
    companyName.trim().length > 0 &&
    companySize !== NO_SELECTION_VALUE &&
    isFullWebsiteUrl(companyWebsite) &&
    useCase.trim().length > 0 &&
    useCase.length <= USE_CASE_MAX &&
    !isSubmitting

  const handleSubmit = async () => {
    if (!canSubmit) return
    setSubmitError(false)
    setIsSubmitting(true)
    try {
      await submitEnterpriseApplication({
        firstName: firstName.trim(),
        lastName: lastName.trim(),
        email: email.trim(),
        companyName: companyName.trim(),
        companySize,
        companyWebsite: companyWebsite.trim(),
        preferredDeployment:
          preferredDeployment !== NO_SELECTION_VALUE
            ? preferredDeployment
            : undefined,
        timeline: timeline !== NO_SELECTION_VALUE ? timeline : undefined,
        useCase: useCase.trim(),
        cloudEmail: account?.email,
      })

      trackEvent('Form Submitted', { form: 'enterprise' })
      setSubmitted(true)
    } catch (error) {
      setSubmitError(true)
      // API messages are dynamic, so only the rate limit and fallback copy go through t().
      toast.error(
        error instanceof GrowthError
          ? error.isRateLimited
            ? t(error.message)
            : error.message
          : t('Error submitting form. Please contact support.'),
      )
    } finally {
      setIsSubmitting(false)
    }
  }

  const sidebar = (
    <div className="space-y-6">
      <div className="rounded-xl border border-border bg-card overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground tracking-tight">
            {t('What you can expect')}
          </h3>
          <ul className="mt-4 space-y-3" role="list">
            {enterpriseFormBullets.map((item) => (
              <li
                key={item}
                className="flex items-start gap-3 text-[13px] leading-relaxed"
                role="listitem"
              >
                <span
                  aria-hidden
                  className="mt-[7px] size-1 shrink-0 rounded-full bg-muted-foreground/45"
                />
                <span className="text-muted-foreground">{t(item)}</span>
              </li>
            ))}
          </ul>
        </div>
      </div>

      <div className="rounded-xl border border-border bg-card overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground tracking-tight">
            {t('What happens next')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2 leading-relaxed">
            {t(
              'Our enterprise team reviews your requirements and follows up with a tailored proposal, usually within 3 business days.',
            )}
          </p>
        </div>
      </div>

      <div className="rounded-xl border border-border bg-card overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground tracking-tight">
            {t('Learn more')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2 leading-relaxed">
            {t(
              'Compare plans and explore enterprise capabilities on our marketing site.',
            )}
          </p>
        </div>
        <div className="border-t border-border/80" />
        <div className="px-6 py-4 space-y-2">
          <Link
            to="/enterprise"
            className="flex items-center gap-1.5 rounded-lg border border-border bg-muted/30 px-3 py-2.5 text-[12px] font-medium text-foreground hover:bg-muted/50 hover:border-border transition-colors"
          >
            <span>{t('Enterprise overview')}</span>
            <ExternalLink className="h-3 w-3 ms-auto shrink-0 text-muted-foreground" />
          </Link>
          <Link
            to="/pricing"
            className="flex items-center gap-1.5 rounded-lg border border-border bg-muted/30 px-3 py-2.5 text-[12px] font-medium text-foreground hover:bg-muted/50 hover:border-border transition-colors"
          >
            <span>{t('Compare plans')}</span>
            <ExternalLink className="h-3 w-3 ms-auto shrink-0 text-muted-foreground" />
          </Link>
        </div>
      </div>
    </div>
  )

  const mainContent = submitted ? (
    <div
      className={cn(
        MAIN_CONTENT_MIN_HEIGHT,
        'flex flex-col items-center justify-center py-12 px-4',
      )}
    >
      <div className="w-full max-w-xl flex flex-col items-center text-center">
        <div className="mb-6 flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-emerald-500/10">
          <CheckCircle2
            className="h-8 w-8 text-emerald-600 dark:text-emerald-400"
            aria-hidden
          />
        </div>
        <h2 className="text-[18px] font-semibold text-foreground tracking-tight">
          {t('Thank you for your submission')}
        </h2>
        <p className="mt-3 max-w-md text-[13px] text-muted-foreground leading-relaxed">
          {t(
            'Your details have been sent successfully. We usually get back within 3 business days.',
          )}
        </p>
      </div>
      <div className="mt-10 w-full max-w-xl">
        <div className="rounded-xl border border-border bg-card overflow-hidden">
          <div className="px-6 py-5">
            <h3 className="text-[15px] font-semibold text-foreground tracking-tight">
              {t('What happens next')}
            </h3>
            <ul className="mt-4 space-y-3 text-[13px] text-muted-foreground leading-relaxed">
              <li className="flex gap-3 text-start">
                <span className="text-muted-foreground/60 shrink-0">•</span>
                <span>
                  {t('Our enterprise team will review your requirements.')}
                </span>
              </li>
              <li className="flex gap-3 text-start">
                <span className="text-muted-foreground/60 shrink-0">•</span>
                <span>
                  {t('We usually get back within 3 business days at')}{' '}
                  <strong className="text-foreground">{email}</strong>.
                </span>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  ) : (
    <div className={cn(MAIN_CONTENT_MIN_HEIGHT, 'space-y-6')}>
      {submitError ? (
        <WarningAlert title="We couldn't submit your inquiry">
          Something went wrong while sending your request. Please try again in a
          moment.
        </WarningAlert>
      ) : null}

      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Your details')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              'Fields marked with your account email help us connect your inquiry to your Appwrite account.',
            )}
          </p>
        </div>
        <div className="border-t border-border px-6 py-4 space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <Label
                htmlFor="sales-first-name"
                className="text-[13px] font-medium"
              >
                {t('First name')}
              </Label>
              <Input
                id="sales-first-name"
                value={firstName}
                onChange={(e) => setFirstName(e.target.value)}
                placeholder={t('Walter')}
                className="mt-2 h-9"
              />
            </div>
            <div>
              <Label
                htmlFor="sales-last-name"
                className="text-[13px] font-medium"
              >
                {t('Last name')}
              </Label>
              <Input
                id="sales-last-name"
                value={lastName}
                onChange={(e) => setLastName(e.target.value)}
                placeholder={t("O'Brien")}
                className="mt-2 h-9"
              />
            </div>
          </div>

          <div>
            <Label htmlFor="sales-email" className="text-[13px] font-medium">
              {t('Work email address')}
            </Label>
            <Input
              id="sales-email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder={t('walter@company.com')}
              className="mt-2 h-9"
            />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <Label
                htmlFor="sales-company"
                className="text-[13px] font-medium"
              >
                {t('Company name')}
              </Label>
              <Input
                id="sales-company"
                value={companyName}
                onChange={(e) => setCompanyName(e.target.value)}
                placeholder={t('Acme Corp')}
                className="mt-2 h-9"
              />
            </div>
            <div>
              <Label
                htmlFor="sales-company-size"
                className="text-[13px] font-medium"
              >
                {t('Company size')}
              </Label>
              <Select value={companySize} onValueChange={setCompanySize}>
                <SelectTrigger
                  id="sales-company-size"
                  className="mt-2 h-9 w-full"
                >
                  <SelectValue placeholder={t('Select size')} />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={NO_SELECTION_VALUE}>
                    {t('Select size')}
                  </SelectItem>
                  {enterpriseCompanySizeOptions.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {t(option.label)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div>
            <Label htmlFor="sales-website" className="text-[13px] font-medium">
              {t('Company website')}
            </Label>
            <Input
              id="sales-website"
              type="url"
              value={companyWebsite}
              onChange={(e) => setCompanyWebsite(e.target.value)}
              placeholder="https://company.com"
              className="mt-2 h-9"
              autoComplete="url"
            />
            <p
              className={`text-[11px] mt-1 ${
                companyWebsite.trim() && !isFullWebsiteUrl(companyWebsite)
                  ? 'text-destructive'
                  : 'text-muted-foreground'
              }`}
            >
              {t('Enter the full URL, including https://')}
            </p>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <Label
                htmlFor="sales-deployment"
                className="text-[13px] font-medium"
              >
                {t('Preferred deployment')}
              </Label>
              <Select
                value={preferredDeployment}
                onValueChange={setPreferredDeployment}
              >
                <SelectTrigger
                  id="sales-deployment"
                  className="mt-2 h-9 w-full"
                >
                  <SelectValue placeholder={t('Select deployment')} />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={NO_SELECTION_VALUE}>
                    {t('Select deployment')}
                  </SelectItem>
                  {enterprisePreferredDeploymentOptions.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {t(option.label)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label
                htmlFor="sales-timeline"
                className="text-[13px] font-medium"
              >
                {t('Timeline')}
              </Label>
              <Select value={timeline} onValueChange={setTimeline}>
                <SelectTrigger id="sales-timeline" className="mt-2 h-9 w-full">
                  <SelectValue placeholder={t('Select timeline')} />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={NO_SELECTION_VALUE}>
                    {t('Select timeline')}
                  </SelectItem>
                  {enterpriseTimelineOptions.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {t(option.label)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div>
            <Label htmlFor="sales-use-case" className="text-[13px] font-medium">
              {t('Please share more information about your use case')}
            </Label>
            <Textarea
              id="sales-use-case"
              value={useCase}
              onChange={(e) =>
                setUseCase(e.target.value.slice(0, USE_CASE_MAX))
              }
              placeholder={t(
                'Describe your use case and how our Enterprise plan can support it',
              )}
              className="mt-2 min-h-[140px] resize-y"
              maxLength={USE_CASE_MAX}
            />
            <p className="text-[11px] text-muted-foreground mt-1">
              {useCase.length}/{USE_CASE_MAX} {t('characters')}
            </p>
          </div>
        </div>
      </div>
    </div>
  )

  return (
    <WizardLayout
      title={submitted ? t('Inquiry submitted') : t('Contact sales')}
      fullscreen
      useSidebar={!submitted}
      sidebar={submitted ? undefined : sidebar}
      footerAlign="right"
      skipInitialFieldFocus
      initialFocusKey={submitted ? 'submitted' : 'form'}
      footer={
        submitted ? (
          <Button onClick={handleCancel}>{t('Done')}</Button>
        ) : (
          <>
            <Button
              variant="outline"
              onClick={handleCancel}
              disabled={isSubmitting}
            >
              {t('Cancel')}
            </Button>
            <Button
              onClick={handleSubmit}
              disabled={!canSubmit || isSubmitting}
            >
              {t('Submit')}
            </Button>
          </>
        )
      }
    >
      {mainContent}
    </WizardLayout>
  )
}
