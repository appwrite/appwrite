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
 * Handle 3DS or other customer actions for a PaymentIntent that was already
 * confirmed server-side (`confirm: true`).
 *
 * The backend always returns a clientSecret, but the PI may or may not need
 * customer action (3DS). We retrieve the PI status first and only call
 * `handleNextAction` when the PI is actually in `requires_action`.
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
        ? (window as Window & { __STRIPE_PUBLISHABLE_KEY__?: string })
            .__STRIPE_PUBLISHABLE_KEY__ ?? envKey
        : envKey),
  )
  if (!stripe) throw new Error('Stripe not available')

  // Check if the PI actually needs a customer action (e.g. 3DS)
  const { paymentIntent, error: retrieveError } =
    await stripe.retrievePaymentIntent(config.clientSecret)
  if (retrieveError) {
    throw new Error(retrieveError.message ?? 'Failed to retrieve payment status')
  }

  if (paymentIntent?.status === 'requires_action') {
    const { error } = await stripe.handleNextAction({
      clientSecret: config.clientSecret,
    })
    if (error) throw new Error(error.message ?? 'Payment confirmation failed')
  }
  // If already requires_capture or succeeded, nothing to do client-side
}
