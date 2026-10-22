/**
 * A provider accepts sign-ins through the browser redirect flow, through native
 * ID tokens, or both, and the two are switched on independently on the server.
 *
 * Reporting only the browser flow's state is what makes a row read as a
 * contradiction: a provider that signs users in from native ID tokens would be
 * labelled "disabled". Status is therefore derived from both flows at once, and
 * the label names the flows that are on rather than asserting a single verdict.
 */
export type OAuth2SignInStatus =
  'off' | 'browser' | 'native' | 'browser-and-native'

export function getOAuth2SignInStatus(input: {
  browserEnabled: boolean
  nativeEnabled: boolean
}): OAuth2SignInStatus {
  if (input.browserEnabled && input.nativeEnabled) return 'browser-and-native'
  if (input.browserEnabled) return 'browser'
  if (input.nativeEnabled) return 'native'
  return 'off'
}

/** True when at least one sign-in flow can create sessions. */
export function isOAuth2SignInStatusOn(status: OAuth2SignInStatus): boolean {
  return status !== 'off'
}
