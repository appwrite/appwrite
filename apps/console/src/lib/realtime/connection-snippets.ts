import type { CodeBlockLanguage } from '@/components/global/shared/CodeBlock'
import { resolveFenceCodeLabel, resolveFenceCodeLanguage } from '@/lib/code-language'
import {
  buildSdkQueryCalls,
  formatSdkQueryArray,
} from '@/lib/realtime/query-snippet-source'
import type { SubscriptionQueryEntry } from '@/lib/realtime/subscription-queries'

export type RealtimeSnippetSdkId =
  | 'client-web'
  | 'client-flutter'
  | 'client-apple'
  | 'client-android-kotlin'
  | 'client-android-java'
  | 'client-react-native'

export type RealtimeSnippetSubscription = {
  channel: string
  queries: SubscriptionQueryEntry[]
}

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

function normalizeSubscriptions(
  subscriptions: RealtimeSnippetSubscription[],
): RealtimeSnippetSubscription[] {
  return subscriptions
    .map((entry) => ({
      channel: entry.channel.trim(),
      queries: entry.queries,
    }))
    .filter((entry) => entry.channel)
}

function subscriptionVariableName(index: number, total: number): string {
  if (total === 1) return 'subscription'
  return `subscription${index + 1}`
}

function subscriptionsUseQueries(subscriptions: RealtimeSnippetSubscription[]): boolean {
  return subscriptions.some((entry) => entry.queries.length > 0)
}

function buildWebSubscribeCall(
  channel: string,
  queries: SubscriptionQueryEntry[],
  variable: string,
): string {
  const channelLiteral = `'${escapeSingleQuoted(channel)}'`
  const queryCalls = buildSdkQueryCalls(queries, 'client-web')
  const hasQueries = queryCalls.length > 0

  if (!hasQueries) {
    return `const ${variable} = await realtime.subscribe(${channelLiteral}, response => {
    console.log(response);
});`
  }

  const queryArray = formatSdkQueryArray(queryCalls, 'client-web')
  return `const ${variable} = await realtime.subscribe(
    ${channelLiteral},
    response => {
        console.log(response);
    },
    ${queryArray}
);`
}

function buildFlutterSubscribeCall(
  channel: string,
  queries: SubscriptionQueryEntry[],
  variable: string,
): string {
  const channelLiteral = `['${escapeSingleQuoted(channel)}']`
  const queryCalls = buildSdkQueryCalls(queries, 'client-flutter')
  const hasQueries = queryCalls.length > 0

  if (!hasQueries) {
    return `final ${variable} = realtime.subscribe(${channelLiteral});

${variable}.stream.listen((response) {
    print(response);
});`
  }

  const queryArray = formatSdkQueryArray(queryCalls, 'client-flutter')
  return `final ${variable} = realtime.subscribe(
    ${channelLiteral},
    queries: ${queryArray},
);

${variable}.stream.listen((response) {
    print(response);
});`
}

function buildAppleSubscribeCall(
  channel: string,
  queries: SubscriptionQueryEntry[],
  variable: string,
): string {
  const channelLiteral = `["${escapeDoubleQuoted(channel)}"]`
  const queryCalls = buildSdkQueryCalls(queries, 'client-apple')
  const hasQueries = queryCalls.length > 0

  if (!hasQueries) {
    return `let ${variable} = realtime.subscribe(channels: ${channelLiteral}) { response in
    print(String(describing: response))
}`
  }

  const queryArray = formatSdkQueryArray(queryCalls, 'client-apple')
  return `let ${variable} = realtime.subscribe(
    channels: ${channelLiteral},
    callback: { response in
        print(String(describing: response))
    },
    queries: ${queryArray}
)`
}

function buildKotlinSubscribeCall(
  channel: string,
  queries: SubscriptionQueryEntry[],
  variable: string,
): string {
  const channelLiteral = `"${escapeDoubleQuoted(channel)}"`
  const queryCalls = buildSdkQueryCalls(queries, 'client-android-kotlin')
  const hasQueries = queryCalls.length > 0

  if (!hasQueries) {
    return `val ${variable} = realtime.subscribe(${channelLiteral}) {
    print(it.payload.toString())
}`
  }

  const queryArray = formatSdkQueryArray(queryCalls, 'client-android-kotlin')
  return `val ${variable} = realtime.subscribe(
    ${channelLiteral},
    payloadType = Any::class.java,
    queries = ${queryArray}
) {
    print(it.payload.toString())
}`
}

function buildJavaSubscribeCall(
  channel: string,
  queries: SubscriptionQueryEntry[],
  variable: string,
): string {
  const channelArray = `new String[] { "${escapeDoubleQuoted(channel)}" }`
  const queryCalls = buildSdkQueryCalls(queries, 'client-android-java')
  const hasQueries = queryCalls.length > 0

  if (!hasQueries) {
    return `RealtimeSubscription ${variable} = realtime.subscribe(
    ${channelArray},
    (RealtimeResponseEvent<Object> response) -> {
        System.out.println(response);
        return Unit.INSTANCE;
    }
);`
  }

  const queryArray = formatSdkQueryArray(queryCalls, 'client-android-java')
  return `RealtimeSubscription ${variable} = realtime.subscribe(
    ${channelArray},
    Object.class,
    ${queryArray},
    (RealtimeResponseEvent<Object> response) -> {
        System.out.println(response);
        return Unit.INSTANCE;
    }
);`
}

function buildWebSnippet(
  endpoint: string,
  projectId: string,
  subscriptions: RealtimeSnippetSubscription[],
): string {
  const hasQueries = subscriptionsUseQueries(subscriptions)

  const setup = `import { Client, Realtime${hasQueries ? ', Query' : ''} } from "appwrite";

const client = new Client()
    .setEndpoint('${escapeSingleQuoted(endpoint)}')
    .setProject('${escapeSingleQuoted(projectId)}');

const realtime = new Realtime(client);`

  if (subscriptions.length === 0) {
    return `${setup}

// Add subscriptions in the debugger, then copy updated code here:
// const subscription = await realtime.subscribe('account', response => {
//     console.log(response);
// });`
  }

  const subscribeCalls = subscriptions
    .map((entry, index) =>
      buildWebSubscribeCall(
        entry.channel,
        entry.queries,
        subscriptionVariableName(index, subscriptions.length),
      ),
    )
    .join('\n\n')

  return `${setup}

${subscribeCalls}

// Stop one listener:
// await subscription.unsubscribe();

// Close the shared WebSocket:
// await realtime.disconnect();`
}

function buildFlutterSnippet(
  endpoint: string,
  projectId: string,
  subscriptions: RealtimeSnippetSubscription[],
): string {
  const setup = `import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('${escapeSingleQuoted(endpoint)}')
    .setProject('${escapeSingleQuoted(projectId)}');

final realtime = Realtime(client);`

  if (subscriptions.length === 0) {
    return `${setup}

// final subscription = realtime.subscribe(['account']);
// subscription.stream.listen((response) {
//     print(response);
// });`
  }

  const subscribeCalls = subscriptions
    .map((entry, index) =>
      buildFlutterSubscribeCall(
        entry.channel,
        entry.queries,
        subscriptionVariableName(index, subscriptions.length),
      ),
    )
    .join('\n\n')

  return `${setup}

${subscribeCalls}`
}

function buildAppleSnippet(
  endpoint: string,
  projectId: string,
  subscriptions: RealtimeSnippetSubscription[],
): string {
  const setup = `import Appwrite

let client = Client()
    .setEndpoint("${escapeDoubleQuoted(endpoint)}")
    .setProject("${escapeDoubleQuoted(projectId)}")

let realtime = Realtime(client)`

  if (subscriptions.length === 0) {
    return `${setup}

// let subscription = realtime.subscribe(channels: ["account"]) { response in
//     print(String(describing: response))
// }`
  }

  const subscribeCalls = subscriptions
    .map((entry, index) =>
      buildAppleSubscribeCall(
        entry.channel,
        entry.queries,
        subscriptionVariableName(index, subscriptions.length),
      ),
    )
    .join('\n\n')

  return `${setup}

${subscribeCalls}`
}

function buildKotlinSnippet(
  endpoint: string,
  projectId: string,
  subscriptions: RealtimeSnippetSubscription[],
): string {
  const hasQueries = subscriptionsUseQueries(subscriptions)

  const setup = `import io.appwrite.Client${hasQueries ? '\nimport io.appwrite.Query' : ''}
import io.appwrite.services.Realtime

val client = Client(context)
    .setEndpoint("${escapeDoubleQuoted(endpoint)}")
    .setProject("${escapeDoubleQuoted(projectId)}")

val realtime = Realtime(client)`

  if (subscriptions.length === 0) {
    return `${setup}

// val subscription = realtime.subscribe("account") {
//     print(it.payload.toString())
// }`
  }

  const subscribeCalls = subscriptions
    .map((entry, index) =>
      buildKotlinSubscribeCall(
        entry.channel,
        entry.queries,
        subscriptionVariableName(index, subscriptions.length),
      ),
    )
    .join('\n\n')

  return `${setup}

${subscribeCalls}`
}

function buildJavaSnippet(
  endpoint: string,
  projectId: string,
  subscriptions: RealtimeSnippetSubscription[],
): string {
  const hasQueries = subscriptionsUseQueries(subscriptions)

  const setup = `import io.appwrite.Client;
import io.appwrite.Query;
import io.appwrite.models.RealtimeResponseEvent;
import io.appwrite.models.RealtimeSubscription;
import io.appwrite.services.Realtime;${hasQueries ? '\nimport java.util.Arrays;\nimport java.util.HashSet;' : ''}
import kotlin.Unit;

Client client = new Client(context)
    .setEndpoint("${escapeDoubleQuoted(endpoint)}")
    .setProject("${escapeDoubleQuoted(projectId)}");

Realtime realtime = new Realtime(client);`

  if (subscriptions.length === 0) {
    return `${setup}

// RealtimeSubscription subscription = realtime.subscribe(
//     new String[] { "account" },
//     (RealtimeResponseEvent<Object> response) -> {
//         System.out.println(response);
//         return Unit.INSTANCE;
//     }
// );`
  }

  const subscribeCalls = subscriptions
    .map((entry, index) =>
      buildJavaSubscribeCall(
        entry.channel,
        entry.queries,
        subscriptionVariableName(index, subscriptions.length),
      ),
    )
    .join('\n\n')

  return `${setup}

${subscribeCalls}`
}

function buildReactNativeSnippet(
  endpoint: string,
  projectId: string,
  subscriptions: RealtimeSnippetSubscription[],
): string {
  return buildWebSnippet(endpoint, projectId, subscriptions)
}

export function buildRealtimeConnectionSnippets({
  endpoint,
  projectId,
  subscriptions,
}: {
  endpoint: string
  projectId: string
  subscriptions: RealtimeSnippetSubscription[]
}): RealtimeConnectionSnippet[] {
  const normalizedSubscriptions = normalizeSubscriptions(subscriptions)

  const builders: Record<
    RealtimeSnippetSdkId,
    (
      endpoint: string,
      projectId: string,
      subscriptions: RealtimeSnippetSubscription[],
    ) => string
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
    code: builders[id](endpoint, projectId, normalizedSubscriptions),
    language: resolveFenceCodeLanguage(id),
  }))
}
