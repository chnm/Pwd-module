<?php
require 'config.php';
require 'Migrator.php';

function done()
{
    printf(" done (%s MB)\n", round(memory_get_usage() / 1048576));
}

$migrator = new Migrator(PWD_DB_HOST, PWD_DB_NAME, PWD_DB_USERNAME, PWD_DB_PASSWORD, PWD_OMEKA_PATH);

$timeStart = microtime(true);
printf("Execution started: %s\n", date('c'));
echo "------------------------------\n";

// Prepare migration
echo "Reverting Omeka..."; $migrator->revertOmeka(); done();
echo "Creating tables..."; $migrator->createTables(); done();
echo "Importing vocabularies..."; $migrator->importVocabs(); done();
echo "Caching data..."; $migrator->cacheData(); done();
echo "Creating item sets..."; $migrator->createItemSets(); done();
echo "Creating resource templates..."; $migrator->createResourceTemplates(); done();

// Migrate
echo "Migrating repositories..."; $migrator->migrateRepositories(); done();
echo "Migrating collections..."; $migrator->migrateCollections(); done();
echo "Migrating microfilms..."; $migrator->migrateMicrofilms(); done();
echo "Migrating publications..."; $migrator->migratePublications(); done();
echo "Migrating names..."; $migrator->migrateNames(); done();
echo "Migrating images..."; $migrator->migrateImages(); done();
echo "Migrating documents..."; $migrator->migrateDocuments(1000); done();
echo "Mapping reification data..."; $migrator->mapReificationData(); done();

echo "------------------------------\n";
printf("Execution ended: %s\n", date('c'));
printf("Total execution time: %s seconds\n", round(microtime(true) - $timeStart));
printf("Peak memory usage: %s MB\n", round(memory_get_peak_usage() / 1048576));
