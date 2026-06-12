# Client API Usage Audit

This audit records client-side API usage that must be preserved or updated immediately during the Laravel migration. If a Laravel route changes shape, path, token behavior, or response format, the affected client must be updated in the same task.

## Base URLs

| Client | Current Base URL |
| --- | --- |
| `yamak-dashboard` | `VITE_BASE_URL`, currently expected to point to `http://localhost:8000/api/dashboard/v1/` |
| `yamak-web` | `VITE_BASE_URL`, currently expected to point to `http://localhost:8000/api/dashboard/v1/` |
| `yamak-drivers` | `http://localhost:8000/api/driver/v1/` |
| `yamak-mobile` | `EXPO_PUBLIC_API_PRO` or `http://localhost:8000/api/mobile/v1/`; custom runtime base URL can override it |

## yamak-dashboard

Authentication:
- `POST auth/login`
- `GET sys-users/profile`

Dashboard/system users:
- `GET sys-users`
- `POST sys-users`
- `PUT sys-users/{id}`

Core dashboard resources:
- `GET dashboard`
- `GET/POST/PATCH adds`
- `GET/POST app-setting`
- `PUT app-setting/{id}`
- `GET app-users`
- `GET requests`
- `GET/POST/PATCH branches`
- `GET branches/{branchId}/services`
- `GET branches/{branchId}/drivers`
- `GET branches/{branchId}/free-drivers`
- `GET/POST/PATCH companies`
- `GET companies/{companyId}/drivers`
- `GET companies/{companyId}/branches`
- `GET companies/{companyId}/vehicles`
- `GET/POST/PUT driver`
- `GET/POST/PUT services`
- `GET/POST/PUT vehicles`
- `POST files-upload`
- `POST notifications/topics`
- `POST notifications/users`

Location/common data:
- `GET countries`
- `GET countries/{countryId}/states`
- `GET states/{stateId}/cities`

Website management:
- `GET/POST/PUT websites`
- `GET/POST/PUT website-features`
- `GET/POST/PUT website-sliders`
- `GET website-themes/websites/{websiteId}`
- `PUT website-themes/{id}`
- `GET/POST/DELETE website-carousels`

Response shape dependencies:
- Login requires `response.data.data.accessToken`.
- User list requires `response.data.data.list`.
- Pagination requires `response.data.data.metaData.lastPage`.

## yamak-drivers

Authentication and profile:
- `POST auth/login`
- `PUT drivers/profile`

Vehicle workflow:
- `GET vehicles/owner`
- `POST vehicles/unlink`
- `POST vehicles/link`
- `PUT vehicles/location`

Token behavior:
- Reads `token` from AsyncStorage and sends `Authorization: Bearer <token>`.

## yamak-mobile

Authentication:
- `POST /api/mobile/client/v1/register` is kept as a Laravel compatibility alias because `FormAuthScreen` expects the legacy `{ data, access_token }` response shape.
- `POST auth/login`
- `POST auth/sendCode`
- `POST auth/checkCode`
- `POST auth/verify-email`
- `POST auth/check-reset-password`
- `POST auth/send-reset-password`
- `POST auth/reset-password`
- `POST auth/signup`

Social login:
- `POST auth/signup-social` and `POST auth/login-social` are removed from active client usage for the current phase. Mobile must use email/password auth plus OTP/reset flows only until social providers are explicitly re-enabled.

User/profile:
- `GET app-users/profile`
- `PATCH app-users/profile`
- `DELETE /app-users/account`

User cars:
- `GET user-cars`
- `POST user-cars`
- `DELETE user-cars/{id}`

Core mobile data:
- `GET app-setting`
- `GET services`
- `GET vehicles/{id}`

Push notifications:
- Sends directly to Expo push API: `https://exp.host/--/api/v2/push/send`.

Token behavior:
- Reads `auth` from AsyncStorage and sends `Authorization: Bearer auth.state.token`.

## yamak-web

Public website:
- `GET websites/domains`
- `POST files-upload`

Token behavior:
- Axios interceptor can attach `accessToken` from localStorage, but public website usage may not require it.

## High-Risk Findings

- `yamak-mobile` has one register call using `/api/mobile/client/v1/register`; Laravel keeps this as a compatibility alias until that legacy screen is removed or migrated.
- `yamak-dashboard`, `yamak-mobile`, and `yamak-drivers` have basic Sanctum login/profile smoke coverage.
- `yamak-drivers` now has driver parity coverage for login success/failure, profile get/update, vehicle owner/location/status/link/unlink, legacy prefix alias, and wrong-area token rejection.
- Broader dashboard and mobile authenticated route response testing is still required before switching production traffic.
- Social login buttons and API calls are removed from active clients for the current phase.
- `yamak-drivers` uses `api/driver/v1`, while the NestJS baseline also used `api/driver-app/v1`. Laravel currently supports both roots during migration.
- Client token storage keys differ:
  - dashboard/web: `accessToken`
  - drivers: `token`
  - yamak-mobile: `auth.state.token`
- Do not change response envelope or token fields without updating the affected client immediately.
