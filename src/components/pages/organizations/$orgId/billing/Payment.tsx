/**
 * PaymentModal Component
 *
 * Modal for adding a new payment method using Stripe Elements.
 * Handles card input, US state selection, and payment method creation.
 */

import { useEffect, useRef, useState } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
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
import { toast } from 'sonner'
import type { Stripe, StripeElements, PaymentElement } from '@stripe/stripe-js'
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
} from '@/lib/react-query/hooks'
import type { Models } from '@appwrite.io/console'

interface PaymentModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  organizationId?: string
  isBackup?: boolean
  onSuccess?: () => void
  /** When true, dialog and overlay use z-[9999] so they appear above fullscreen wizards */
  elevatedForWizard?: boolean
}

// US States list for state selector
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

export function PaymentModal({
  open,
  onOpenChange,
  organizationId,
  isBackup = false,
  onSuccess,
  elevatedForWizard = false,
}: PaymentModalProps) {
  const [cardholderName, setCardholderName] = useState('')
  const [selectedState, setSelectedState] = useState<string>('')
  const [showStatePicker, setShowStatePicker] = useState(false)
  const [isStripeLoading, setIsStripeLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [paymentMethodId, setPaymentMethodId] = useState<string | null>(null)
  const [clientSecret, setClientSecret] = useState<string | null>(null)
  const [providerMethodId, setProviderMethodId] = useState<string | null>(null)

  const stripeRef = useRef<Stripe | null>(null)
  const elementsRef = useRef<StripeElements | null>(null)
  const paymentElementRef = useRef<PaymentElement | null>(null)
  const stripeContainerRef = useRef<HTMLDivElement>(null)
  const createPaymentMethodMutationRef = useRef(
    null as ReturnType<typeof useCreatePaymentMethod> | null,
  )

  const createPaymentMethodMutation = useCreatePaymentMethod()
  createPaymentMethodMutationRef.current = createPaymentMethodMutation
  const setPaymentMethodProviderMutation = useSetPaymentMethodProvider()
  const setDefaultPaymentMethodMutation =
    useSetOrganizationDefaultPaymentMethod()
  const setBackupPaymentMethodMutation = useSetOrganizationBackupPaymentMethod()
  const { paymentMethods: allPaymentMethods } = usePaymentMethods()

  const { theme } = useTheme()

  const stripePublishableKey =
    typeof window !== 'undefined'
      ? import.meta.env.VITE_STRIPE_PUBLISHABLE_KEY ||
        (window as Window & { __STRIPE_PUBLISHABLE_KEY__?: string })
          .__STRIPE_PUBLISHABLE_KEY__
      : undefined
  // Allow Stripe in development or on cloud
  const hasStripePublicKey = !!stripePublishableKey

  // Initialize Stripe when modal opens
  useEffect(() => {
    if (!open || !hasStripePublicKey) {
      // Clean up when modal closes
      if (paymentElementRef.current) {
        paymentElementRef.current = null
      }
      if (elementsRef.current) {
        elementsRef.current = null
      }
      return
    }

    let mounted = true
    let currentPaymentElement: PaymentElement | null = null
    let hasInitialized = false
    let containerForCleanup: HTMLDivElement | null = null

    async function initializeStripe() {
      // Prevent multiple initializations
      if (hasInitialized) return
      hasInitialized = true

      try {
        setIsStripeLoading(true)
        setError(null)

        // Check for existing incomplete payment method (read once; do not depend on list in effect deps to avoid re-init when query invalidates after create)
        const existingIncomplete = allPaymentMethods?.find(
          (method: Models.PaymentMethod) =>
            method.clientSecret && !method.providerMethodId,
        )

        let paymentMethod: Models.PaymentMethod
        let secret: string

        if (existingIncomplete) {
          // Reuse incomplete payment method
          paymentMethod = existingIncomplete
          secret = existingIncomplete.clientSecret!
        } else {
          // Create new payment method (use ref so effect does not depend on mutation object)
          paymentMethod =
            await createPaymentMethodMutationRef.current!.mutateAsync()
          secret = paymentMethod.clientSecret!
        }

        if (!mounted) return

        setPaymentMethodId(paymentMethod.$id)
        setClientSecret(secret)

        // Initialize Stripe
        const stripe = await getStripeInstance(stripePublishableKey)
        if (!stripe || !mounted) {
          setIsStripeLoading(false)
          return
        }

        stripeRef.current = stripe

        // Create Elements (compute appearance inside effect so we don't depend on a new object ref every render)
        const elements = stripe.elements({
          clientSecret: secret,
          appearance: getStripeAppearanceFromTheme(theme),
        })

        elementsRef.current = elements

        // Create Payment Element
        const paymentElement = elements.create('payment')
        currentPaymentElement = paymentElement
        paymentElementRef.current = paymentElement

        // Wait for container to be available in DOM
        // Use a small delay to ensure React has rendered the container
        await new Promise((resolve) => setTimeout(resolve, 50))

        const container = stripeContainerRef.current
        if (!mounted || !container) {
          setIsStripeLoading(false)
          return
        }

        // Capture for cleanup so we unmount from the same node we mounted to
        containerForCleanup = container

        // Mount Payment Element
        try {
          paymentElement.mount(container)
          if (mounted) {
            setIsStripeLoading(false)
          }
        } catch (mountError) {
          if (mounted) {
            setError('Failed to mount payment form. Please try again.')
            setIsStripeLoading(false)
            console.error('Stripe mount error:', mountError)
          }
        }
      } catch (err) {
        if (mounted) {
          const errorMessage =
            err instanceof Error
              ? err.message
              : 'Failed to initialize payment form'
          setError(errorMessage)
          setIsStripeLoading(false)
          console.error('Stripe initialization error:', err)
        }
      }
    }

    // Initialize after a small delay to ensure container is rendered
    // Use the payment methods from the hook (already fetched)
    const timeoutId = setTimeout(() => {
      initializeStripe()
    }, 100)

    return () => {
      clearTimeout(timeoutId)
      hasInitialized = false
      mounted = false

      // Clean up Stripe Elements
      // Use requestAnimationFrame to ensure this happens in the right order
      requestAnimationFrame(() => {
        if (currentPaymentElement && containerForCleanup?.parentNode) {
          try {
            currentPaymentElement.unmount()
          } catch {
            // Silently ignore - React may have already cleaned up
          }
        }
        currentPaymentElement = null
        containerForCleanup = null
        paymentElementRef.current = null
        if (elementsRef.current) {
          elementsRef.current = null
        }
      })
    }
    // Intentionally omit allPaymentMethods and createPaymentMethodMutation:
    // - allPaymentMethods changes when createPaymentMethod invalidates the query, which would re-run this effect and unmount/remount Stripe (form "reload")
    // - createPaymentMethodMutation object reference is unstable; we use createPaymentMethodMutationRef.current inside the effect
    // - appearance is computed inside the effect from theme; we depend on theme (string) not appearance (new object every render would cause endless re-init loop)
  }, [open, hasStripePublicKey, stripePublishableKey, theme])

  // Reset form when modal closes
  useEffect(() => {
    if (!open) {
      // Reset form state
      setCardholderName('')
      setSelectedState('')
      setShowStatePicker(false)
      setError(null)
      setPaymentMethodId(null)
      setClientSecret(null)
      setProviderMethodId(null)
      setIsStripeLoading(true)

      // Note: Stripe Elements cleanup is handled in the initialization effect's cleanup
      // We don't clean up here to avoid conflicts with React's DOM removal
    }
  }, [open])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    if (!cardholderName.trim()) {
      setError('Please enter a cardholder name')
      return
    }

    if (showStatePicker && !selectedState) {
      setError('Please select a state')
      return
    }

    if (!paymentMethodId) {
      setError('Payment form not ready. Please try again.')
      return
    }

    try {
      setError(null)

      // If we already confirmed the card with Stripe on a previous submit
      // (state-picker flow), the setup intent is consumed — skip the Stripe
      // step entirely and just finish by submitting the state to our backend.
      let resolvedProviderMethodId = providerMethodId

      if (!resolvedProviderMethodId) {
        if (!stripeRef.current || !elementsRef.current || !clientSecret) {
          setError('Payment form not ready. Please try again.')
          return
        }

        // Submit Stripe Elements
        await elementsRef.current.submit()

        // Confirm setup intent
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
                billing_details: {
                  name: cardholderName.trim(),
                },
              },
              expand: ['payment_method'],
            },
            redirect: 'if_required',
          })

        if (stripeError) {
          throw new Error(stripeError.message)
        }

        // For SCA/3DS cards the SDK may hand control back with
        // status=requires_action if the inline modal couldn't complete — run
        // handleNextAction explicitly and pick up the resulting intent.
        let finalIntent = setupIntent
        if (finalIntent?.status === 'requires_action') {
          const { setupIntent: next, error: actionError } =
            await stripeRef.current.handleNextAction({ clientSecret })
          if (actionError) {
            throw new Error(actionError.message ?? 'Authentication failed')
          }
          finalIntent = next ?? finalIntent
        }

        if (!finalIntent || finalIntent.status !== 'succeeded') {
          const reason =
            finalIntent?.last_setup_error?.message ??
            (finalIntent?.status === 'requires_payment_method'
              ? 'The card was declined or authentication was cancelled. Please try again or use a different card.'
              : `Payment setup did not complete (status: ${finalIntent?.status ?? 'unknown'}).`)
          throw new Error(reason)
        }

        const stripePaymentMethod = finalIntent.payment_method
        if (!stripePaymentMethod) {
          throw new Error('Invalid payment method response')
        }

        // After handleNextAction the payment_method is a bare id string (no
        // expand) — only the confirmSetup path has the full card object.
        resolvedProviderMethodId =
          typeof stripePaymentMethod === 'string'
            ? stripePaymentMethod
            : stripePaymentMethod.id
        const pmCard =
          typeof stripePaymentMethod === 'object'
            ? stripePaymentMethod.card
            : null

        // Check if US card requires state — pause, keep the confirmed provider
        // id so the next submit only updates state via our backend, not Stripe.
        if (pmCard?.country === 'US' && !showStatePicker) {
          setProviderMethodId(resolvedProviderMethodId)
          setShowStatePicker(true)
          return
        }
      }

      // Link payment method to Appwrite
      await setPaymentMethodProviderMutation.mutateAsync({
        paymentMethodId,
        providerMethodId: resolvedProviderMethodId,
        name: cardholderName.trim(),
        state: selectedState || undefined,
      })

      // Assign to organization if provided
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
      }

      toast.success(
        organizationId
          ? `Payment method has been added to your organization`
          : 'A new payment method has been added to your account',
      )

      onOpenChange(false)
      onSuccess?.()
    } catch (err) {
      setError(
        err instanceof Error ? err.message : 'Failed to add payment method',
      )
    }
  }

  const isLoading =
    isStripeLoading ||
    createPaymentMethodMutation.isPending ||
    setPaymentMethodProviderMutation.isPending ||
    setDefaultPaymentMethodMutation.isPending ||
    setBackupPaymentMethodMutation.isPending

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className={cn('sm:max-w-md p-0', elevatedForWizard && 'z-[9999]')}
        overlayClassName={elevatedForWizard ? 'z-[9999]' : undefined}
      >
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Add payment method</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {!hasStripePublicKey
              ? 'Payment method setup is not available. Please contact support.'
              : showStatePicker
                ? 'Please select your state to complete the payment method setup.'
                : 'Enter your card details to add a new payment method.'}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        {!hasStripePublicKey ? (
          <>
            <div className="px-6 pb-4 pt-0">
              <div className="rounded-md bg-yellow-500/10 border border-yellow-500/20 px-3 py-2">
                <p className="text-[12px] text-yellow-600 dark:text-yellow-400">
                  Stripe payment processing is not configured. Please ensure
                  VITE_STRIPE_PUBLISHABLE_KEY is set in your environment.
                </p>
              </div>
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
              >
                Close
              </Button>
            </div>
          </>
        ) : (
          <form onSubmit={handleSubmit}>
            <div className="px-6 pb-4 pt-0 space-y-4">
              {!showStatePicker ? (
                <>
                  <div className="space-y-2">
                    <Label htmlFor="cardholder-name" className="text-[13px]">
                      Cardholder name
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

                  <div>
                    {/* Use key to force React to recreate container on open/close */}
                    <div
                      key={
                        open
                          ? `stripe-${paymentMethodId || 'new'}`
                          : 'stripe-closed'
                      }
                      className="min-h-[200px] relative"
                    >
                      {/* Always render container, show loading overlay */}
                      <div ref={stripeContainerRef} className="min-h-[200px]" />
                      {isStripeLoading && (
                        <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-background/80">
                          <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                          <p className="text-[12px] text-muted-foreground">
                            Loading payment form...
                          </p>
                        </div>
                      )}
                    </div>
                  </div>
                </>
              ) : (
                <div className="space-y-2">
                  <Label htmlFor="state" className="text-[13px]">
                    State
                  </Label>
                  <Select
                    value={selectedState}
                    onValueChange={setSelectedState}
                    disabled={isLoading}
                  >
                    <SelectTrigger id="state" className="h-9 text-[13px]">
                      <SelectValue placeholder="Select a state" />
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

              {error && (
                <div className="rounded-md bg-red-500/10 border border-red-500/20 px-3 py-2">
                  <p className="text-[12px] text-red-600 dark:text-red-400">
                    {error}
                  </p>
                </div>
              )}
            </div>

            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
                disabled={isLoading}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={
                  isLoading ||
                  !cardholderName.trim() ||
                  (showStatePicker && !selectedState)
                }
              >
                Add
              </Button>
            </div>
          </form>
        )}
      </DialogContent>
    </Dialog>
  )
}
