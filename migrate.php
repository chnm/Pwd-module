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
     * PWD/Omeka mapping tables.
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
        $this->createMappingTables();
        $this->importVocabs();
        $this->cacheVocabMembers();

        // Migrate
        $this->migrateRepositories();
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
        foreach ($this->vocabs as $vocab) {
            // First check if the vocabulary is already imported.
            $stmt = $conn->prepare('SELECT 1 FROM vocabulary WHERE namespace_uri = ?');
            $stmt->execute([$vocab['vocab']['o:namespace_uri']]);
            if (!$stmt->fetch()) {
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
     * Migrate PWD repositories into Omeka.
     */
    public function migrateRepositories()
    {
        // Migrate PWD repositories.
        $repositories = [];
        foreach ($this->getTable('repositories') as $row) {
            $repository = [
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class']['vcard:Organization'],
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
            if ($title) {
                $repository['dcterms:title'][] = [
                    '@value' => implode(': ', $title),
                    'property_id' => $this->vocabMembers['property']['dcterms:title'],
                    'type' => 'literal',
                ];
            }
            // dcterms:identifier
            if ($row['repositoryMARCOrganizationCode']) {
                // The provided codes don't always match up with the corresponding
                // organization. Even so, corrections can be made post-migration.
                $repository['dcterms:identifier'][] = [
                    '@id' => sprintf(
                        'http://id.loc.gov/vocabulary/organizations/%s',
                        strtolower($row['repositoryMARCOrganizationCode']) // normalized
                    ),
                    'property_id' => $this->vocabMembers['property']['dcterms:identifier'],
                    'type' => 'uri',
                ];
            }
            // vcard:organization-name
            if ($row['repositoryName1']) {
                $repository['vcard:organization-name'][] = [
                    '@value' => $row['repositoryName1'],
                    'property_id' => $this->vocabMembers['property']['vcard:organization-name'],
                    'type' => 'literal',
                ];
            }
            // vcard:organization-unit
            if ($row['repositoryName2']) {
                $repository['vcard:organization-unit'][] = [
                    '@value' => $row['repositoryName2'],
                    'property_id' => $this->vocabMembers['property']['vcard:organization-unit'],
                    'type' => 'literal',
                ];
            }
            // vcard:street-address
            $address = [];
            if ($row['repositoryAddress1']) {
                $address[] = $row['repositoryAddress1'];
            }
            if ($row['repositoryAddress2']) {
                $address[] = $row['repositoryAddress2'];
            }
            if ($address) {
                $repository['vcard:street-address'][] = [
                    '@value' => implode(' ', $address),
                    'property_id' => $this->vocabMembers['property']['vcard:street-address'],
                    'type' => 'literal',
                ];
            }
            // vcard:locality
            if ($row['repositoryCity']) {
                $repository['vcard:locality'][] = [
                    '@value' => $row['repositoryCity'],
                    'property_id' => $this->vocabMembers['property']['vcard:locality'],
                    'type' => 'literal',
                ];
            }
            // vcard:region
            if ($row['repositoryState']) {
                $repository['vcard:region'][] = [
                    '@value' => $row['repositoryState'],
                    'property_id' => $this->vocabMembers['property']['vcard:region'],
                    'type' => 'literal',
                ];
            }
            // vcard:postal-code
            if ($row['repositoryZipCode']) {
                $repository['vcard:postal-code'][] = [
                    '@value' => $row['repositoryZipCode'],
                    'property_id' => $this->vocabMembers['property']['vcard:postal-code'],
                    'type' => 'literal',
                ];
            }
            // foaf:phone
            if ($row['repositoryPhoneNumber']) {
                $repository['foaf:phone'][] = [
                    '@value' => $row['repositoryPhoneNumber'],
                    'property_id' => $this->vocabMembers['property']['foaf:phone'],
                    'type' => 'literal',
                ];
            }
            // vcard:note
            if ($row['repositoryRepositoryNotes']) {
                $repository['vcard:note'][] = [
                    '@value' => $row['repositoryRepositoryNotes'],
                    'property_id' => $this->vocabMembers['property']['vcard:note'],
                    'type' => 'literal',
                ];
            }
            $repositories[$row['repositoryID']] = $repository;
        }
        $api = $this->services->get('Omeka\ApiManager');
        $response = $api->batchCreate('items', $repositories);
        $this->mapTable('pwd_repositories', $response->getContent());
    }
}

require 'config.php';
$pwd = new Pwd(PWD_DB_HOST, PWD_DB_NAME, PWD_DB_USERNAME, PWD_DB_PASSWORD, PWD_OMEKA_PATH);
$pwd->migrate();
