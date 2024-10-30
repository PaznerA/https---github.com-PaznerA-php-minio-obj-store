## About:
This project is currently my playground.
Its a docker based php+rust cli app - containing example usage of "in-memory-db" with btrfs-like storage behind s3 interface (MinIo).



## TODO/WIP:
- in-memory-db for indexing with object metadata storing
- cleanup & complete merging mem-db and minio into single trait
- laravel prompts table for listing data
- complete the termial/consoe app example (CRUD)


## Ideas:
- vim or some text-editor integration?
- framefork agnostic refactor?

## Current "DRUMB?" (rust in-memory DB) API usage:

```

$db = new Connector('/path/to/db');

$count = $db->increment('user/123/visits');

$db->setExpiry('user/123/session', 3600);
$ttl = $db->ttl('user/123/session');

// Remove session of user
$deleted = $db->deleteByPattern('user/123/session/*');
$stats = $db->getDetailedStats();

```
