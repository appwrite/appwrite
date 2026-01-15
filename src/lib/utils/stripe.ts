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
export function getStripeInstance(publishableKey?: string): Promise<Stripe | null> {
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
  const isDark = theme === 'dark' || (theme === 'system' && typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches)

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
