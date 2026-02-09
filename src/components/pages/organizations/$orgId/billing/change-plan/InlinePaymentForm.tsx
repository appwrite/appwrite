/**
 * InlinePaymentForm Component
 *
 * Inline form for adding a new payment method using Stripe Elements.
 * Used in the plan change wizard.
 */

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
import { Loader2, X } from 'lucide-react'
import { toast } from 'sonner'
import type { Stripe, StripeElements, PaymentElement } from '@stripe/stripe-js'
import {
  getStripeInstance,
  getStripeAppearanceFromTheme,
} from '@/lib/utils/stripe'
import { useTheme } from 'next-themes'
import {
  useCreatePaymentMethod,
  useSetPaymentMethodProvider,
  useSetOrganizationDefaultPaymentMethod,
  usePaymentMethods,
} from '@/lib/react-query/hooks'
import type { Models } from '@appwrite.io/console'

interface InlinePaymentFormProps {
  organizationId?: string
  onSuccess?: () => void
  onCancel?: () => void
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

export function InlinePaymentForm({
  organizationId,
  onSuccess,
  onCancel,
}: InlinePaymentFormProps) {
  const [cardholderName, setCardholderName] = useState('')
  const [selectedState, setSelectedState] = useState<string>('')
  const [showStatePicker, setShowStatePicker] = useState(false)
  const [isStripeLoading, setIsStripeLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [paymentMethodId, setPaymentMethodId] = useState<string | null>(null)
  const [clientSecret, setClientSecret] = useState<string | null>(null)

  const stripeRef = useRef<Stripe | null>(null)
  const elementsRef = useRef<StripeElements | null>(null)
  const paymentElementRef = useRef<PaymentElement | null>(null)
  const stripeContainerRef = useRef<HTMLDivElement>(null)

  const createPaymentMethodMutation = useCreatePaymentMethod()
  const setPaymentMethodProviderMutation = useSetPaymentMethodProvider()
  const setDefaultPaymentMethodMutation =
    useSetOrganizationDefaultPaymentMethod()
  const { paymentMethods: allPaymentMethods } = usePaymentMethods()

  const { theme } = useTheme()
  const appearance = getStripeAppearanceFromTheme(theme)

  // Check if Stripe is available
  const stripePublishableKey =
    typeof window !== 'undefined'
      ? import.meta.env.VITE_STRIPE_PUBLISHABLE_KEY ||
        (window as Window & { __STRIPE_PUBLISHABLE_KEY__?: string })
          .__STRIPE_PUBLISHABLE_KEY__
      : undefined
  const hasStripePublicKey = !!stripePublishableKey

  // Initialize Stripe when component mounts
  useEffect(() => {
    if (!hasStripePublicKey) {
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

        // Check for existing incomplete payment method
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
          paymentMethod = await createPaymentMethodMutation.mutateAsync()
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

        // Create Elements
        const elements = stripe.elements({
          clientSecret: secret,
          appearance,
        })

        elementsRef.current = elements

        // Create Payment Element
        const paymentElement = elements.create('payment')
        currentPaymentElement = paymentElement
        paymentElementRef.current = paymentElement

        // Wait for container to be available
        await new Promise((resolve) => setTimeout(resolve, 50))

        const container = stripeContainerRef.current
        if (!mounted || !container) {
          setIsStripeLoading(false)
          return
        }
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

    const timeoutId = setTimeout(() => {
      initializeStripe()
    }, 100)

    return () => {
      clearTimeout(timeoutId)
      hasInitialized = false
      mounted = false

      requestAnimationFrame(() => {
        if (currentPaymentElement && containerForCleanup?.parentNode) {
          try {
            currentPaymentElement.unmount()
          } catch {
            // Silently ignore
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
  }, [
    hasStripePublicKey,
    stripePublishableKey,
    allPaymentMethods,
    appearance,
    createPaymentMethodMutation,
  ])

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

    if (
      !stripeRef.current ||
      !elementsRef.current ||
      !clientSecret ||
      !paymentMethodId
    ) {
      setError('Payment form not ready. Please try again.')
      return
    }

    try {
      setError(null)

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

      if (!setupIntent || setupIntent.status !== 'succeeded') {
        throw new Error('Payment setup failed')
      }

      const stripePaymentMethod = setupIntent.payment_method
      if (!stripePaymentMethod || typeof stripePaymentMethod === 'string') {
        throw new Error('Invalid payment method response')
      }

      // Check if US card requires state
      if (stripePaymentMethod.card?.country === 'US' && !showStatePicker) {
        setShowStatePicker(true)
        return
      }

      // Link payment method to Appwrite
      await setPaymentMethodProviderMutation.mutateAsync({
        paymentMethodId,
        providerMethodId:
          typeof stripePaymentMethod === 'object'
            ? stripePaymentMethod.id
            : stripePaymentMethod,
        name: cardholderName.trim(),
        state: selectedState || undefined,
      })

      // Assign to organization if provided
      if (organizationId) {
        await setDefaultPaymentMethodMutation.mutateAsync({
          organizationId,
          paymentMethodId,
        })
      }

      toast.success(
        organizationId
          ? `Payment method has been added to your organization`
          : 'A new payment method has been added to your account',
      )

      // Reset form
      setCardholderName('')
      setSelectedState('')
      setShowStatePicker(false)
      setError(null)
      setPaymentMethodId(null)
      setClientSecret(null)

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
    setDefaultPaymentMethodMutation.isPending

  if (!hasStripePublicKey) {
    return (
      <div className="rounded-md bg-yellow-500/10 border border-yellow-500/20 px-3 py-2">
        <p className="text-[12px] text-yellow-600 dark:text-yellow-400">
          Stripe payment processing is not configured. Please ensure
          VITE_STRIPE_PUBLISHABLE_KEY is set in your environment.
        </p>
      </div>
    )
  }

  return (
    <div className="rounded-lg border border-border bg-card/50 p-4 space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-[15px] font-semibold text-foreground">
          Add payment method
        </h3>
        {onCancel && (
          <Button
            variant="ghost"
            size="sm"
            className="h-7 w-7 p-0"
            onClick={onCancel}
            disabled={isLoading}
          >
            <X className="h-4 w-4" />
          </Button>
        )}
      </div>

      <form onSubmit={handleSubmit} className="space-y-4">
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
              <div
                key={`stripe-${paymentMethodId || 'new'}`}
                className="min-h-[200px] relative"
              >
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

        <div className="flex items-center justify-end gap-2">
          {onCancel && (
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={onCancel}
              disabled={isLoading}
              className="h-8 text-[13px]"
            >
              Cancel
            </Button>
          )}
          <Button
            type="submit"
            size="sm"
            disabled={
              isLoading ||
              !cardholderName.trim() ||
              (showStatePicker && !selectedState)
            }
            className="h-8 text-[13px]"
          >
            {isLoading ? (
              <>
                <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                Processing...
              </>
            ) : (
              'Add'
            )}
          </Button>
        </div>
      </form>
    </div>
  )
}
