# Custom Domain Setup Guide

White-label a brand's checkout and API under its own domain (e.g. `pay.yourbrand.com`) by mapping a **custom domain** in the admin panel. OwnPay verifies ownership via a DNS **TXT record** and confirms routing via an **A / CNAME record**, then serves the brand's checkout from that domain.

> The Admin → **Domains** page shows the exact records to create. All values below (CNAME target, server IP, TXT token) are generated from your installation's configuration and shown per-domain — nothing here is hardcoded.

---

## Step 1 — Add the domain in OwnPay

1. Go to **Admin → Domains**.
2. Click **+ Add Domain**.
3. Enter the domain you own (e.g. `pay.yourbrand.com`). Do **not** include `https://`.
4. Choose the type:
   - **Checkout domain** — serves the brand's payment checkout.
   - **API domain** — serves the brand's API endpoints.
5. (Optional) Set a **Redirect URL** — visitors hitting the bare domain root are redirected there.
6. Submit. OwnPay creates the domain in **pending** state and reveals the **verification token**.

---

## Step 2 — Add the TXT verification record

At your DNS provider, create a record:

| Field | Value |
| ------ | ----- |
| Type | `TXT` |
| Name / Host | `_ownpay-verify.yourbrand.com` |
| Value | the token shown in the wizard (format `ownpay-verify=…`) |

This proves you own the domain. Copy/paste both fields from the Add Domain wizard or the domain's **Manage → DNS Setup** tab.

---

## Step 3 — Point the domain to OwnPay (choose one)

| Option | Type | Value |
| ------ | ---- | ----- |
| **A** | `A` | the **Server IP** shown in the UI (see Cloudflare note below) |
| **B** | `CNAME` | the **CNAME target** shown in the UI (your `APP_DOMAIN` / `APP_URL` host) |

Only **one** of these is required. DNS propagation typically takes 5–30 minutes.

### If your parent domain is behind Cloudflare

The **Server IP** hint is resolved from your `APP_DOMAIN`. When that domain is **proxied through Cloudflare**, the resolution returns a Cloudflare **edge IP**, not the server that must actually receive traffic. OwnPay detects this and shows a warning on the Domains page.

DNS verification compares the brand domain's **A record** (exact match) against the **Server IP** shown in the UI, so the value OwnPay resolves must be the real origin server. Two ways to get there:

1. **Point the brand domain at your origin (recommended).** Set the origin server's public IPv4 in `.env`:
   ```
   APP_SERVER_IP=203.0.113.10
   ```
   Clear configuration caches if any, reload the Domains page, and point the brand domain's **A record** at that IP. Keep the brand domain itself **DNS only** (grey cloud / not proxied) so its A record resolves to the origin server — a proxied brand domain resolves to Cloudflare edge IPs and still fails verification.

2. **CNAME the brand domain to a host that deterministically resolves to the shown Server IP** (typically the unproxied origin host). OwnPay verifies A records only — a CNAME that resolves to rotating Cloudflare edge IPs will not pass DNS verification, so this path is only reliable when the target's A record matches the Server IP shown.

> If the warning is not shown but you still point to an edge IP, double-check that `APP_SERVER_IP` (when set) matches the IP that port 80/443 actually forwards to.

---

## Step 4 — Verify & activate

1. Wait for propagation, then click **Verify DNS** (or **✓ Verify DNS** in the wizard).
2. OwnPay checks the TXT record, then the A/CNAME record. On success the domain becomes **active** and the status pill turns green.
3. Check **SSL** — run **Check SSL** once the certificate (let's Encrypt, cPanel AutoSSL, Cloudflare Full) is live.

> Unverified domains are re-checked hourly and auto-removed after 7 days. DNS verification must point at the correct origin IP (see the Cloudflare section above) or it stays pending.

---

## Provider-specific DNS panels

| Provider | Zones | TXT | A / CNAME |
| -------- | ----- | --- | --------- |
| **Cloudflare** | Domains → DNS | *Add record → TXT* | *Add record → A or CNAME*; keep proxy on only for CNAME targets behind the same zone |
| **cPanel / WHM** | Domains → Zone Editor | *Manage → TXT* | *Manage → A* (use the shared IP shown in the panel or a dedicated IP) |
| **Plesk** | Websites & Domains → DNS settings | *Add DNS record → TXT* | *Add DNS record → A / CNAME* |
| **Namecheap** | Domain List → Manage → Advanced DNS | *Add New Record → TXT Record* | *Add New Record → A Record / CNAME Record* |
| **GoDaddy** | Domain Portfolio → DNS | *Add → TXT* | *Add → A / CNAME* |
| **Hostinger** | Domains → DNS / Zone Editor | *Add Record → TXT* | *Add Record → A / CNAME* |
| **Hetzner** | DNS → Zones | *Add record → TXT* | *Add record → A / CNAME* |

---

## Automation via control-panel APIs (planned)

The issue **#570** also tracks a future enhancement: **auto-configuration through control-panel APIs** (cPanel/WHM and others that expose a configuration API). The integration would let an operator enter a hosting panel's API credentials once and OwnPay would create the TXT + A/CNAME records automatically on domain mapping. This is a separate feature workstream — see [#570](https://github.com/own-pay/OwnPay/issues/570) and `docs/ROADMAP.md`.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
| ------- | ------------ | --- |
| "TXT record not found" | Record not yet created / token copied wrong / propagated | Re-check Name/Host and Value, wait for propagation, re-run Verify DNS |
| "A record does not point to …" | Pointed at a proxy edge IP instead of the origin, or DNS hasn't propagated | Set `APP_SERVER_IP` to the origin IP (see above) or use the CNAME option |
| SSL stays "Pending" | Certificate not yet issued/renewed for the brand domain | Issue/verify the certificate then click **Check SSL** |
| Submit button never resolves | Domain stays pending past 7 days | DNS was never verified — domain is auto-removed; re-add and fix DNS |