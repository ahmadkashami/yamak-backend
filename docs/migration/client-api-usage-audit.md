# Client API Usage Audit

## Scope

This audit records the current client API usage against the Laravel single-port target. It is a cutover control document, not a client refactor.

Laravel target roots:

- Dashboard: `http://localhost:8000/api/dashboard/v1/`
- Mobile: `http://localhost:8000/api/mobile/v1/`
- Driver: `http://localhost:8000/api/driver/v1/`
- Temporary driver alias: `http://localhost:8000/api/driver-app/v1/`

## mobile-rn

Status: compatible with the Laravel mobile root.

Evidence:

- `src/apis/axios.config.ts` defaults to `http://localhost:8000/api/mobile/v1/` when `EXPO_PUBLIC_API_PRO` is not set.
- `src/apis/axios.config.ts` also falls back dynamic API calls to `http://localhost:8000/api/mobile/v1/`.
- `src/components/ui/AppPickerModal.tsx` development environment points to `http://localhost:8000/api/mobile/v1/`.
- `src/components/ui/AppCustomIpModal.tsx` builds custom LAN URLs with port `8000` and `/api/mobile/v1/`.
- Auth, profile, user cars, services, vehicles, settings, and requests use relative paths under the configured mobile root.

Known compatibility notes:

- `Auth.api.ts` uses the active mobile base URL to build the sibling legacy register endpoint at `/api/mobile/client/v1/register`. This avoids doubling the `/api/mobile/v1` prefix while preserving the Laravel legacy route.
- Production/staging picker values currently point to `https://yamak-kw.com/api/mobile/v1/`; confirm this host routes to Laravel before final cutover.

## yamak-dashboard

Status: compatible with the Laravel dashboard root.

Evidence:

- `.env` sets `VITE_BASE_URL=http://localhost:8000/api/dashboard/v1/`.
- `src/plugins/axios.js` uses `import.meta.env.VITE_BASE_URL`.
- Store actions call relative dashboard paths such as `auth/login`, `sys-users/profile`, `companies`, `branches`, `services`, `vehicles`, `driver`, `requests`, `notifications/*`, `websites`, and `files-upload`.

Known compatibility notes:

- Dashboard upload still uses multipart key `file`, matching Laravel upload parity.
- Production client builds must set `VITE_BASE_URL` to the production Laravel dashboard API root.

## yamak-drivers

Status: compatible with the Laravel driver root.

Evidence:

- `src/plugins/AxiosConfig.ts` sets `http://localhost:8000/api/driver/v1/`.
- Driver auth calls `auth/login`.
- Driver vehicle flows call `vehicles/owner`, `vehicles/link`, `vehicles/unlink`, and `vehicles/location`.
- Driver status updates call `drivers/profile`.

Known compatibility notes:

- Laravel keeps `/api/driver-app/v1` as a temporary compatibility alias, but the active driver client uses `/api/driver/v1`.
- Do not remove the alias until deployed driver clients are verified on the canonical root.

## yamak-web

Status: compatible with the current dashboard-backed website content root.

Evidence:

- `.env` sets `VITE_BASE_URL="http://localhost:8000/api/dashboard/v1/"`.
- `src/plugins/axios.js` uses `import.meta.env.VITE_BASE_URL`.
- Current public web store fetches `websites/domains` through the configured dashboard API root.

Known compatibility notes:

- Production web builds must set `VITE_BASE_URL` to the production Laravel API host.
- If public website endpoints are later split from dashboard auth scope, update this audit and the web client in the same implementation step.

## Cutover Checks

Before production traffic moves:

1. Confirm production client environment values point to the Laravel API host.
2. Confirm no client build references the legacy NestJS local ports `5001`, `5003`, or `5004`.
3. Confirm mobile custom-IP and development picker remain on port `8000`.
4. Confirm dashboard upload, notification, and website content paths still match Laravel routes.
5. Confirm driver clients use `/api/driver/v1` and the temporary alias remains deployed.

## Current Result

As of 2026-05-22, the audited local single-port Laravel target is compatible after fixing the mobile legacy register URL construction. The remaining production cutover action is environment configuration: deployed builds must use the production Laravel API host and must not point to legacy split NestJS ports.
