# Relational Schema Design

## Decision

The Laravel target schema is a normalized relational MySQL schema. Legacy MongoDB `_id` values are used only during import to resolve relationships in memory and are not stored as document payloads.

## Naming

- Table names use Laravel snake_case plural names.
- Foreign keys use `{model}_id`.
- Legacy camelCase API fields are mapped at controller/resource boundaries.
- Localized values use explicit columns such as `name_en`, `name_ar`, `about_us_en`, and `about_us_ar`.

## Main Tables

- Auth and dashboard users: `users`, Spatie `roles`, `permissions`, and pivot tables.
- Company structure: `companies`, `branches`, `branch_service`.
- Location lookup: `countries`, `states`, `cities`.
- Services and vehicles: `services`, `drivers`, `vehicles`, `driver_vehicle`, `vehicle_service`.
- Mobile users and requests: `app_users`, `service_requests`, `request_service`, `user_cars`, `user_otps`, `user_notifications`.
- Website content: `websites`, `website_service`, `website_themes`, `website_features`, `website_sliders`, `website_carousels`.
- Marketing/settings: `ads`, `app_settings`.

## Relationship Rules

- Company, branch, driver, vehicle, app user, website, and request ownership is represented with foreign keys.
- Many-to-many relations use pivot tables, not serialized arrays:
  - branches to services: `branch_service`
  - vehicles to drivers: `driver_vehicle`
  - vehicles to services: `vehicle_service`
  - websites to services: `website_service`
  - requests to services: `request_service`
- Website features, sliders, carousels, and themes are child tables, not embedded website documents.
- User cars, OTPs, and notifications are child tables of the mobile user flow, not nested user payloads.

## Indexes And Constraints

- Natural migration keys such as email, app code, domain URL, country code, and relation pivots are indexed or unique where current behavior needs repeatable imports.
- Route filters use indexes on status, type, location ownership, and primary relation keys.
- Soft deletes are kept where the migrated dashboard/mobile behavior needs reversible records.

## Forbidden Patterns

Business tables must not introduce generic document columns:

- `data`
- `payload`
- `meta`
- `document`
- `json_data`

Framework-owned infrastructure tables such as Laravel `failed_jobs` are excluded from this rule because their schema is not used for migrated business data.

## Verification

`tests/Feature/SchemaGovernanceTest.php` migrates the test schema and rejects forbidden generic document columns in business tables.
