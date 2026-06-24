import type { CodeBlockLanguage } from '@/components/global/shared/CodeBlock'
import { resolveFenceCodeLabel, resolveFenceCodeLanguage } from '@/lib/code-language'

export type RealtimeSnippetSdkId =
  | 'client-web'
  | 'client-flutter'
  | 'client-apple'
  | 'client-android-kotlin'
  | 'client-android-java'
  | 'client-react-native'

export type RealtimeConnectionSnippet = {
  id: RealtimeSnippetSdkId
  label: string
  code: string
  language: CodeBlockLanguage
}

export const REALTIME_SNIPPET_SDK_IDS: RealtimeSnippetSdkId[] = [
  'client-web',
  'client-flutter',
  'client-react-native',
  'client-apple',
  'client-android-kotlin',
  'client-android-java',
]

function escapeSingleQuoted(value: string): string {
  return value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")
}

function escapeDoubleQuoted(value: string): string {
  return value.replace(/\\/g, '\\\\').replace(/"/g, '\\"')
}

function normalizeChannels(channels: string[]): string[] {
  return channels.map((channel) => channel.trim()).filter(Boolean)
}

function subscriptionVariableName(index: number, total: number): string {
  if (total === 1) return 'subscription'
  return `subscription${index + 1}`
}

function buildWebSnippet(endpoint: string, projectId: string, channels: string[]): string {
  const setup = `import { Client, Realtime } from "appwrite";

const client = new Client()
    .setEndpoint('${escapeSingleQuoted(endpoint)}')
    .setProject('${escapeSingleQuoted(projectId)}');

const realtime = new Realtime(client);`

  if (channels.length === 0) {
    return `${setup}

// Add subscriptions in the debugger, then copy updated code here:
// const subscription = await realtime.subscribe('account', response => {
//     console.log(response);
// });`
  }

  const subscriptions = channels
    .map((channel, index) => {
      const variable = subscriptionVariableName(index, channels.length)
      return `const ${variable} = await realtime.subscribe('${escapeSingleQuoted(channel)}', response => {
    console.log(response);
});`
    })
    .join('\n\n')

  return `${setup}

${subscriptions}

// Stop one listener:
// await subscription.unsubscribe();

// Close the shared WebSocket:
// await realtime.disconnect();`
}

function buildFlutterSnippet(endpoint: string, projectId: string, channels: string[]): string {
  const setup = `import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('${escapeSingleQuoted(endpoint)}')
    .setProject('${escapeSingleQuoted(projectId)}');

final realtime = Realtime(client);`

  if (channels.length === 0) {
    return `${setup}

// final subscription = realtime.subscribe(['account']);
// subscription.stream.listen((response) {
//     print(response);
// });`
  }

  const subscriptions = channels
    .map((channel, index) => {
      const variable = subscriptionVariableName(index, channels.length)
      return `final ${variable} = realtime.subscribe(['${escapeSingleQuoted(channel)}']);

${variable}.stream.listen((response) {
    print(response);
});`
    })
    .join('\n\n')

  return `${setup}

${subscriptions}`
}

function buildAppleSnippet(endpoint: string, projectId: string, channels: string[]): string {
  const setup = `import Appwrite

let client = Client()
    .setEndpoint("${escapeDoubleQuoted(endpoint)}")
    .setProject("${escapeDoubleQuoted(projectId)}")

let realtime = Realtime(client)`

  if (channels.length === 0) {
    return `${setup}

// let subscription = realtime.subscribe(channels: ["account"]) { response in
//     print(String(describing: response))
// }`
  }

  const subscriptions = channels
    .map((channel, index) => {
      const variable = subscriptionVariableName(index, channels.length)
      return `let ${variable} = realtime.subscribe(channels: ["${escapeDoubleQuoted(channel)}"]) { response in
    print(String(describing: response))
}`
    })
    .join('\n\n')

  return `${setup}

${subscriptions}`
}

function buildKotlinSnippet(endpoint: string, projectId: string, channels: string[]): string {
  const setup = `import io.appwrite.Client
import io.appwrite.services.Realtime

val client = Client(context)
    .setEndpoint("${escapeDoubleQuoted(endpoint)}")
    .setProject("${escapeDoubleQuoted(projectId)}")

val realtime = Realtime(client)`

  if (channels.length === 0) {
    return `${setup}

// val subscription = realtime.subscribe("account") {
//     print(it.payload.toString())
// }`
  }

  const subscriptions = channels
    .map((channel, index) => {
      const variable = subscriptionVariableName(index, channels.length)
      return `val ${variable} = realtime.subscribe("${escapeDoubleQuoted(channel)}") {
    print(it.payload.toString())
}`
    })
    .join('\n\n')

  return `${setup}

${subscriptions}`
}

function buildJavaSnippet(endpoint: string, projectId: string, channels: string[]): string {
  const setup = `import io.appwrite.Client;
import io.appwrite.models.RealtimeResponseEvent;
import io.appwrite.models.RealtimeSubscription;
import io.appwrite.services.Realtime;
import kotlin.Unit;

Client client = new Client(context)
    .setEndpoint("${escapeDoubleQuoted(endpoint)}")
    .setProject("${escapeDoubleQuoted(projectId)}");

Realtime realtime = new Realtime(client);`

  if (channels.length === 0) {
    return `${setup}

// RealtimeSubscription subscription = realtime.subscribe(
//     new String[] { "account" },
//     (RealtimeResponseEvent<Object> response) -> {
//         System.out.println(response);
//         return Unit.INSTANCE;
//     }
// );`
  }

  const subscriptions = channels
    .map((channel, index) => {
      const variable = subscriptionVariableName(index, channels.length)
      return `RealtimeSubscription ${variable} = realtime.subscribe(
    new String[] { "${escapeDoubleQuoted(channel)}" },
    (RealtimeResponseEvent<Object> response) -> {
        System.out.println(response);
        return Unit.INSTANCE;
    }
);`
    })
    .join('\n\n')

  return `${setup}

${subscriptions}`
}

function buildReactNativeSnippet(
  endpoint: string,
  projectId: string,
  channels: string[],
): string {
  return buildWebSnippet(endpoint, projectId, channels)
}

export function buildRealtimeConnectionSnippets({
  endpoint,
  projectId,
  channels,
}: {
  endpoint: string
  projectId: string
  channels: string[]
}): RealtimeConnectionSnippet[] {
  const normalizedChannels = normalizeChannels(channels)

  const builders: Record<
    RealtimeSnippetSdkId,
    (endpoint: string, projectId: string, channels: string[]) => string
  > = {
    'client-web': buildWebSnippet,
    'client-flutter': buildFlutterSnippet,
    'client-apple': buildAppleSnippet,
    'client-android-kotlin': buildKotlinSnippet,
    'client-android-java': buildJavaSnippet,
    'client-react-native': buildReactNativeSnippet,
  }

  return REALTIME_SNIPPET_SDK_IDS.map((id) => ({
    id,
    label: resolveFenceCodeLabel(id),
    code: builders[id](endpoint, projectId, normalizedChannels),
    language: resolveFenceCodeLanguage(id),
  }))
}
