# Mongo Dump Seeding

The legacy dump at `../dump/yamak-db` is BSON. Laravel does not parse BSON natively in this project, and MongoDB IDs must not be stored in MySQL.

Install MongoDB Database Tools, then run:

```bash
php artisan yamak:seed-mongo-dump ../dump/yamak-db --convert
```

The command converts each `.bson` file to line-delimited JSON with `bsondump`, then imports the data into normalized relational tables. Legacy Mongo `_id` values are used only in memory during the import to resolve relationships and pivot tables.

If JSON files already exist beside the BSON files, run:

```bash
php artisan yamak:seed-mongo-dump ../dump/yamak-db
```

The importer intentionally skips collections whose JSON file is missing, so partial imports can be tested safely.

The import is repeatable. Existing normalized records are matched by current Laravel keys such as email, app code, domain, website content identity, request party/status, and notification identity. Legacy Mongo `_id` values remain in memory only for the current run while resolving relationships.

After each run the command prints a collection summary with read, imported, and skipped counts. It also warns when required base collections import zero records, which helps catch missing JSON conversion or incomplete dump folders before using the data for parity testing.
