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
