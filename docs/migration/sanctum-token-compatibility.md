# Sanctum Token Compatibility

## Decision

Laravel Sanctum opaque tokens are compatible with the current migrated clients.

Keep the existing response field names (`accessToken` and legacy `access_token` where already supported) and continue sending the token as an `Authorization: Bearer <token>` header. Do not add JWT payload compatibility fields unless a future client audit finds active JWT decoding.

## Audit Scope

Checked active token usage in:

- `mobile-rn/src`
- `yamak-dashboard/src`
- `yamak-drivers/src`

Search terms included:

- `jwt`
- `jwtDecode`
- `jsonwebtoken`
- `decode`
- `atob`
- `Authorization`
- `Bearer`
- `accessToken`
- `access_token`
- `token`

## Findings

- `mobile-rn` stores the login/reset `accessToken` value in auth state and sends it as a bearer token from Axios interceptors. No JWT payload decoding was found in active source.
- `yamak-dashboard` stores `response.data.data.accessToken` in `localStorage`, checks only that `accessToken`, `userData`, and `userAbility` exist, and sends the token as a bearer token. No JWT payload decoding was found in active source.
- `yamak-drivers` stores `data.accessToken` in `AsyncStorage` and sends it as a bearer token from the Axios interceptor. No JWT payload decoding was found in active source.
- `yamak-dashboard/package.json` no longer lists the unused `jwt-decode` dependency.

## Strategy

- Preserve the current Laravel auth response contract.
- Keep Sanctum tokens opaque.
- Keep client auth checks based on stored user/ability data and token presence, not token payload claims.
- Treat future JWT decoding requirements as a new compatibility task instead of silently changing token semantics.

## Verification

Commands run from the workspace root:

```bash
rg -n "jwt|decode|jwtDecode|jwt-decode|jsonwebtoken|atob|Authorization|Bearer|accessToken|access_token|token" mobile-rn/src
rg -n "jwt|decode|jwtDecode|jwt-decode|jsonwebtoken|atob|Authorization|Bearer|accessToken|access_token|token" yamak-dashboard/src
rg -n "jwt|decode|jwtDecode|jwt-decode|jsonwebtoken|atob|Authorization|Bearer|accessToken|access_token|token" yamak-drivers/src
rg -n "jwt|decode|jwtDecode|jwt-decode|jsonwebtoken" mobile-rn yamak-dashboard yamak-drivers -g "package.json" -g "package-lock.json" -g "yarn.lock"
```
