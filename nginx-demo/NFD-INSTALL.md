# Installing Node Feature Discovery (NFD) for CPU info

The dashboard’s **CPU type** column shows vendor, family, and model (e.g. **Intel, Family 6, Model 79**) when [Node Feature Discovery](https://github.com/kubernetes-sigs/node-feature-discovery) (NFD) is installed on your cluster. Without NFD you’ll only see architecture (e.g. amd64) or cloud instance type.

## Quick install (Kustomize)

```bash
kubectl apply -k "https://github.com/kubernetes-sigs/node-feature-discovery/deployment/overlays/default?ref=master"
```

This creates the `node-feature-discovery` namespace, NFD master deployment, and NFD worker DaemonSet. The workers run on each node and add labels like `feature.node.kubernetes.io/cpu-model.vendor_id`, `cpu-model.family`, and `cpu-model.id`.

## Verify

Wait for the NFD worker pods to be ready:

```bash
kubectl -n node-feature-discovery get ds,deploy
```

Check that nodes have NFD labels:

```bash
kubectl get nodes -o json | jq '.items[].metadata.labels | with_entries(select(.key | startswith("feature.node.kubernetes.io/cpu-model")))'
```

You should see labels such as `feature.node.kubernetes.io/cpu-model.vendor_id`, `cpu-model.family`, `cpu-model.id`. After that, refresh the dashboard and the **CPU type** column will show the human-readable CPU info (e.g. **Intel, Family 6, Model 79**).

## Alternative: Helm

```bash
helm install -n node-feature-discovery --create-namespace nfd \
  oci://gcr.io/k8s-staging-nfd/charts/node-feature-discovery \
  --version 0.0.0-master
```

## More info

- [NFD quick start](https://kubernetes-sigs.github.io/node-feature-discovery/master/get-started/quick-start.html)
- [NFD feature labels](https://kubernetes-sigs.github.io/node-feature-discovery/master/usage/features.html) (including CPU)
