# Dashboard Authorization Coverage

This document records the current Laravel dashboard authorization behavior for migration parity. It must be updated whenever a dashboard route is added, removed, hardened, or relaxed.

## Current Rule

- Public dashboard routes are limited to health/root and login.
- Protected dashboard routes use both `auth:sanctum` and `api.area:dashboard`.
- `api.area:dashboard` requires the current Sanctum token to have the `dashboard` ability.
- No extra role hardening is applied silently at the route layer yet.
- Super-admin checks that existed inside controllers are preserved inside controller/service logic.

## Route Coverage

| Method | Route | Controller | Current Access | Notes |
| --- | --- | --- | --- | --- |
| GET | `/api/dashboard/v1/` | route closure | Public | Health/root response for migration parity. |
| POST | `/api/dashboard/v1/auth/login` | `Dashboard\AuthController@login` | Public | Creates Sanctum token with `dashboard` ability. |
| POST | `/api/dashboard/v1/auth/logout` | `Dashboard\AuthController@logout` | Authenticated dashboard token | Deletes the current access token. |
| GET | `/api/dashboard/v1/dashboard` | `Dashboard\DashboardController@index` | Authenticated dashboard token | Returns dashboard statistics counters for websites, drivers, companies, services, app users, vehicles, and branches. |
| GET | `/api/dashboard/v1/sys-users/profile` | `Dashboard\AuthController@profile` | Authenticated dashboard token | Used immediately by `yamak-dashboard` after login. |
| PUT | `/api/dashboard/v1/sys-users/profile` | `Dashboard\AuthController@updateProfile` | Authenticated dashboard token | Keeps profile update protected; no role hardening added. |
| GET | `/api/dashboard/v1/sys-users` | `Dashboard\UserController@index` | Authenticated dashboard token | Controller preserves current super-admin scoping behavior. |
| POST | `/api/dashboard/v1/sys-users` | `Dashboard\UserController@store` | Authenticated dashboard token | Controller validates and maps dashboard role names. |
| GET | `/api/dashboard/v1/sys-users/{user}` | `Dashboard\UserController@show` | Authenticated dashboard token | Controller preserves partner/super-admin access check where possible. |
| PUT/PATCH | `/api/dashboard/v1/sys-users/{user}` | `Dashboard\UserController@update` | Authenticated dashboard token | Controller preserves partner/super-admin access check where possible. |
| GET | `/api/dashboard/v1/roles` | `Dashboard\RoleController@index` | Authenticated dashboard token | Controller filters roles for non-super admins. |
| POST | `/api/dashboard/v1/roles` | `Dashboard\RoleController@store` | Authenticated dashboard token | No route-level super-admin gate added yet. |
| GET | `/api/dashboard/v1/roles/{role}` | `Dashboard\RoleController@show` | Authenticated dashboard token | No route-level super-admin gate added yet. |
| PUT/PATCH | `/api/dashboard/v1/roles/{role}` | `Dashboard\RoleController@update` | Authenticated dashboard token | No route-level super-admin gate added yet. |

## Non-Deferred Client Rule

If any route response shape, route path, HTTP method, token field, or authorization requirement changes, update `yamak-dashboard` in the same task and commit. Do not defer client updates.

## Known Risks

- Some legacy controller logic still contains partner/company concepts from an earlier Laravel codebase. These are kept permissive where the current Yamak schema does not have the related tables yet.
- Role hardening beyond current behavior needs a separate approval/task so existing dashboard flows are not silently blocked.
- `sys-users` is backed by Laravel's `users` table, not a Mongo-style `sys_users` table, following the Laravel naming rule.
