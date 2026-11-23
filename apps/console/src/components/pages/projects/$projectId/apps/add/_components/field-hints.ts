/**
 * Per-variant tooltip copy for the App details form (stage 2 of the
 * Add App wizard). Mirrors the hints used in the legacy console
 * (`createWeb.svelte`, `createApple.svelte`, `createAndroid.svelte`,
 * `createFlutter.svelte`, `createReactNative.svelte`).
 */

const HOSTNAME_HINT =
  'The hostname your app uses to call the Appwrite APIs in production or development. No protocol or port number - use localhost during local development.'

const BUNDLE_ID_HINT =
  "You can find your Bundle Identifier in the General tab for your app's primary target in Xcode."

const PACKAGE_NAME_HINT =
  'Your package name is generally the applicationId in your app-level build.gradle file.'

const APP_NAME_HINT = 'Your application name as it appears on the device.'

const NAME_HINT =
  'A friendly name to identify this app in your Appwrite project. Only used inside the console.'

export function getNameTooltip(): string {
  return NAME_HINT
}

export function getHostnameTooltip(_variant: string): string {
  return HOSTNAME_HINT
}

export function getKeyTooltip(variant: string): string {
  if (variant === 'flutter-linux' || variant === 'flutter-windows') {
    return APP_NAME_HINT
  }
  if (
    variant.startsWith('apple') ||
    variant === 'flutter-ios' ||
    variant === 'flutter-macos' ||
    variant === 'react-native-ios'
  ) {
    return BUNDLE_ID_HINT
  }
  return PACKAGE_NAME_HINT
}
