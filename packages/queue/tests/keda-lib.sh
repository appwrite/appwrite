# Sourced helpers to stand up / tear down the KEDA e2e environment (kind + KEDA +
# Redis + the ScaledJob). Call keda_up (exports KUBECONFIG + KEDA_E2E) then
# keda_down. Assumes cwd is the package root and vendor/ is installed (the worker
# image copies it). Missing CLI tools are downloaded at pinned, checksum-verified
# versions; kubectl/helm are usually already on CI runners.

KIND_CLUSTER="${KIND_CLUSTER:-utopia-queue-keda}"
KUBECONFIG_FILE="${KUBECONFIG_FILE:-$(pwd)/.kubeconfig.keda}"
KEDA_TOOLS="${KEDA_TOOLS:-$(pwd)/.e2e-bin}"

KIND_VERSION='v0.30.0'
KIND_SHA256='517ab7fc89ddeed5fa65abf71530d90648d9638ef0c4cde22c2c11f8097b8889'
KUBECTL_VERSION='v1.31.4'
KUBECTL_SHA256='298e19e9c6c17199011404278f0ff8168a7eca4217edad9097af577023a5620f'
HELM_VERSION='v3.16.4'
HELM_SHA256='fc307327959aa38ed8f9f7e66d45492bb022a66c3e5da6063958254b9767d179'

# Only tear down a cluster this script created, never a pre-existing one.
KEDA_CREATED_CLUSTER=0

keda_verify() {
    # $1 = file, $2 = expected sha256
    echo "$2  $1" | sha256sum -c - > /dev/null 2>&1 || { echo "checksum mismatch for $1"; exit 1; }
}

keda_up() {
    mkdir -p "$KEDA_TOOLS"
    PATH="$KEDA_TOOLS:$PATH"

    if ! command -v kind > /dev/null 2>&1; then
        echo "installing kind $KIND_VERSION..."
        curl -fsSL -o "$KEDA_TOOLS/kind" "https://github.com/kubernetes-sigs/kind/releases/download/$KIND_VERSION/kind-linux-amd64"
        keda_verify "$KEDA_TOOLS/kind" "$KIND_SHA256"
        chmod +x "$KEDA_TOOLS/kind"
    fi
    if ! command -v kubectl > /dev/null 2>&1; then
        echo "installing kubectl $KUBECTL_VERSION..."
        curl -fsSL -o "$KEDA_TOOLS/kubectl" "https://dl.k8s.io/release/$KUBECTL_VERSION/bin/linux/amd64/kubectl"
        keda_verify "$KEDA_TOOLS/kubectl" "$KUBECTL_SHA256"
        chmod +x "$KEDA_TOOLS/kubectl"
    fi
    if ! command -v helm > /dev/null 2>&1; then
        echo "installing helm $HELM_VERSION..."
        curl -fsSL -o "$KEDA_TOOLS/helm.tar.gz" "https://get.helm.sh/helm-$HELM_VERSION-linux-amd64.tar.gz"
        keda_verify "$KEDA_TOOLS/helm.tar.gz" "$HELM_SHA256"
        tar -xz -C "$KEDA_TOOLS" --strip-components=1 -f "$KEDA_TOOLS/helm.tar.gz" linux-amd64/helm
        rm -f "$KEDA_TOOLS/helm.tar.gz"
    fi

    if ! kind get clusters 2> /dev/null | grep -qx "$KIND_CLUSTER"; then
        kind create cluster --name "$KIND_CLUSTER" --wait 120s
        KEDA_CREATED_CLUSTER=1
    fi
    kind get kubeconfig --name "$KIND_CLUSTER" > "$KUBECONFIG_FILE"
    export KUBECONFIG="$KUBECONFIG_FILE"

    helm repo add kedacore https://kedacore.github.io/charts > /dev/null 2>&1 || true
    helm repo update kedacore > /dev/null
    helm upgrade --install keda kedacore/keda --namespace keda --create-namespace --wait --timeout 5m

    docker build -t utopia-queue-keda-worker:e2e -f tests/Queue/servers/Keda/Dockerfile .
    kind load docker-image utopia-queue-keda-worker:e2e --name "$KIND_CLUSTER"

    kubectl apply -f tests/Queue/servers/Keda/k8s.yaml
    kubectl rollout status deploy/redis -n utopia-queue-keda --timeout=120s

    export KEDA_E2E=true
}

keda_down() {
    if [ "$KEDA_CREATED_CLUSTER" = "1" ]; then
        kind delete cluster --name "$KIND_CLUSTER" > /dev/null 2>&1 || true
    fi
    rm -f "$KUBECONFIG_FILE"
}
