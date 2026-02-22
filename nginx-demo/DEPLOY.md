# Deploy / update the dashboard

After changing the ConfigMap (dashboard, data.php, version.php), the running pod keeps serving the **old** files until new pods are created. Do this every time you update the dashboard:

```bash
# From the repo root (or pass the correct path to your configmap/deployment)
kubectl apply -f nginx-demo/configmap.yaml
kubectl rollout restart deployment/nginx-demo -n default
```

Replace `-n default` with your namespace if different (e.g. `-n nginx-demo`).

Wait for the new pod to be ready:

```bash
kubectl rollout status deployment/nginx-demo -n default
```

Then **hard-refresh** the browser so it doesn’t use cached HTML/JS:

- **Chrome / Edge:** `Ctrl+Shift+R` (Windows/Linux) or `Cmd+Shift+R` (Mac)
- **Firefox:** `Ctrl+F5` or `Cmd+Shift+R`

If you still see old content, try an incognito/private window or clear cache for the site.
