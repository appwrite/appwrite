export const OAUTH2_PREVIEW_SCREENS = [
  'consent',
  'consent-mcp',
  'consent-resources',
  'device-code',
  'device-consent',
  'outcome-approved',
  'outcome-approved-device',
  'outcome-approved-deeplink',
  'outcome-denied',
  'error',
  'loading',
] as const

export type OAuth2PreviewScreen = (typeof OAUTH2_PREVIEW_SCREENS)[number]

export const OAUTH2_CONSENT_PREVIEW_SCREENS = [
  'consent',
  'consent-mcp',
  'consent-resources',
] as const satisfies readonly OAuth2PreviewScreen[]

export const OAUTH2_DEVICE_FLOW_PREVIEW_SCREENS = [
  'device-code',
  'device-consent',
  'outcome-approved-device',
] as const satisfies readonly OAuth2PreviewScreen[]

export const OAUTH2_OUTCOME_PREVIEW_SCREENS = [
  'outcome-approved',
  'outcome-approved-deeplink',
  'outcome-denied',
  'error',
  'loading',
] as const satisfies readonly OAuth2PreviewScreen[]

export const OAUTH2_CONSENT_PREVIEW_SCREEN_OPTIONS: {
  value: (typeof OAUTH2_CONSENT_PREVIEW_SCREENS)[number]
  label: string
}[] = [
  { value: 'consent', label: 'Default' },
  { value: 'consent-mcp', label: 'MCP' },
  { value: 'consent-resources', label: 'Resources' },
]

export const OAUTH2_DEVICE_FLOW_PREVIEW_SCREEN_OPTIONS: {
  value: (typeof OAUTH2_DEVICE_FLOW_PREVIEW_SCREENS)[number]
  label: string
}[] = [
  { value: 'device-code', label: 'Enter / confirm code' },
  { value: 'device-consent', label: 'Consent' },
  { value: 'outcome-approved-device', label: 'Device connected' },
]

export const OAUTH2_OUTCOME_PREVIEW_SCREEN_OPTIONS: {
  value: (typeof OAUTH2_OUTCOME_PREVIEW_SCREENS)[number]
  label: string
}[] = [
  { value: 'outcome-approved', label: 'Access granted' },
  { value: 'outcome-approved-deeplink', label: 'Deep link' },
  { value: 'outcome-denied', label: 'Cancelled' },
  { value: 'error', label: 'Failed' },
  { value: 'loading', label: 'Loading' },
]

export function isOAuth2PreviewScreen(value: string): value is OAuth2PreviewScreen {
  return (OAUTH2_PREVIEW_SCREENS as readonly string[]).includes(value)
}

export function oauth2PreviewDemoIdForScreen(
  screen: OAuth2PreviewScreen,
): 'oauth2-consent' | 'oauth2-device-flow' | 'oauth2-outcomes' {
  if (
    (OAUTH2_CONSENT_PREVIEW_SCREENS as readonly string[]).includes(screen)
  ) {
    return 'oauth2-consent'
  }
  if (
    (OAUTH2_DEVICE_FLOW_PREVIEW_SCREENS as readonly string[]).includes(screen)
  ) {
    return 'oauth2-device-flow'
  }
  return 'oauth2-outcomes'
}
