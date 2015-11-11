# silverstripe-restoredb
Provides a /dev/task/RestoreDatabaseTask?db=database.sql.gz to replace existing database. It does not rely on mysql client or run exec commands to restore the database. Purely PHP based restore. Deletes all existing tables.
