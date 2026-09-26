# KEDA ScaledJob POC

Runs queue jobs as Kubernetes Jobs the K8s-native way: the payload stays in the
**Redis queue**, and [KEDA](https://keda.sh) scales worker Jobs off the queue
depth. No custom broker, no payload on any Kubernetes object.

Contrast with the environment-variable `KubernetesJob` broker (branch `feat/queue-kubernetes-job`),
which creates one Job per message with the payload inlined in an environment
variable.

## How it works

- Producers enqueue with the ordinary `Utopia\Queue\Broker\Redis` broker — no
  Kubernetes involvement.
- A KEDA `ScaledJob` (`k8s.yaml`) watches the queue's Redis list length
  (`{namespace}.queue.{name}`) and spawns worker Jobs, up to `maxReplicaCount`.
- Each worker (`worker.php`) runs the `Utopia\Queue\Adapter\KubernetesJob`
  adapter: a run-to-completion adapter that drains the queue with the same Redis
  broker (`receive` → handle → `commit`) and returns, so the Job completes.

The only library code the approach needs is that adapter (`src/Queue/Adapter/KubernetesJob.php`,
covered by `KubernetesJobAdapterTest`); everything else is deployment config.

## Run it

```sh
tests/keda-e2e.sh   # needs docker + kind + kubectl + helm
```

It stands up kind, installs KEDA, deploys Redis + the ScaledJob, loads the worker
image, then runs `KedaTest`, which enqueues messages and asserts KEDA spawns Jobs
that drain the queue.

## Comparison with the environment-variable broker

| | environment-variable KubernetesJob | KEDA ScaledJob |
|---|---|---|
| Payload | inlined in the Job's environment variable — etcd (~1.5 MB) / `ARG_MAX` limits, visible in the pod spec, duplicated per Pod | stays in Redis; never on a K8s object |
| Custom broker | yes (`KubernetesJob` publisher) | none — reuses the `Redis` broker |
| Producer needs cluster access | yes (creates Jobs) | no (just Redis) |
| Scaling | one Job per message | KEDA scales N Jobs off queue depth; workers batch-drain |
| Extra dependency | `appwrite-labs/php-k8s` in the app | KEDA operator in the cluster |
| Secrets/PII in payload | exposed via pod spec | fine (stays in Redis) |

Trade-off: KEDA needs its operator installed in the cluster, but it keeps the
queue a queue (payload in Redis) and is the de-facto standard for event-driven
Kubernetes Jobs — which is why it's the recommended path.

> Note: the ScaledJob trigger `address` must be the Redis Service FQDN
> (`redis.<namespace>.svc.cluster.local:6379`) — KEDA evaluates triggers from the
> `keda` namespace, so a short name won't resolve to the workload's namespace.

## Crash recovery

`receive()` claims a message by atomically popping it from the main queue into
the broker's processing list before the worker handles it. If a worker pod is
hard-killed (OOM, node eviction) after the claim but before `commit`/`reject`,
that message is stranded in the processing list — it's no longer in the main
queue, so KEDA won't spawn a Job for it, and `backoffLimit` can't help (a new pod
only drains the main queue). Production deployments should run a periodic reaper
(`Publisher::retry()`) to requeue stale processing entries. This POC does not
configure one.
