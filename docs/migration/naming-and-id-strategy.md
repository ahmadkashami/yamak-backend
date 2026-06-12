# Naming and Legacy ID Strategy

## Summary

This document defines the canonical naming conventions used throughout the Laravel backend and specifies how the legacy MongoDB `_id` field is handled for client compatibility.

---

## 1. Database Column Names — snake_case

All MySQL column names follow Laravel/SQL convention: lowercase `snake_case`.

| Concept | Column name |
|---------|-------------|
| Primary key | `id` |
| User (sys) FK | `user_id` |
| App user FK | `app_user_id` |
| Company FK | `company_id` |
| Branch FK | `branch_id` |
| Driver FK | `driver_id` |
| Vehicle FK | `vehicle_id` |
| Website FK | `website_id` |
| Service FK | `service_id` |
| Country FK | `country_id` |
| State FK | `state_id` |
| City FK | `city_id` |

**Rule:** No camelCase or `PascalCase` column names in MySQL. No generic JSON columns (`data`, `payload`, `meta`, `document`, `json_data`).

---

## 2. API Resource Response Keys — camelCase

All JSON response keys use `camelCase` to match the NestJS baseline that existing clients (mobile app, driver app, admin dashboard, public web) were built against.

| DB column | API key |
|-----------|---------|
| `app_user_id` | `userId` |
| `company_id` | `companyId` |
| `branch_id` | `branchId` |
| `driver_id` | `driverId` |
| `vehicle_id` | `vehicleId` |
| `website_id` | `websiteId` |
| `work_status` | `workStatus` |
| `domain_url` | `domainUrl` |
| `about_us_en` / `about_us_ar` | `aboutUs: { en, ar }` |

---

## 3. Legacy `_id` Field — MongoDB ObjectId Compatibility

The NestJS backend used MongoDB, where every document had an `_id` field of type `ObjectId` (24-character hex string). All four client apps read `_id` from list and detail responses. To avoid a breaking change, **every** resource response in the Laravel backend includes both:

| Field | Type | Value |
|-------|------|-------|
| `id` | integer | MySQL auto-increment primary key |
| `_id` | string | `(string) $model->id` — integer cast to string |

`_id` is purely a legacy compatibility shim. New code should reference `id`. No client update is required when accessing existing resources because both fields are always present.

Example resource array fragment:

```php
return [
    'id'  => $company->id,           // integer: 42
    '_id' => (string) $company->id,  // string: "42"
    'name' => [
        'en' => $company->name_en,
        'ar' => $company->name_ar,
    ],
    ...
];
```

---

## 4. Validation Rules — Inbound FK Fields

When clients POST/PATCH data with FK references they send the camelCase key name:

```json
{ "companyId": 5, "branchId": 12 }
```

Laravel validation rules match these camelCase keys:

```php
'companyId' => ['required', 'integer', 'exists:companies,id'],
'branchId'  => ['required', 'integer', 'exists:branches,id'],
```

The controller then maps `companyId` → `company_id` when writing to the DB.

---

## 5. Scope of Audit

All 19 `*Resource()` methods across Dashboard, Mobile, and Driver controllers have been audited:

- `companyResource`, `serviceResource`, `countryResource`, `stateResource`, `cityResource`
- `branchResource`, `vehicleResource`, `driverResource`
- `websiteResource`, `featureResource`, `sliderResource`, `carouselResource`, `themeResource`
- `requestResource`, `appUserResource`, `appSettingResource`, `adResource`
- `notificationResource`, `carResource`

All return `'_id' => (string) $model->id` alongside integer `'id'`. No client-visible naming change was introduced.

---

## 6. No Client Updates Required

Because the `_id` shim is in place and all camelCase API keys match the NestJS baseline exactly, no client app (yamak-mobile, yamak-drivers, yamak-dashboard, yamak-web) requires changes to its API field references.
