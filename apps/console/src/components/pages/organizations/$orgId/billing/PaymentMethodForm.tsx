import { useEffect, useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Loader2 } from 'lucide-react'
import { getRuntimeConfig } from '@/lib/runtime-config'
import {
  warningAlertContainerClassName,
  warningAlertTextClassName,
} from '@/components/global/shared/WarningAlert'
import { toast } from 'sonner'
import type {
  PaymentMethod as StripePaymentMethod,
  Stripe,
  StripeElements,
  PaymentElement,
} from '@stripe/stripe-js'
import { cn } from '@/lib/utils'
import {
  getStripeInstance,
  getStripeAppearanceFromTheme,
} from '@/lib/utils/stripe'
import { useTheme } from 'next-themes'
import {
  useCreatePaymentMethod,
  useSetPaymentMethodProvider,
  useSetOrganizationDefaultPaymentMethod,
  useSetOrganizationBackupPaymentMethod,
  usePaymentMethods,
  useLocale,
} from '@/lib/react-query/hooks'
import type { Models } from '@appwrite.io/console'
import { maskCardNumber } from './utils'
import { useT } from '@/lib/i18n/translate'

export interface PaymentMethodFormProps {
  /** Whether the enclosing surface (dialog or inline panel) is visible.
   * Init runs when this becomes true; state resets when it becomes false. */
  open: boolean
  organizationId?: string
  isBackup?: boolean
  onSuccess?: () => void
  onCancel?: () => void
  /** Adjusts button sizing and footer layout for the two call sites. */
  variant?: 'dialog' | 'inline'
}

const US_STATES = [
  { value: 'AL', label: 'Alabama' },
  { value: 'AK', label: 'Alaska' },
  { value: 'AZ', label: 'Arizona' },
  { value: 'AR', label: 'Arkansas' },
  { value: 'CA', label: 'California' },
  { value: 'CO', label: 'Colorado' },
  { value: 'CT', label: 'Connecticut' },
  { value: 'DE', label: 'Delaware' },
  { value: 'FL', label: 'Florida' },
  { value: 'GA', label: 'Georgia' },
  { value: 'HI', label: 'Hawaii' },
  { value: 'ID', label: 'Idaho' },
  { value: 'IL', label: 'Illinois' },
  { value: 'IN', label: 'Indiana' },
  { value: 'IA', label: 'Iowa' },
  { value: 'KS', label: 'Kansas' },
  { value: 'KY', label: 'Kentucky' },
  { value: 'LA', label: 'Louisiana' },
  { value: 'ME', label: 'Maine' },
  { value: 'MD', label: 'Maryland' },
  { value: 'MA', label: 'Massachusetts' },
  { value: 'MI', label: 'Michigan' },
  { value: 'MN', label: 'Minnesota' },
  { value: 'MS', label: 'Mississippi' },
  { value: 'MO', label: 'Missouri' },
  { value: 'MT', label: 'Montana' },
  { value: 'NE', label: 'Nebraska' },
  { value: 'NV', label: 'Nevada' },
  { value: 'NH', label: 'New Hampshire' },
  { value: 'NJ', label: 'New Jersey' },
  { value: 'NM', label: 'New Mexico' },
  { value: 'NY', label: 'New York' },
  { value: 'NC', label: 'North Carolina' },
  { value: 'ND', label: 'North Dakota' },
  { value: 'OH', label: 'Ohio' },
  { value: 'OK', label: 'Oklahoma' },
  { value: 'OR', label: 'Oregon' },
  { value: 'PA', label: 'Pennsylvania' },
  { value: 'RI', label: 'Rhode Island' },
  { value: 'SC', label: 'South Carolina' },
  { value: 'SD', label: 'South Dakota' },
  { value: 'TN', label: 'Tennessee' },
  { value: 'TX', label: 'Texas' },
  { value: 'UT', label: 'Utah' },
  { value: 'VT', label: 'Vermont' },
  { value: 'VA', label: 'Virginia' },
  { value: 'WA', label: 'Washington' },
  { value: 'WV', label: 'West Virginia' },
  { value: 'WI', label: 'Wisconsin' },
  { value: 'WY', label: 'Wyoming' },
]

interface CardPreview {
  brand: string
  last4: string
  expMonth: number
  expYear: number
}

export function PaymentMethodForm({
  open,
  organizationId,
  isBackup = false,
  onSuccess,
  onCancel,
  variant = 'dialog',
}: PaymentMethodFormProps) {
  const t = useT()
  const { data: localeData } = useLocale()
  const isUsLocale =
    localeData?.countryCode?.trim().toUpperCase() === 'US'
  const isUsLocaleRef = useRef(isUsLocale)
  isUsLocaleRef.current = isUsLocale
  const [cardholderName, setCardholderName] = useState('')
  const [selectedState, setSelectedState] = useState<string>('')
  // Post-Stripe confirm step (US state and/or recovery name entry).
  const [showConfirmStep, setShowConfirmStep] = useState(false)
  // US state is required only when locale.get() reports countryCode === 'US'
  // (IP-derived, already fetched for the console). Card BIN and the Stripe
  // country dropdown are not used for this gate.
  const [requiresState, setRequiresState] = useState(false)
  // Recovery: SetupIntent already succeeded but Appwrite link never landed.
  // Card form is skipped; cardholder name must be collected on the confirm step.
  const [isRecovery, setIsRecovery] = useState(false)
  const [isStripeLoading, setIsStripeLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [paymentMethodId, setPaymentMethodId] = useState<string | null>(null)
  const [clientSecret, setClientSecret] = useState<string | null>(null)
  const [providerMethodId, setProviderMethodId] = useState<string | null>(null)
  const [addedCardPreview, setAddedCardPreview] = useState<CardPreview | null>(
    null,
  )

  const stripeRef = useRef<Stripe | null>(null)
  const elementsRef = useRef<StripeElements | null>(null)
  const paymentElementRef = useRef<PaymentElement | null>(null)
  const stripeContainerRef = useRef<HTMLDivElement>(null)
  const createPaymentMethodMutationRef = useRef(
    null as ReturnType<typeof useCreatePaymentMethod> | null,
  )
  // Tracks the currently in-flight submit so we can abort it when the user
  // closes/cancels the form mid-way. Stripe.js calls don't accept signals,
  // so aborting only prevents post-await state updates - but that's enough
  // to stop "form reset" races and stale error toasts.
  const submitAbortRef = useRef<AbortController | null>(null)

  const createPaymentMethodMutation = useCreatePaymentMethod()
  createPaymentMethodMutationRef.current = createPaymentMethodMutation
  const setPaymentMethodProviderMutation = useSetPaymentMethodProvider()
  const setDefaultPaymentMethodMutation =
    useSetOrganizationDefaultPaymentMethod()
  const setBackupPaymentMethodMutation = useSetOrganizationBackupPaymentMethod()
  const { paymentMethods: allPaymentMethods } = usePaymentMethods({
    enabled: open,
  })

  const { theme } = useTheme()

  const stripePublishableKey =
    typeof window !== 'undefined'
      ? getRuntimeConfig().stripePublishableKey ||
        (window as Window & { __STRIPE_PUBLISHABLE_KEY__?: string })
          .__STRIPE_PUBLISHABLE_KEY__
      : undefined
  const hasStripePublicKey = !!stripePublishableKey

  // Initialize Stripe on open / mount.
  useEffect(() => {
    if (!open || !hasStripePublicKey) {
      if (paymentElementRef.current) paymentElementRef.current = null
      if (elementsRef.current) elementsRef.current = null
      return
    }

    let mounted = true
    let currentPaymentElement: PaymentElement | null = null
    let hasInitialized = false
    let containerForCleanup: HTMLDivElement | null = null

    async function initializeStripe() {
      if (hasInitialized) return
      hasInitialized = true

      try {
        setIsStripeLoading(true)
        setError(null)

        // Read the list once - depending on it in effect deps would re-init the
        // form every time the query invalidates.
        const existingIncomplete = allPaymentMethods?.find(
          (method: Models.PaymentMethod) =>
            method.clientSecret && !method.providerMethodId,
        )

        let paymentMethod: Models.PaymentMethod
        let secret: string

        if (existingIncomplete) {
          paymentMethod = existingIncomplete
          secret = existingIncomplete.clientSecret!
        } else {
          paymentMethod =
            await createPaymentMethodMutationRef.current!.mutateAsync()
          secret = paymentMethod.clientSecret!
        }
        if (!mounted) return

        setPaymentMethodId(paymentMethod.$id)
        setClientSecret(secret)

        const stripe = await getStripeInstance(stripePublishableKey)
        if (!stripe || !mounted) {
          setIsStripeLoading(false)
          return
        }
        stripeRef.current = stripe

        // If a previous attempt already drove the SetupIntent to succeeded
        // (Appwrite PM exists with clientSecret but no providerMethodId
        // because the backend link never landed), skip the card form and
        // show a confirm step so the user can enter a name and finish linking.
        // Only require US state when the user's locale country is US.
        const { setupIntent: existingIntent } =
          await stripe.retrieveSetupIntent(secret)
        if (!mounted) return
        if (existingIntent?.status === 'succeeded') {
          const pm = existingIntent.payment_method
          const pmId = typeof pm === 'string' ? pm : (pm?.id ?? null)
          const pmCard = typeof pm === 'object' && pm !== null ? pm.card : null
          if (pmId) {
            setProviderMethodId(pmId)
            // Appwrite PM stays empty until setPaymentMethodProvider lands, so
            // the preview is often missing - the view degrades to a
            // "card was entered previously" message.
            if (pmCard?.last4) {
              setAddedCardPreview({
                brand: pmCard.brand ?? '',
                last4: pmCard.last4,
                expMonth: pmCard.exp_month ?? 0,
                expYear: pmCard.exp_year ?? 0,
              })
            }
            setIsRecovery(true)
            setRequiresState(isUsLocaleRef.current)
            setShowConfirmStep(true)
            setIsStripeLoading(false)
            return
          }
        }

        const elements = stripe.elements({
          clientSecret: secret,
          appearance: getStripeAppearanceFromTheme(theme),
        })
        elementsRef.current = elements

        const paymentElement = elements.create('payment')
        currentPaymentElement = paymentElement
        paymentElementRef.current = paymentElement

        // Wait for the container ref to be attached by React.
        await new Promise((resolve) => setTimeout(resolve, 50))
        const container = stripeContainerRef.current
        if (!mounted || !container) {
          setIsStripeLoading(false)
          return
        }
        containerForCleanup = container

        try {
          paymentElement.mount(container)
          if (mounted) setIsStripeLoading(false)
        } catch (mountError) {
          if (mounted) {
            setError(t('Failed to mount payment form. Please try again.'))
            setIsStripeLoading(false)
            console.error('Stripe mount error:', mountError)
          }
        }
      } catch (err) {
        if (mounted) {
          setError(
            err instanceof Error
              ? err.message
              : t('Failed to initialize payment form'),
          )
          setIsStripeLoading(false)
          console.error('Stripe initialization error:', err)
        }
      }
    }

    const timeoutId = setTimeout(initializeStripe, 100)

    return () => {
      clearTimeout(timeoutId)
      hasInitialized = false
      mounted = false
      requestAnimationFrame(() => {
        if (currentPaymentElement && containerForCleanup?.parentNode) {
          try {
            currentPaymentElement.unmount()
          } catch {
            // React may have already cleaned up the node.
          }
        }
        currentPaymentElement = null
        containerForCleanup = null
        paymentElementRef.current = null
        if (elementsRef.current) elementsRef.current = null
      })
    }
    // See comment above - we intentionally exclude allPaymentMethods and the
    // mutation object to keep the Stripe form from reloading when the list
    // refetches.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, hasStripePublicKey, stripePublishableKey, theme])

  // Reset internal state when the enclosing surface closes. Also abort any
  // in-flight submit so its continuation doesn't fire setError / toast /
  // onSuccess against a closed form.
  useEffect(() => {
    if (!open) {
      submitAbortRef.current?.abort()
      submitAbortRef.current = null
      setCardholderName('')
      setSelectedState('')
      setShowConfirmStep(false)
      setRequiresState(false)
      setIsRecovery(false)
      setError(null)
      setPaymentMethodId(null)
      setClientSecret(null)
      setProviderMethodId(null)
      setAddedCardPreview(null)
      setIsStripeLoading(true)
    }
  }, [open])

  // Abort any pending submit on unmount too (covers components that don't
  // flip `open` - e.g. InlinePaymentForm removed from the tree).
  useEffect(() => {
    return () => {
      submitAbortRef.current?.abort()
      submitAbortRef.current = null
    }
  }, [])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    if (!cardholderName.trim()) {
      setError(t('Please enter a cardholder name'))
      return
    }
    if (showConfirmStep && requiresState && !selectedState) {
      setError(t('Please select a state'))
      return
    }
    if (!paymentMethodId) {
      setError(t('Payment form not ready. Please try again.'))
      return
    }

    submitAbortRef.current?.abort()
    const controller = new AbortController()
    submitAbortRef.current = controller
    const { signal } = controller
    const aborted = () => signal.aborted

    try {
      setError(null)

      // If a previous submit already confirmed the card with Stripe (state
      // picker flow, or a post-Stripe backend failure), the setup intent is
      // consumed - skip Stripe entirely and only send the state to our backend.
      let resolvedProviderMethodId = providerMethodId

      if (!resolvedProviderMethodId) {
        if (!stripeRef.current || !elementsRef.current || !clientSecret) {
          setError(t('Payment form not ready. Please try again.'))
          return
        }

        // Calling confirmSetup on an already-succeeded intent returns
        // setup_intent_unexpected_state. Check current state first and reuse
        // the PM if Stripe already has it.
        const { setupIntent: existingIntent } =
          await stripeRef.current.retrieveSetupIntent(clientSecret)
        if (aborted()) return

        let finalIntent = existingIntent ?? null
        // Card object captured from the pre-action SetupIntent. handleNextAction
        // returns a bare payment_method id, so keep the expanded card for the
        // confirm-step preview.
        let initialPmCard: StripePaymentMethod.Card | null = null

        if (finalIntent?.status !== 'succeeded') {
          await elementsRef.current.submit()
          if (aborted()) return

          const { setupIntent, error: stripeError } =
            await stripeRef.current.confirmSetup({
              elements: elementsRef.current,
              clientSecret,
              confirmParams: {
                return_url:
                  typeof window !== 'undefined'
                    ? `${window.location.origin}${window.location.pathname}`
                    : '',
                payment_method_data: {
                  billing_details: { name: cardholderName.trim() },
                },
                expand: ['payment_method'],
              },
              redirect: 'if_required',
            })
          if (aborted()) return

          if (stripeError) throw new Error(stripeError.message)

          // confirmSetup honours `expand: ['payment_method']`, so the initial
          // intent has the full card object. handleNextAction strips that
          // expansion and returns a bare id, which would hide the card's
          // country from us - capture the expanded card now and use it as a
          // fallback after the 3DS step.
          if (
            setupIntent?.payment_method &&
            typeof setupIntent.payment_method === 'object'
          ) {
            initialPmCard = setupIntent.payment_method.card ?? null
          }

          // If Stripe couldn't complete the SCA inline it hands control back
          // with status=requires_action - run handleNextAction explicitly.
          finalIntent = setupIntent
          if (finalIntent?.status === 'requires_action') {
            const { setupIntent: next, error: actionError } =
              await stripeRef.current.handleNextAction({ clientSecret })
            if (aborted()) return
            if (actionError) {
              throw new Error(actionError.message ?? t('Authentication failed'))
            }
            finalIntent = next ?? finalIntent
          }
        }

        if (!finalIntent || finalIntent.status !== 'succeeded') {
          throw new Error(
            finalIntent?.last_setup_error?.message ??
              (finalIntent?.status === 'requires_payment_method'
                ? t('The card was declined or authentication was cancelled. Please try again or use a different card.')
                : `${t('Payment setup did not complete')} (status: ${finalIntent?.status ?? 'unknown'}).`),
          )
        }

        const stripePaymentMethod = finalIntent.payment_method
        if (!stripePaymentMethod) {
          throw new Error(t('Invalid payment method response'))
        }

        // After handleNextAction / retrieveSetupIntent the payment_method is a
        // bare id string. Only the confirmSetup + expand path has the card,
        // which we already captured into `initialPmCard` above.
        resolvedProviderMethodId =
          typeof stripePaymentMethod === 'string'
            ? stripePaymentMethod
            : stripePaymentMethod.id
        const pmCard =
          typeof stripePaymentMethod === 'object' && stripePaymentMethod.card
            ? stripePaymentMethod.card
            : initialPmCard

        // Cache the card preview for the confirm step whenever we have
        // expanded card info.
        if (pmCard?.last4) {
          setAddedCardPreview({
            brand: pmCard.brand ?? '',
            last4: pmCard.last4,
            expMonth: pmCard.exp_month ?? 0,
            expYear: pmCard.exp_year ?? 0,
          })
        }

        // Only collect US state when locale.get() countryCode is US.
        // Non-US IPs skip this step even if Stripe reports a US-issued card.
        if (isUsLocaleRef.current && !showConfirmStep) {
          setProviderMethodId(resolvedProviderMethodId)
          setRequiresState(true)
          setShowConfirmStep(true)
          return
        }
      }

      await setPaymentMethodProviderMutation.mutateAsync({
        paymentMethodId,
        providerMethodId: resolvedProviderMethodId,
        name: cardholderName.trim(),
        state: selectedState || undefined,
      })
      if (aborted()) return

      if (organizationId) {
        if (isBackup) {
          await setBackupPaymentMethodMutation.mutateAsync({
            organizationId,
            paymentMethodId,
          })
        } else {
          await setDefaultPaymentMethodMutation.mutateAsync({
            organizationId,
            paymentMethodId,
          })
        }
        if (aborted()) return
      }

      toast.success(
        organizationId
          ? t('Payment method has been added to your organization')
          : t('A new payment method has been added to your account'),
      )
      onSuccess?.()
    } catch (err) {
      if (aborted()) return
      setError(
        err instanceof Error ? err.message : t('Failed to add payment method'),
      )
    } finally {
      if (submitAbortRef.current === controller) {
        submitAbortRef.current = null
      }
    }
  }

  const isLoading =
    isStripeLoading ||
    createPaymentMethodMutation.isPending ||
    setPaymentMethodProviderMutation.isPending ||
    setDefaultPaymentMethodMutation.isPending ||
    setBackupPaymentMethodMutation.isPending

  if (!hasStripePublicKey) {
    return (
      <div
        className={cn(
          'space-y-4',
          variant === 'dialog' ? 'px-6 pb-4' : undefined,
        )}
      >
        <div className="rounded-md bg-yellow-500/10 border border-yellow-500/20 px-3 py-2">
          <p className="text-[12px] text-yellow-600 dark:text-yellow-400">
            {t('Stripe payment processing is not configured. Please ensure VITE_STRIPE_PUBLISHABLE_KEY is set in your environment.')}
          </p>
        </div>
        {onCancel && (
          <div
            className={cn(
              'flex justify-end gap-2',
              variant === 'dialog' &&
                '-mx-6 -mb-4 px-6 py-4 border-t border-border bg-muted/30',
            )}
          >
            <Button
              type="button"
              variant="outline"
              size={variant === 'inline' ? 'sm' : undefined}
              onClick={onCancel}
            >
              {t('Close')}
            </Button>
          </div>
        )}
      </div>
    )
  }

  const buttonSize = variant === 'inline' ? 'sm' : undefined
  const buttonClass = variant === 'inline' ? 'h-8 text-[13px]' : undefined

  return (
    <form
      onSubmit={handleSubmit}
      className={cn(
        'space-y-4',
        variant === 'dialog' ? 'px-6 pb-4' : undefined,
      )}
    >
      {!showConfirmStep ? (
        <>
          <div className="space-y-2">
            <Label htmlFor="cardholder-name" className="text-[13px]">
              {t('Cardholder name')} <span className="text-destructive">*</span>
            </Label>
            <Input
              id="cardholder-name"
              value={cardholderName}
              onChange={(e) => setCardholderName(e.target.value)}
              placeholder="John Doe"
              className="h-9 text-[13px]"
              disabled={isLoading}
            />
          </div>

          <div
            key={open ? `stripe-${paymentMethodId || 'new'}` : 'stripe-closed'}
            className="min-h-[200px] relative"
          >
            <div ref={stripeContainerRef} className="min-h-[200px]" />
            {isStripeLoading && (
              <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-background/80">
                <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                <p className="text-[12px] text-muted-foreground">
                  {t('Loading payment form...')}
                </p>
              </div>
            )}
          </div>
        </>
      ) : (
        <div className="space-y-4">
          {addedCardPreview ? (
            <div className="rounded-md border border-border bg-muted/30 px-3 py-2.5">
              <p className="text-[11px] font-medium text-muted-foreground uppercase tracking-wide">
                {t('Card added')}
              </p>
              <p className="text-[13px] text-foreground mt-1 capitalize">
                {addedCardPreview.brand}{' '}
                {maskCardNumber(addedCardPreview.last4)}
              </p>
              {addedCardPreview.expMonth > 0 &&
                addedCardPreview.expYear > 0 && (
                  <p className="text-[12px] text-muted-foreground mt-0.5">
                    {t('Expires')} {String(addedCardPreview.expMonth).padStart(2, '0')}
                    /{String(addedCardPreview.expYear).slice(-2)}
                  </p>
                )}
            </div>
          ) : (
            <div className="rounded-md border border-border bg-muted/30 px-3 py-2.5">
              <p className="text-[11px] font-medium text-muted-foreground uppercase tracking-wide">
                {t('Card added')}
              </p>
              <p className="text-[13px] text-foreground mt-1">
                {t('A card was entered in a previous attempt. Complete the details below to finish adding it.')}
              </p>
            </div>
          )}

          {/* Card form is skipped on the recovery path, so ask for the
              cardholder name here so the backend link has a value to store. */}
          {isRecovery && (
            <div className="space-y-2">
              <Label htmlFor="cardholder-name-recover" className="text-[13px]">
                {t('Cardholder name')} <span className="text-destructive">*</span>
              </Label>
              <Input
                id="cardholder-name-recover"
                value={cardholderName}
                onChange={(e) => setCardholderName(e.target.value)}
                placeholder="John Doe"
                className="h-9 text-[13px]"
                disabled={isLoading}
              />
            </div>
          )}

          {requiresState && (
            <div className="space-y-2">
              <Label htmlFor="state" className="text-[13px]">
                {t('State')} <span className="text-destructive">*</span>
              </Label>
              <Select
                value={selectedState}
                onValueChange={setSelectedState}
                disabled={isLoading}
              >
                <SelectTrigger id="state" className="h-9 text-[13px]">
                  <SelectValue placeholder={t('Select a state')} />
                </SelectTrigger>
                <SelectContent>
                  {US_STATES.map((state) => (
                    <SelectItem key={state.value} value={state.value}>
                      {state.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}
        </div>
      )}

      {error && (
        <div
          className={cn(
            'rounded-lg border px-3 py-2',
            warningAlertContainerClassName,
          )}
        >
          <p className={warningAlertTextClassName}>{error}</p>
        </div>
      )}

      <div
        className={cn(
          'flex items-center justify-end gap-2',
          variant === 'dialog' &&
            '-mx-6 -mb-4 px-6 py-4 border-t border-border bg-muted/30 flex-col-reverse sm:flex-row sm:justify-end',
        )}
      >
        {onCancel && (
          <Button
            type="button"
            variant="outline"
            size={buttonSize}
            className={buttonClass}
            onClick={onCancel}
            disabled={isLoading}
          >
            {t('Cancel')}
          </Button>
        )}
        <Button
          type="submit"
          size={buttonSize}
          className={buttonClass}
          disabled={
            isLoading ||
            !cardholderName.trim() ||
            (showConfirmStep && requiresState && !selectedState)
          }
        >
          {showConfirmStep ? t('Save') : t('Add')}
        </Button>
      </div>
    </form>
  )
}
