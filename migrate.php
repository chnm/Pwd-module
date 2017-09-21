<?php
require 'config.php';
require 'Migrator.php';

$migrator = new Migrator(PWD_DB_HOST, PWD_DB_NAME, PWD_DB_USERNAME, PWD_DB_PASSWORD, PWD_OMEKA_PATH);

$timeStart = microtime(true);
printf("Execution started: %s\n", date('c'));
echo "------------------------------\n";

// Prepare migration
echo "Reverting Omeka...\n";
$migrator->revertOmeka();
echo "Creating tables...\n";
$migrator->createTables();
echo "Importing vocabularies...\n";
$migrator->importVocabs();
echo "Caching vocabularies...\n";
$migrator->cacheVocabs();
echo "Creating item sets...\n";
$migrator->createItemSets();

// Migrate
echo "Migrating repositories...\n";
$migrator->migrateRepositories();
echo "Migrating collections...\n";
$migrator->migrateCollections();
echo "Migrating microfilms...\n";
$migrator->migrateMicrofilms();
echo "Migrating publications...\n";
$migrator->migratePublications();
echo "Migrating names...\n";
$migrator->migrateNames();
echo "Migrating documents...\n";
$migrator->migrateDocuments(100);

echo "------------------------------\n";
printf("Execution ended: %s\n", date('c'));
printf("Total execution time: %s seconds\n", round(microtime(true) - $timeStart));
printf("Peak memory usage: %s MB\n", round(memory_get_peak_usage() / 1048576));
