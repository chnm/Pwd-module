<?php
require 'config.php';
require 'Migrator.php';

function done()
{
    printf(" done (%s MB)\n", round(memory_get_usage() / 1048576));
}

$timeStart = microtime(true);
printf("Execution started: %s\n", date('c'));
echo "------------------------------\n";

echo "Initializing..."; $migrator = new Migrator(
    PWD_DB_HOST, PWD_DB_NAME, PWD_DB_USERNAME, PWD_DB_PASSWORD, PWD_IMAGES_PATH, PWD_OMEKA_PATH
); done();

echo "Preparing migration..."; $migrator->prepareMigration(); done();
echo "Migrating repositories..."; $migrator->migrateRepositories(); done();
echo "Migrating collections..."; $migrator->migrateCollections(); done();
echo "Migrating microfilms..."; $migrator->migrateMicrofilms(); done();
echo "Migrating publications..."; $migrator->migratePublications(); done();
echo "Migrating names..."; $migrator->migrateNames(); done();
echo "Migrating images..."; $migrator->migrateImages(); done();
echo "Migrating documents..."; $migrator->migrateDocuments(); done();
echo "Mapping data..."; $migrator->mapData(); done();
echo "Inserting ingested media..."; $migrator->insertIngestedMedia();; done();

echo "------------------------------\n";
printf("Execution ended: %s\n", date('c'));
printf("Total execution time: %s seconds\n", round(microtime(true) - $timeStart));
printf("Peak memory usage: %s MB\n", round(memory_get_peak_usage() / 1048576));
