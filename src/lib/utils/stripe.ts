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
 * Redirect to Stripe to confirm payment (e.g. 3DS). Call this when the API
 * returns clientSecret indicating payment authentication is required.
 *
 * @param config - clientSecret, paymentMethodId, returnUrl
 */
export async function confirmPayment(config: {
  clientSecret: string
  paymentMethodId: string
  returnUrl: string
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
  const { error } = await stripe.confirmPayment({
    clientSecret: config.clientSecret,
    confirmParams: {
      return_url: config.returnUrl,
      payment_method: config.paymentMethodId,
    },
  })
  if (error) throw new Error(error.message ?? 'Payment confirmation failed')
}
