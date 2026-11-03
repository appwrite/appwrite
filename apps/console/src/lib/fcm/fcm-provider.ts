/**
 * The FCM quick setup provider: the Google OAuth2 provider that issues the
 * access token, plus the Google Cloud calls the flow makes with it. Shaped like
 * the SMTP quick setup providers so it reuses the same OAuth2 round trip and
 * token reconnect rules (`src/lib/smtp/quick-setup-oauth.ts`,
 * `use-provider-tokens.ts`), which key only on `id`, `oauth`, and
 * `api.isUnauthorizedError`.
 */

import { OAuthProvider } from '@appwrite.io/console'
import type { QuickSetupOAuthProvider } from '@/lib/smtp/quick-setup-oauth'
import { FIREBASE_CONSOLE_URL, GOOGLE_OAUTH_SCOPES } from './google'
import {
  checkProvisioningPermissions,
  deleteServiceAccount,
  enableServices,
  isGoogleUnauthorizedError,
  listFirebaseProjects,
  provisionServiceAccountKey,
  type FcmServiceAccountKey,
  type FirebaseProject,
  type ProvisioningPreflight,
} from './google-cloud-api'

export interface FcmQuickSetupProvider extends QuickSetupOAuthProvider {
  id: 'fcm'
  name: string
  iconPath: string
  connectLabel: string
  /** Where the user manages Firebase projects, for the "no projects" state. */
  projectsUrl: string
  api: {
    listProjects(accessToken: string): Promise<FirebaseProject[]>
    checkPermissions(
      accessToken: string,
      projectId: string,
    ): Promise<ProvisioningPreflight>
    enableServices(accessToken: string, projectId: string): Promise<void>
    provisionKey(
      accessToken: string,
      input: { projectId: string; projectName: string },
    ): Promise<FcmServiceAccountKey>
    deleteServiceAccount(
      accessToken: string,
      projectId: string,
      email: string,
    ): Promise<void>
    isUnauthorizedError(error: unknown): boolean
  }
}

export const FCM_QUICK_SETUP_PROVIDER: FcmQuickSetupProvider = {
  id: 'fcm',
  name: 'Firebase Cloud Messaging',
  iconPath: '/icons/firebase.svg',
  connectLabel: 'Connect with Google',
  projectsUrl: FIREBASE_CONSOLE_URL,
  oauth: { provider: OAuthProvider.Google, scopes: GOOGLE_OAUTH_SCOPES },
  api: {
    listProjects: listFirebaseProjects,
    checkPermissions: checkProvisioningPermissions,
    enableServices,
    provisionKey: provisionServiceAccountKey,
    deleteServiceAccount,
    isUnauthorizedError: isGoogleUnauthorizedError,
  },
}
