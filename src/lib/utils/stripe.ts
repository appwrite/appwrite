/**
 * Stripe utility functions
 *
 * Handles Stripe.js initialization and theme configuration
 */

import { loadStripe, type Stripe } from '@stripe/stripe-js'

let stripePromise: Promise<Stripe | null> | null = null

/**
 * Get or create Stripe instance
 *
 * @param publishableKey - Stripe publishable key
 * @returns Stripe instance promise
 */
export function getStripeInstance(
  publishableKey?: string,
): Promise<Stripe | null> {
  if (!publishableKey) {
    return Promise.resolve(null)
  }

  if (!stripePromise) {
    stripePromise = loadStripe(publishableKey)
  }

  return stripePromise
}

/**
 * Get Stripe appearance configuration based on theme
 *
 * @param theme - Current theme ('light' | 'dark' | 'system')
 * @returns Stripe appearance configuration
 */
export function getStripeAppearance(theme: string | undefined): {
  theme: 'stripe' | 'night' | 'flat'
  variables?: {
    colorPrimary?: string
    colorBackground?: string
    colorText?: string
    colorDanger?: string
    fontFamily?: string
    spacingUnit?: string
    borderRadius?: string
  }
} {
  const isDark =
    theme === 'dark' ||
    (theme === 'system' &&
      typeof window !== 'undefined' &&
      window.matchMedia('(prefers-color-scheme: dark)').matches)

  if (isDark) {
    return {
      theme: 'night',
      variables: {
        colorPrimary: '#3b82f6',
        colorBackground: '#0a0a0a',
        colorText: '#ffffff',
        colorDanger: '#ef4444',
        fontFamily: 'system-ui, sans-serif',
        spacingUnit: '4px',
        borderRadius: '8px',
      },
    }
  }

  return {
    theme: 'stripe',
    variables: {
      colorPrimary: '#3b82f6',
      colorBackground: '#ffffff',
      colorText: '#0a0a0a',
      colorDanger: '#ef4444',
      fontFamily: 'system-ui, sans-serif',
      spacingUnit: '4px',
      borderRadius: '8px',
    },
  }
}

/**
 * Get Stripe appearance based on current theme
 *
 * @param theme - Current theme from useTheme hook
 * @returns Stripe appearance configuration
 */
export function getStripeAppearanceFromTheme(theme: string | undefined) {
  return getStripeAppearance(theme)
}

/**
 * Drive a PaymentIntent that needs customer action to completion.
 *
 * The backend may return a clientSecret with the PI in either
 * `requires_confirmation` (server didn't pre-confirm) or `requires_action`
 * (server confirmed, needs 3DS). `stripe.confirmCardPayment` handles both —
 * it confirms the PI with its already-attached payment method if needed and
 * runs the 3DS challenge inline. If the PI is already settled we do nothing.
 */
export async function confirmPayment(config: {
  clientSecret: string
  publishableKey?: string
}): Promise<void> {
  const envKey =
    typeof import.meta.env.VITE_STRIPE_PUBLISHABLE_KEY === 'string'
      ? import.meta.env.VITE_STRIPE_PUBLISHABLE_KEY
      : undefined
  const stripe = await getStripeInstance(
    config.publishableKey ??
      (typeof window !== 'undefined'
        ? ((window as Window & { __STRIPE_PUBLISHABLE_KEY__?: string })
            .__STRIPE_PUBLISHABLE_KEY__ ?? envKey)
        : envKey),
  )
  if (!stripe) throw new Error('Stripe not available')

  const { paymentIntent, error: retrieveError } =
    await stripe.retrievePaymentIntent(config.clientSecret)
  if (retrieveError) {
    throw new Error(
      retrieveError.message ?? 'Failed to retrieve payment status',
    )
  }

  const status = paymentIntent?.status
  if (
    status === 'succeeded' ||
    status === 'processing' ||
    status === 'requires_capture'
  ) {
    return
  }

  if (status === 'requires_confirmation' || status === 'requires_action') {
    const { error, paymentIntent: updatedIntent } =
      await stripe.confirmCardPayment(config.clientSecret)
    if (error) throw new Error(error.message ?? 'Payment confirmation failed')

    // If the user dismissed the 3DS modal the PI reverts to
    // requires_payment_method without surfacing an error in `error` — detect
    // this and throw an actionable message.
    if (updatedIntent?.status === 'requires_payment_method') {
      throw new Error(
        'Authentication was cancelled. Please try again or use a different payment method.',
      )
    }
    if (
      updatedIntent &&
      updatedIntent.status !== 'succeeded' &&
      updatedIntent.status !== 'processing' &&
      updatedIntent.status !== 'requires_capture'
    ) {
      throw new Error(
        `Payment did not complete (status: ${updatedIntent.status}).`,
      )
    }
    return
  }

  throw new Error(
    `Payment cannot be completed in its current state (${status ?? 'unknown'}).`,
  )
}
