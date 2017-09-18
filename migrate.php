<?php
class Pwd
{
    /**
     * The PWD database connection
     *
     * @var PDO
     */
    protected $conn;

    /**
     * The Omeka service manager
     *
     * @var Zend\ServiceManager\ServiceManager
     */
    protected $services;

    /**
     * Cache of vocabulary members.
     *
     * @var array
     */
    protected $vocabMembers = [];

    /**
     * Tables to truncate during Omeka reversion.
     *
     * @var array
     */
    protected $truncateTables = [
        'api_key',
        'asset',
        'item',
        'item_item_set',
        'item_set',
        'job',
        'media',
        'module',
        'password_creation',
        'resource',
        'site',
        'site_block_attachment',
        'site_item_set',
        'site_page',
        'site_page_block',
        'site_permission',
        'site_setting',
        'user_setting',
        'value',
    ];

    /**
     * PWD/Omeka mapping tables
     *
     * @var array
     */
    protected $mappingTables = [
        // mapped to item sets
        'pwd_collections',
        'pwd_microfilms',
        'pwd_publications',
        // mapped to items
        'pwd_repositories',
        'pwd_names',
        'pwd_documents',
        'pwd_images',
    ];

    /**
     * Cache of PWD/Omeka identifier mappings
     *
     * @var array
     */
    protected $mappings = [];

    /**
     * Do not migrate these special PWD repositories (no data to save).
     *
     * Each have corresponding PWD collections:
     *
     * - CITE (3) => Cite only--no image (422)
     * - PRINT (26) => Printed Version only (9)
     * - TYPED (209) => Typed Version only (13)
     * - HAND (210) => Handwritten Transcript only (150)
     * - LISTED (232) => Document listed in Syrett's appendicies (800)
     *
     * @var array
     */
    protected $excludeRepositories = [3, 26, 209, 210, 232];

    /**
     * Vocabularies to import
     *
     * @var array
     */
    protected $vocabs = [
        [
            'strategy' => 'url',
            'options' => [
                'url' => 'http://lov.okfn.org/dataset/lov/vocabs/vcard/versions/2014-05-22.n3',
                'format' => 'turtle',
            ],
            'vocab' => [
                'o:namespace_uri' => 'http://www.w3.org/2006/vcard/ns#',
                'o:prefix' => 'vcard',
                'o:label' => 'vCard Ontology',
                'o:comment' =>  'An ontology for describing people and organizations.',
            ],
        ],
        [
            'strategy' => 'url',
            'options' => [
                'url' => 'http://lov.okfn.org/dataset/lov/vocabs/bio/versions/2011-06-14.n3',
                'format' => 'turtle',
            ],
            'vocab' => [
                'o:namespace_uri' => 'http://purl.org/vocab/bio/0.1/',
                'o:prefix' => 'bio',
                'o:label' => 'BIO',
                'o:comment' =>  'A vocabulary for describing biographical information about people, both living and dead.',
            ],
        ],
        [
            'strategy' => 'url',
            'options' => [
                'url' => 'http://lov.okfn.org/dataset/lov/vocabs/time/versions/2017-04-06.n3',
                'format' => 'turtle',
            ],
            'vocab' => [
                'o:namespace_uri' => 'http://www.w3.org/2006/time#',
                'o:prefix' => 't',
                'o:label' => 'Time Ontology',
                'o:comment' =>  'A vocabulary for defining temporal entities such as time intervals, their properties and relationships.',
            ],
        ],
        [
            'strategy' => 'file',
            'options' => [
                'file' => __DIR__ . '/pwd.n3',
                'format' => 'turtle',
            ],
            'vocab' => [
                'o:namespace_uri' => 'http://wardepartmentpapers.org/vocab#',
                'o:prefix' => 'pwd',
                'o:label' => 'Papers of the War Department',
                'o:comment' =>  null,
            ],
        ],
    ];

    /**
     * @param string $dbHost PWD database host
     * @param string $dbName PWD database name
     * @param string $dbUsername PWD database username
     * @param string $dbPassword PWD database password
     * @param string $omekaPath Omeka path
     */
    public function __construct($dbHost, $dbName, $dbUsername, $dbPassword, $omekaPath)
    {
        // Set the PWD database.
        $dsn = sprintf('mysql:host=%s;dbname=%s', $dbHost, $dbName);
        $this->conn = new PDO($dsn, $dbUsername, $dbPassword);

        // Set the the Omeka service manager.
        require "$omekaPath/bootstrap.php";
        $config = "$omekaPath/application/config/application.config.php";
        $application = Zend\Mvc\Application::init(require $config);
        $this->services = $application->getServiceManager();
    }

    /**
     * Perform the migration.
     */
    public function migrate()
    {
        // Prepare migration
        $this->revertOmeka();
        $this->createMappingTables();
        $this->importVocabs();
        $this->cacheVocabMembers();

        // Migrate
        $this->migrateRepositories();
        $this->migrateCollections();
        $this->migrateMicrofilms();
        $this->migratePublications();
    }

    /**
     * Revert Omeka to a newly installed state.
     */
    public function revertOmeka()
    {
        $conn = $this->services->get('Omeka\Connection');
        $conn->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->truncateTables as $table) {
            $conn->exec(sprintf('TRUNCATE TABLE %s', $table));
        }
        $conn->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Cache vocabulary members (classes and properties).
     */
    public function cacheVocabMembers()
    {
        foreach (['resource_class', 'property'] as $member) {
            $conn = $this->services->get('Omeka\Connection');
            $sql = 'SELECT m.id, m.local_name, v.prefix FROM %s m JOIN vocabulary v ON m.vocabulary_id = v.id';
            $stmt = $conn->query(sprintf($sql, $member));
            $this->vocabMembers[$member] = [];
            foreach ($stmt as $row) {
                $this->vocabMembers[$member][sprintf('%s:%s', $row['prefix'], $row['local_name'])] = $row['id'];
            }
        }
    }

    /**
     * Create PWD/Omeka mapping tables.
     */
    public function createMappingTables()
    {
        $conn = $this->services->get('Omeka\Connection');
        $sql = sprintf('DROP TABLE IF EXISTS %s', implode(',', $this->mappingTables));
        $conn->exec($sql);
        foreach ($this->mappingTables as $table) {
            $sql = sprintf('CREATE TABLE %s (id_pwd int(11) NOT NULL, id_omeka int(11) NOT NULL)', $table);
            $conn->exec($sql);
        }
    }

    /**
     * Import vocabularies needed for PWD migration.
     */
    public function importVocabs()
    {
        $conn = $this->services->get('Omeka\Connection');
        $importer = $this->services->get('Omeka\RdfImporter');
        $api = $this->services->get('Omeka\ApiManager');
        foreach ($this->vocabs as $vocab) {
            // First check if the vocabulary is already imported.
            $stmt = $conn->prepare('SELECT id FROM vocabulary WHERE namespace_uri = ?');
            $stmt->execute([$vocab['vocab']['o:namespace_uri']]);
            $id = $stmt->fetchColumn();
            // Unlike other vocabularies, the PWD vocabulary will change over
            // the course of development. Delete and re-import it during every
            // migration.
            if ($id && 'http://wardepartmentpapers.org/vocab#' === $vocab['vocab']['o:namespace_uri']) {
                $api->delete('vocabularies', $id);
                $id = false;
            }
            if (!$id) {
                $importer->import($vocab['strategy'], $vocab['vocab'], $vocab['options']);
            }
        }
    }

    /**
     * Get the PDO statement for iterating all rows of a PWD table.
     *
     * @param string $table The PWD table name
     * @return PDOStatement
     */
    public function getTable($table)
    {
        return $this->conn->query(sprintf('SELECT * FROM %s', $table));
    }

    /**
     * Map PWD identifiers to Omeka identifiers.
     *
     * @param string $table Mapping table name
     * @param array $content Batch create response content
     */
    public function mapTable($table, $content)
    {
        $insertValues = [];
        foreach ($content as $key => $value) {
            $this->mappings[$table][$key] = $value->id(); // Cache the mappings
            $insertValues[] = $key;
            $insertValues[] = $value->id();
        }
        $sql = sprintf(
            'INSERT INTO %s (id_pwd, id_omeka) VALUES %s',
            $table,
            implode(', ', array_fill(0, count($content), '(?, ?)'))
        );
        $conn = $this->services->get('Omeka\Connection');
        $stmt = $conn->prepare($sql);
        $stmt->execute($insertValues);
    }

    /**
     * Add a value to Omeka resource entity data.
     *
     * @param array $data
     * @param string $value
     * @param string $term
     * @param string $type
     * @return array
     */
    public function addValue(array $data, $value, $term, $type)
    {
        // A value must not be null or an empty string.
        if (null !== $value && '' !== trim($value)) {
            $dataValue = ['property_id' => $this->vocabMembers['property'][$term]];
            switch ($type) {
                case 'uri':
                    $dataValue['type'] = 'uri';
                    $dataValue['@id'] = $value;
                    break;
                case 'resource':
                    $dataValue['type'] = 'resource';
                    $dataValue['value_resource_id'] = $value;
                    break;
                case 'literal':
                default:
                    $dataValue['type'] = 'literal';
                    $dataValue['@value'] = $value;
            }
            $data[$term][] = $dataValue;
        }
        return $data;
    }

    /**
     * Add multiple values to Omeka resource entity data, given mapping
     * instructions.
     *
     * @param array $data
     * @param array $mapping
     * @return array
     */
    public function addValues(array $data, array $mapping)
    {
        foreach ($mapping as $map) {
            $data = $this->addValue($data, $map[0], $map[1], $map[2]);
        }
        return $data;
    }

    /**
     * Migrate PWD repositories into Omeka.
     */
    public function migrateRepositories()
    {
        $repositories = [];
        foreach ($this->getTable('repositories') as $row) {
            if (in_array($row['repositoryID'], $this->excludeRepositories)) {
                continue;
            }
            $data = [
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class']['pwd:Repository'],
                ],
            ];

            // dcterms:title
            $title = [];
            if ($row['repositoryName1']) {
                $title[] = $row['repositoryName1'];
            }
            if ($row['repositoryName2']) {
                $title[] = $row['repositoryName2'];
            }
            $title = $title ? implode(': ', $title) : null;

            // dcterms:identifier
            // The provided codes don't always match up with the corresponding
            // organization. Even so, corrections can be made post-migration.
            $identifier = sprintf(
                'http://id.loc.gov/vocabulary/organizations/%s',
                strtolower($row['repositoryMARCOrganizationCode']) // normalized
            );

            // vcard:street-address
            $address = [];
            if ($row['repositoryAddress1']) {
                $address[] = $row['repositoryAddress1'];
            }
            if ($row['repositoryAddress2']) {
                $address[] = $row['repositoryAddress2'];
            }
            $address = $address ? implode(' ', $address) : null;

            $mapping = [
                [$title, 'dcterms:title', 'literal'],
                [$identifier, 'dcterms:identifier', 'uri'],
                [$row['repositoryName1'], 'vcard:organization-name', 'literal'],
                [$row['repositoryName2'], 'vcard:organization-unit', 'literal'],
                [$address, 'vcard:street-address', 'literal'],
                [$row['repositoryCity'], 'vcard:locality', 'literal'],
                [$row['repositoryState'], 'vcard:region', 'literal'],
                [$row['repositoryZipCode'], 'vcard:postal-code', 'literal'],
                [$row['repositoryPhoneNumber'], 'foaf:phone', 'literal'],
                [$row['repositoryRepositoryNotes'], 'vcard:note', 'literal'],
            ];
            $repositories[$row['repositoryID']] = $this->addValues($data, $mapping);
        }

        $api = $this->services->get('Omeka\ApiManager');
        $response = $api->batchCreate('items', $repositories);
        $this->mapTable('pwd_repositories', $response->getContent());
    }

    /**
     * Migrate PWD collections into Omeka.
     */
    public function migrateCollections()
    {
        $collections = [];
        foreach ($this->getTable('collections') as $row) {
            $data = [
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class']['pwd:Collection'],
                ],
            ];

            // dcterms:title | bibo:shortTitle
            $title = null;
            $shortTitle = null;
            $longName = trim($row['collectionLongName']);
            $shortName = trim($row['collectionShortName']);
            if ($longName && $shortName) {
                $title = $longName;
                if ($longName !== $shortName) {
                    $shortTitle = $shortName;
                }
            }
            if ($longName && !$shortName) {
                $title = $longName;
            }
            if (!$longName && $shortName) {
                $title = $shortName;
            }

            $mapping = [
                [$title, 'dcterms:title', 'literal'],
                [$shortTitle, 'bibo:shortTitle', 'literal'],
            ];
            // PWD collections without a repository: 1, 821. Do not assign
            // excluded repositories.
            if ($row['repositoryID'] && !in_array($row['repositoryID'], $this->excludeRepositories)) {
                $mapping[] = [$this->mappings['pwd_repositories'][$row['repositoryID']], 'dcterms:publisher', 'resource'];
            }
            $collections[$row['collectionID']] = $this->addValues($data, $mapping);
        }

        $api = $this->services->get('Omeka\ApiManager');
        $response = $api->batchCreate('item_sets', $collections);
        $this->mapTable('pwd_collections', $response->getContent());
    }

    /**
     * Migrate PWD microfilms into Omeka.
     */
    public function migrateMicrofilms()
    {
        $microfilms = [];
        foreach ($this->getTable('microfilms') as $row) {
            $data = [
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class']['pwd:Microfilm'],
                ],
            ];

            $mapping = [
                [$row['microfilmCitation'], 'dcterms:bibliographicCitation', 'literal'],
                [$row['microfilmShortTitle'], 'dcterms:title', 'literal'],
            ];
            if ($row['repositoryID']) {
                $mapping[] = [$this->mappings['pwd_repositories'][$row['repositoryID']], 'dcterms:publisher', 'resource'];
            }
            $microfilms[$row['microfilmID']] = $this->addValues($data, $mapping);
        }

        $api = $this->services->get('Omeka\ApiManager');
        $response = $api->batchCreate('item_sets', $microfilms);
        $this->mapTable('pwd_microfilms', $response->getContent());
    }

    /**
     * Migrate PWD publications into Omeka.
     */
    public function migratePublications()
    {
        $publications = [];
        foreach ($this->getTable('publications') as $row) {
            $data = [
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class']['pwd:Publication'],
                ],
            ];

            $creator = [];
            $firstName = trim($row['publicationAuthorFirstName']);
            $lastName = trim($row['publicationAuthorLastName']);
            if ($firstName) {
                $creator[] = $firstName;
            }
            if ($lastName) {
                $creator[] = $lastName;
            }
            $creator = $creator ? implode(' ', $creator) : null;

            $mapping = [
                [$creator, 'dcterms:creator', 'literal'],
                [$row['publicationYear'], 'dcterms:issued', 'literal'],
                [$row['publicationCitation'], 'dcterms:bibliographicCitation', 'literal'],
                [$row['publicationShortTitle'], 'dcterms:title', 'literal'],
            ];

            $publications[$row['publicationID']] = $this->addValues($data, $mapping);
        }

        $api = $this->services->get('Omeka\ApiManager');
        $response = $api->batchCreate('item_sets', $publications);
        $this->mapTable('pwd_publications', $response->getContent());
    }
}

require 'config.php';
$pwd = new Pwd(PWD_DB_HOST, PWD_DB_NAME, PWD_DB_USERNAME, PWD_DB_PASSWORD, PWD_OMEKA_PATH);
$pwd->migrate();
