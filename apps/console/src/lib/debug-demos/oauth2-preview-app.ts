/** Search param value for the built-in Cursor mock client. */
export const OAUTH2_PREVIEW_MOCK_APP_ID = 'mock'

export function isOAuth2PreviewMockAppId(
  appId: string | null | undefined,
): boolean {
  const trimmed = appId?.trim()
  return !trimmed || trimmed === OAUTH2_PREVIEW_MOCK_APP_ID
}

export function resolveOAuth2PreviewAppId(
  appId: string | null | undefined,
): string | null {
  if (isOAuth2PreviewMockAppId(appId)) return null
  return appId!.trim()
}
