import type { WebFrameworkKey } from './types'
import { WEB_FRAMEWORK_META, getWebStarterRepoName } from './platform-map'

export type LlmPromptConfig = {
  title: string
  alreadyExistsInstructions: string
  cloneCommand: string
  configFile: string
  configCode: string
  configLanguage: string
  runInstructions: string
  using: string
}

export function generatePromptFromConfig(config: LlmPromptConfig): string {
  return `
Goal: Setting up Appwrite SDK in the project depending on if a project already exists or not.

Following are the project details:

\`\`\`
${config.configCode}
\`\`\`

Follow the steps depending on if a project already exists on user's working directory or not:

## If a project already exists:
${config.alreadyExistsInstructions}

## If a project does not exist:

1. Clone the starter kit using ${config.using || 'the terminal'}. Make sure to clone in the current working directory so that the cloned files are directly available in the working directory.

\`\`\`bash
${config.cloneCommand} .
\`\`\`

2. Replace all occurrences of the environment variables described in the project details section with their corresponding values. This effectively hardcodes the project details wherever those environment variables are used. Use grep (or an equivalent search) to find and update all occurrences.
3. ${config.runInstructions}`
}

export function buildWebPromptConfig(params: {
  framework: WebFrameworkKey
  projectId: string
  projectName: string
  endpoint: string
}): LlmPromptConfig {
  const { framework, projectId, projectName, endpoint } = params
  const meta = WEB_FRAMEWORK_META[framework]
  const starterRepo = getWebStarterRepoName(framework)

  const alreadyExists =
    framework === 'angular'
      ? `Install the Appwrite web SDK. Create or update the Angular environment file with the project endpoint and project ID.

Use \`appwrite\` package and wire the client in a shared module. On app launch, call \`client.ping()\` once to verify connectivity.`
      : `Install the Appwrite web SDK. Create an \`appwrite\` client module (TypeScript or JavaScript to match the project) and set endpoint + project.

Example:

\`\`\`js
import { Client } from "appwrite";
const client = new Client().setEndpoint("${endpoint}").setProject("${projectId}");
export { client };
client.ping();
\`\`\`

Ensure \`client.ping()\` runs once when the app starts so the user can confirm the setup.`

  const dotenvBlock = `APPWRITE_PROJECT_ID="${projectId}"
APPWRITE_PROJECT_NAME="${projectName}"
APPWRITE_ENDPOINT="${endpoint}"`

  const angularBlock = `export const environment = {
  appwriteEndpoint: '${endpoint}',
  appwriteProjectId: '${projectId}',
  appwriteProjectName: '${projectName}',
};`

  return {
    title: `Starter kit for Appwrite (${meta.label})`,
    alreadyExistsInstructions: alreadyExists,
    cloneCommand: `git clone https://github.com/appwrite/${starterRepo}\ncd ${starterRepo}`,
    configFile:
      framework === 'angular'
        ? 'src/environments/environment.ts'
        : '.env',
    configCode: framework === 'angular' ? angularBlock : dotenvBlock,
    configLanguage: framework === 'angular' ? 'ts' : 'dotenv',
    runInstructions: `Install dependencies with \`pnpm install\`, then run \`${meta.runCommand}\`. Open http://localhost:${meta.port} and use the demo control to send a ping to Appwrite.`,
    using: 'the terminal or your editor',
  }
}

export function buildNativePromptConfig(
  variant: string,
  configCode: string,
  alreadyExistsInstructions: string,
): LlmPromptConfig {
  const native = NATIVE_PLATFORM_PROMPTS[variant]
  if (!native) {
    return {
      title: 'Appwrite starter kit',
      alreadyExistsInstructions,
      cloneCommand: 'git clone https://github.com/appwrite/starter-for-flutter\ncd starter-for-flutter',
      configFile: 'lib/config/environment.dart',
      configCode,
      configLanguage: 'plaintext',
      runInstructions:
        'Install dependencies, run the app on a device or simulator, then use the demo to ping Appwrite.',
      using: 'the terminal',
    }
  }

  return {
    title: native.title,
    alreadyExistsInstructions,
    cloneCommand: native.cloneCommand,
    configFile: native.configFile,
    configCode,
    configLanguage: native.configLanguage,
    runInstructions: native.runInstructions,
    using: native.using,
  }
}

const NATIVE_PLATFORM_PROMPTS: Record<
  string,
  {
    title: string
    cloneCommand: string
    configFile: string
    configLanguage: string
    runInstructions: string
    using: string
  }
> = {
  android: {
    title: 'Starter kit for Appwrite (Android)',
    cloneCommand:
      'git clone https://github.com/appwrite/starter-for-android\ncd starter-for-android',
    configFile: 'constants/AppwriteConfig.kt',
    configLanguage: 'kotlin',
    runInstructions:
      'Run on a device or emulator, then use the demo to ping Appwrite.',
    using: 'Android Studio or the terminal',
  },
  'apple-ios': {
    title: 'Starter kit for Appwrite (Apple)',
    cloneCommand:
      'git clone https://github.com/appwrite/starter-for-ios\ncd starter-for-ios',
    configFile: 'Sources/Config.plist',
    configLanguage: 'plaintext',
    runInstructions:
      'Run on a simulator or device, then use the demo to ping Appwrite.',
    using: 'Xcode or the terminal',
  },
  'apple-macos': {
    title: 'Starter kit for Appwrite (Apple)',
    cloneCommand:
      'git clone https://github.com/appwrite/starter-for-ios\ncd starter-for-ios',
    configFile: 'Sources/Config.plist',
    configLanguage: 'plaintext',
    runInstructions:
      'Run on a simulator or device, then use the demo to ping Appwrite.',
    using: 'Xcode or the terminal',
  },
  'apple-watchos': {
    title: 'Starter kit for Appwrite (Apple)',
    cloneCommand:
      'git clone https://github.com/appwrite/starter-for-ios\ncd starter-for-ios',
    configFile: 'Sources/Config.plist',
    configLanguage: 'plaintext',
    runInstructions:
      'Run on a simulator or device, then use the demo to ping Appwrite.',
    using: 'Xcode or the terminal',
  },
  'apple-tvos': {
    title: 'Starter kit for Appwrite (Apple)',
    cloneCommand:
      'git clone https://github.com/appwrite/starter-for-ios\ncd starter-for-ios',
    configFile: 'Sources/Config.plist',
    configLanguage: 'plaintext',
    runInstructions:
      'Run on a simulator or device, then use the demo to ping Appwrite.',
    using: 'Xcode or the terminal',
  },
  'flutter-android': {
    title: 'Starter kit for Appwrite (Flutter)',
    cloneCommand:
      'git clone https://github.com/appwrite/starter-for-flutter\ncd starter-for-flutter',
    configFile: 'lib/config/environment.dart',
    configLanguage: 'dart',
    runInstructions:
      'Run with `flutter run` on a device or simulator, then use the demo to ping Appwrite.',
    using: 'the terminal',
  },
  'flutter-ios': {
    title: 'Starter kit for Appwrite (Flutter)',
    cloneCommand:
      'git clone https://github.com/appwrite/starter-for-flutter\ncd starter-for-flutter',
    configFile: 'lib/config/environment.dart',
    configLanguage: 'dart',
    runInstructions:
      'Run with `flutter run` on a device or simulator, then use the demo to ping Appwrite.',
    using: 'the terminal',
  },
  'flutter-web': {
    title: 'Starter kit for Appwrite (Flutter)',
    cloneCommand:
      'git clone https://github.com/appwrite/starter-for-flutter\ncd starter-for-flutter',
    configFile: 'lib/config/environment.dart',
    configLanguage: 'dart',
    runInstructions:
      'Run with `flutter run -d chrome` (or your target), then use the demo to ping Appwrite.',
    using: 'the terminal',
  },
  'flutter-linux': {
    title: 'Starter kit for Appwrite (Flutter)',
    cloneCommand:
      'git clone https://github.com/appwrite/starter-for-flutter\ncd starter-for-flutter',
    configFile: 'lib/config/environment.dart',
    configLanguage: 'dart',
    runInstructions:
      'Run with `flutter run -d linux`, then use the demo to ping Appwrite.',
    using: 'the terminal',
  },
  'flutter-macos': {
    title: 'Starter kit for Appwrite (Flutter)',
    cloneCommand:
      'git clone https://github.com/appwrite/starter-for-flutter\ncd starter-for-flutter',
    configFile: 'lib/config/environment.dart',
    configLanguage: 'dart',
    runInstructions:
      'Run with `flutter run -d macos`, then use the demo to ping Appwrite.',
    using: 'the terminal',
  },
  'flutter-windows': {
    title: 'Starter kit for Appwrite (Flutter)',
    cloneCommand:
      'git clone https://github.com/appwrite/starter-for-flutter\ncd starter-for-flutter',
    configFile: 'lib/config/environment.dart',
    configLanguage: 'dart',
    runInstructions:
      'Run with `flutter run -d windows`, then use the demo to ping Appwrite.',
    using: 'the terminal',
  },
  'react-native-android': {
    title: 'Starter kit for Appwrite (React Native)',
    cloneCommand:
      'git clone https://github.com/appwrite/starter-for-react-native\ncd starter-for-react-native',
    configFile: 'index.ts',
    configLanguage: 'typescript',
    runInstructions:
      'Run `pnpm install` then `pnpm android` or `pnpm ios`, then use the demo to ping Appwrite.',
    using: 'the terminal',
  },
  'react-native-ios': {
    title: 'Starter kit for Appwrite (React Native)',
    cloneCommand:
      'git clone https://github.com/appwrite/starter-for-react-native\ncd starter-for-react-native',
    configFile: 'index.ts',
    configLanguage: 'typescript',
    runInstructions:
      'Run `pnpm install` then `pnpm ios`, then use the demo to ping Appwrite.',
    using: 'the terminal',
  },
}
