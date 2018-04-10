<?php
/**
 * Migrate Papers of the War Department to Omeka S.
 */
class Migrator
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
     * The path to the PWD large images directory
     *
     * @var string
     */
    protected $imagesPath;

    /**
     * The path to the Omeka installation
     *
     * @var string
     */
    protected $omekaPath;

    /**
     * Cache of PWD table data (name, collection, microfilm, publication)
     *
     * @var array
     */
    protected $tableCache = [];

    /**
     * Cache of PWD/Omeka identifier mappings
     *
     * @var array
     */
    protected $mappings = [];

    /**
     * Cache of Omeka vocabulary members.
     *
     * @var array
     */
    protected $vocabMembers = [];

    /**
     * Cache of Omeka item sets
     *
     * @var array
     */
    protected $itemSets = [];

    /**
     * Cache of Omeka resource templates
     *
     * @var array
     */
    protected $resourceTemplates = [];

    /**
     * Do not migrate these special PWD repositories (no data to save):
     *
     * - 3: CITE
     * - 26: PRINT
     * - 209: TYPED
     * - 210: HAND
     * - 232: LISTED
     *
     * @var array
     */
    protected $excludeRepositories = [3, 26, 209, 210, 232];

    /**
     * Special PWD collections
     *
     * @var array
     */
    protected $specialCollections = [
        9 => 'Printed Versions',
        13 => 'Typed Versions',
        150 => 'Handwritten Transcripts',
        422 => 'Citations',
        800 => 'Listed in Syrett\'s Appendicies',
    ];

    /**
     * Do not migrate these special PWD images:
     *
     * - 4828: "ZZZ00" (placeholder for "Printed Version only")
     * - 18582: "ZZZ01" (unknown use, only two document/collection associations)
     * - 10943: "ZZZ99" (placeholder for "Cite only--no image")
     * - 19115: "ZZZ98" (placeholder for "Document listed in Syrett's appendicies")
     * - 18196: "typescript" (placeholder for "Typed Version only")
     * - 18238: "HAND" (placeholder for "Handwritten Transcript only")
     * - 19124: "CITE" (placeholder for "Cite only--no image")
     * - 18208: "PRINT" (placeholder for "Printed Version only")
     * - 18195: "TEXT" (placeholder for "Handwritten Transcript only")
     * - 18213: "TYPED" (placeholder for "Typed Version only")
     *
     * @var array
     */
    protected $excludeImages = [4828, 18582, 10943, 19115, 18196, 18238, 19124, 18208, 18195, 18213];

    /**
     * Tables to truncate during Omeka reversion
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
        'password_creation',
        'property',
        'resource',
        'resource_class',
        'resource_template',
        'resource_template_property',
        'site',
        'site_block_attachment',
        'site_item_set',
        'site_page',
        'site_page_block',
        'site_permission',
        'site_setting',
        'user_setting',
        'value',
        'vocabulary',
    ];

    /**
     * PWD/Omeka identifier mapping tables
     *
     * @var array
     */
    protected $mappingTables = [
        'pwd_collections',
        'pwd_microfilms',
        'pwd_publications',
        'pwd_repositories',
        'pwd_names',
        'pwd_documents',
        'pwd_images',
    ];

    /**
     * Vocabularies to import
     *
     * Turtle (.n3) files taken from Linked Open Vocabularies. EasyRDF parses
     * turtle files slowly so I've converted them to RDF/XML using the EasyRDF
     * Converter webservice.
     *
     * @see http://lov.okfn.org/dataset/lov/
     * @see http://www.easyrdf.org/converter
     * @var array
     */
    protected $vocabs = [
        [
            'strategy' => 'file',
            'options' => [
                'file' => __DIR__ . '/vocabs/vcard.rdf',
                'format' => 'rdfxml',
            ],
            'vocab' => [
                'o:namespace_uri' => 'http://www.w3.org/2006/vcard/ns#',
                'o:prefix' => 'vcard',
                'o:label' => 'vCard Ontology',
                'o:comment' =>  'An ontology for describing people and organizations.',
            ],
        ],
        [
            'strategy' => 'file',
            'options' => [
                'file' => __DIR__ . '/vocabs/pwd.n3',
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
     * Item sets to create
     *
     * @var array
     */
    protected $itemSetMappings = [
        'repositories' => [
            ['Repositories', 'dcterms:title', 'literal'],
            ['Repositories from which War Department documents were derived.', 'dcterms:description', 'literal'],
        ],
        'collections' => [
            ['Collections', 'dcterms:title', 'literal'],
            ['Collections from which War Department documents were derived.', 'dcterms:description', 'literal'],
        ],
        'microfilms' => [
            ['Microfilms', 'dcterms:title', 'literal'],
            ['Microfilms from which War Department documents were derived.', 'dcterms:description', 'literal'],
        ],
        'publications' => [
            ['Publications', 'dcterms:title', 'literal'],
            ['Publications from which War Department documents were derived.', 'dcterms:description', 'literal'],
        ],
        'names' => [
            ['Names', 'dcterms:title', 'literal'],
            ['People and group names referenced within War Department documents.', 'dcterms:description', 'literal'],
        ],
        'documents' => [
            ['Documents', 'dcterms:title', 'literal'],
            ['War Department documents.', 'dcterms:description', 'literal'],
        ],
        'images' => [
            ['Images', 'dcterms:title', 'literal'],
            ['War Department document images.', 'dcterms:description', 'literal'],
        ],
    ];

    /**
     * Resource templates to create
     *
     * @var array
     */
    protected $resourceTemplateMappings = [
        'repositories' => [
            'label' => 'Repository',
            'resource_class' => 'pwd:Repository',
            'resource_template_property' => [
                'dcterms:title' => ['name', null, 'literal', false],
                'foaf:name' => ['name (copy)', null, 'literal', false],
                'dcterms:identifier' => ['MARC organization code', null, 'literal', false],
                'vcard:organization-name' => [null, null, 'literal', false],
                'vcard:organization-unit' => [null, null, 'literal', false],
                'vcard:street-address' => [null, null, 'literal', false],
                'vcard:locality' => [null, null, 'literal', false],
                'vcard:region' => [null, null, 'literal', false],
                'vcard:postal-code' => [null, null, 'literal', false],
                'foaf:phone' => [null, null, 'literal', false],
                'vcard:note' => [null, null, 'literal', false],
            ],
        ],
        'collections' => [
            'label' => 'Collection',
            'resource_class' => 'pwd:Collection',
            'resource_template_property' => [
                'dcterms:title' => [null, null, 'literal', false],
                'bibo:shortTitle' => [null, null, 'literal', false],
                'pwd:repository' => [null, null, 'resource', false],
            ],
        ],
        'microfilms' => [
            'label' => 'Microfilm',
            'resource_class' => 'pwd:Microfilm',
            'resource_template_property' => [
                'dcterms:title' => [null, null, 'literal', false],
                'dcterms:bibliographicCitation' => [null, null, 'literal', false],
                'pwd:repository' => [null, null, 'resource', false],
            ],
        ],
        'publications' => [
            'label' => 'Publication',
            'resource_class' => 'pwd:Publication',
            'resource_template_property' => [
                'dcterms:title' => [null, null, 'literal', false],
                'dcterms:creator' => ['author', null, 'literal', false],
                'dcterms:issued' => ['published', null, 'literal', false],
                'dcterms:bibliographicCitation' => [null, null, 'literal', false],
            ],
        ],
        'names' => [
            'label' => 'Name',
            'resource_class' => 'pwd:Name',
            'resource_template_property' => [
                'dcterms:title' => ['full name', null, 'literal', false],
                'foaf:name' => ['full name (copy)', null, 'literal', false],
                'dcterms:description' => [null, null, 'literal', false],
                'vcard:note' => [null, null, 'literal', false],
                'vcard:honorific-prefix' => [null, null, 'literal', false],
                'vcard:given-name' => [null, null, 'literal', false],
                'pwd:middleName' => [null, null, 'literal', false],
                'vcard:family-name' => [null, null, 'literal', false],
                'vcard:honorific-suffix' => [null, null, 'literal', false],
            ],
        ],
        'documents' => [
            'label' => 'Document',
            'resource_class' => 'pwd:Document',
            'resource_template_property' => [
                'dcterms:type' => [null, null, 'literal', false],
                'dcterms:title' => [null, null, 'literal', false],
                'dcterms:description' => [null, null, 'literal', false],
                'bibo:shortDescription' => ['short description', null, 'literal', false],
                'pwd:createdYear' => [null, null, 'literal', false],
                'pwd:createdMonth' => [null, null, 'literal', false],
                'pwd:createdDay' => [null, null, 'literal', false],
                'dcterms:creator' => ['author', null, 'resource', false],
                'pwd:secondaryAuthor' => [null, null, 'literal', false],
                'pwd:sentFromLocation' => [null, null, 'literal', false],
                'bibo:recipient' => [null, null, 'resource', false],
                'pwd:secondaryRecipient' => [null, null, 'literal', false],
                'pwd:sentToLocation' => [null, null, 'literal', false],
                'pwd:collection' => [null, null, 'resource', false],
                'pwd:microfilm' => [null, null, 'resource', false],
                'pwd:publication' => [null, null, 'resource', false],
                'pwd:image' => [null, null, 'resource', false],
                'pwd:note' => [null, null, 'literal', false],
                'pwd:contentNote' => [null, null, 'literal', false],
                'pwd:createdNote' => [null, null, 'literal', false],
                'pwd:authorNote' => [null, null, 'literal', false],
                'pwd:recipientNote' => [null, null, 'literal', false],
                'pwd:citedNote' => [null, null, 'literal', false],
                'pwd:notablePersonGroup' => [null, null, 'literal', false],
                'pwd:notableLocation' => [null, null, 'literal', false],
                'pwd:notableItemThing' => [null, null, 'literal', false],
                'pwd:notableIdeaIssue' => [null, null, 'literal', false],
                'pwd:notablePhrase' => [null, null, 'literal', false],
                'pwd:documentNumber' => [null, null, 'literal', false],
                'bibo:pageStart' => [null, null, 'literal', false],
                'bibo:numPages' => [null, null, 'literal', false],
            ],
        ],
        'images' => [
            'label' => 'Image',
            'resource_class' => 'pwd:Image',
            'resource_template_property' => [
                'dcterms:title' => ['name', null, 'literal', false],
                'dcterms:created' => [null, null, 'literal', false],
                'bibo:numPages' => [null, null, 'literal', false],
            ],
        ],
    ];

    /**
     * Initialize the PWD database connection and Omeka application.
     *
     * @param string $dbHost PWD database host
     * @param string $dbName PWD database name
     * @param string $dbUsername PWD database username
     * @param string $dbPassword PWD database password
     * @param string $imagesPath PWD large images path
     * @param string $omekaPath Omeka path
     */
    public function __construct($dbHost, $dbName, $dbUsername, $dbPassword, $imagesPath, $omekaPath)
    {
        $this->imagesPath = $imagesPath;
        $this->omekaPath = $omekaPath;

        // Set the PWD database.
        $dsn = sprintf('mysql:host=%s;dbname=%s', $dbHost, $dbName);
        $this->conn = new PDO($dsn, $dbUsername, $dbPassword);

        // Initialize the Omeka application.
        require "$omekaPath/bootstrap.php";
        $config = "$omekaPath/application/config/application.config.php";
        $application = Omeka\Mvc\Application::init(require $config);
        $this->services = $application->getServiceManager();
    }

    /**
     * Prepare for migration.
     */
    public function prepareMigration()
    {
        $this->prepareApplication();
        $this->prepareFilesystem();
        $this->prepareDatabase();
        $this->prepareVocabularies();
        $this->prepareItemSets();
        $this->prepareResourceTemplates();
        $this->prepareCache();
    }

    /**
     * Prepare the Omeka application for migration.
     */
    public function prepareApplication()
    {
        // Authenticate the administrative user.
        $em = $this->services->get('Omeka\EntityManager');
        $auth = $this->services->get('Omeka\AuthenticationService');
        $auth->getStorage()->write($em->find('Omeka\Entity\User', 1));

        // Verify module dependencies.
        $modules = $this->services->get('Omeka\ModuleManager');
        foreach (['FileSideload'] as $moduleName) {
            $module = $modules->getModule($moduleName);
            if (!$module || 'active' !== $module->getState())  {
                throw new Exception(sprintf('The %s module must be installed.', $moduleName));
            }
        }

        // Configure modules.
        $settings = $this->services->get('Omeka\Settings');
        $settings->set('file_sideload_directory', $this->imagesPath);
        $settings->set('file_sideload_delete_file', 'no');
    }

    /**
     * Prepare the Omeka filesystem for migration.
     */
    public function prepareFilesystem()
    {
        $filesPath = sprintf('%s/files', $this->omekaPath);
        $filesBackupPath = sprintf('%s/files_backup', $this->omekaPath);
        if (!file_exists($filesBackupPath)) {
            rename($filesPath, $filesBackupPath);
        }
    }

    /**
     * Prepare the Omeka database for migration.
     */
    public function prepareDatabase()
    {
        $conn = $this->services->get('Omeka\Connection');

        // Revert Omeka to a newly installed state.
        $conn->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->truncateTables as $table) {
            $conn->exec(sprintf('TRUNCATE TABLE %s', $table));
        }
        $conn->exec('SET FOREIGN_KEY_CHECKS = 1');

        // Create PWD/Omeka mapping tables.
        foreach ($this->mappingTables as $table) {
            $conn->exec("DROP TABLE IF EXISTS $table");
            $conn->exec("
            CREATE TABLE $table (
                id_pwd int(11) NOT NULL,
                id_omeka int(11) DEFAULT NULL,
                FOREIGN KEY (id_omeka) REFERENCES item(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // Create document transcription table.
        $conn->exec('DROP TABLE IF EXISTS pwd_transcriptions');
        $conn->exec('
        CREATE TABLE pwd_transcriptions (
            id_omeka int(11) DEFAULT NULL,
            transcription MEDIUMTEXT COLLATE utf8mb4_unicode_ci,
            FOREIGN KEY (id_omeka) REFERENCES item(id) ON DELETE SET NULL
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Create name instance table.
        $conn->exec('DROP TABLE IF EXISTS pwd_document_name');
        $conn->exec('
        CREATE TABLE pwd_document_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            document_id int(11) DEFAULT NULL,
            name_id int(11) DEFAULT NULL,
            is_author tinyint(1) DEFAULT NULL,
            is_recipient tinyint(1) DEFAULT NULL,
            is_primary tinyint(1) DEFAULT NULL,
            location TEXT COLLATE utf8mb4_unicode_ci,
            notes TEXT COLLATE utf8mb4_unicode_ci,
            PRIMARY KEY (id),
            FOREIGN KEY (document_id) REFERENCES item(id) ON DELETE SET NULL,
            FOREIGN KEY (name_id) REFERENCES item(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Create document instance table.
        $conn->exec("DROP TABLE IF EXISTS pwd_document_instance");
        $conn->exec('
        CREATE TABLE pwd_document_instance (
            id int(11) NOT NULL AUTO_INCREMENT,
            document_id int(11) DEFAULT NULL,
            image_id int(11) DEFAULT NULL,
            source_id int(11) DEFAULT NULL,
            source_type ENUM ("collection", "microfilm", "publication") NOT NULL,
            location TEXT COLLATE utf8mb4_unicode_ci,
            is_primary tinyint(1) DEFAULT NULL,
            page_number int(11) DEFAULT NULL,
            page_count int(11) DEFAULT NULL,
            PRIMARY KEY (id),
            FOREIGN KEY (document_id) REFERENCES item(id) ON DELETE SET NULL,
            FOREIGN KEY (image_id) REFERENCES item(id) ON DELETE SET NULL,
            FOREIGN KEY (source_id) REFERENCES item(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /**
     * Prepare Omeka vocabularies for migration.
     */
    public function prepareVocabularies()
    {
        $importer = $this->services->get('Omeka\RdfImporter');
        $conn = $this->services->get('Omeka\Connection');

        // Import default vocabularies.
        $installTask = new Omeka\Installation\Task\InstallDefaultVocabulariesTask;
        $vocabs = $installTask->getVocabularies();
        foreach ($vocabs as $vocab) {
            $importer->import($vocab['strategy'], $vocab['vocabulary'], [
                'file' => sprintf('%s/application/data/vocabularies/%s', OMEKA_PATH, $vocab['file']),
                'format' => $vocab['format'],
            ]);
        }
        // Import project-specific vocabularies.
        foreach ($this->vocabs as $vocab) {
            $importer->import($vocab['strategy'], $vocab['vocab'], $vocab['options']);
        }

        // Cache vocabulary members (classes and properties).
        foreach (['resource_class', 'property'] as $member) {
            $sql = 'SELECT m.id, m.local_name, v.prefix FROM %s m JOIN vocabulary v ON m.vocabulary_id = v.id';
            $stmt = $conn->query(sprintf($sql, $member));
            $this->vocabMembers[$member] = [];
            foreach ($stmt as $row) {
                $this->vocabMembers[$member][sprintf('%s:%s', $row['prefix'], $row['local_name'])] = $row['id'];
            }
        }
    }

    /**
     * Prepare Omeka item sets for migration.
     */
    public function prepareItemSets()
    {
        $itemSets = [];
        foreach ($this->itemSetMappings as $key => $mapping) {
            $itemSets[$key] = $this->addValues([], $mapping);
        }
        $api = $this->services->get('Omeka\ApiManager');
        $response = $api->batchCreate('item_sets', $itemSets);
        foreach ($response->getContent() as $key => $itemSet) {
            $this->itemSets[$key] = $itemSet->id();
        }
    }

    /**
     * Prepare Omeka resource templates for migration.
     */
    public function prepareResourceTemplates()
    {
        $resTemps = [];
        foreach ($this->resourceTemplateMappings as $key => $mapping) {
            $resTemp = [
                'o:label' => $mapping['label'],
                'o:resource_class' => ['o:id' => $this->vocabMembers['resource_class'][$mapping['resource_class']]],
                'o:resource_template_property' => [],
            ];
            foreach ($mapping['resource_template_property'] as $term => $prop) {
                $resTemp['o:resource_template_property'][] = [
                    'o:property' => ['o:id' => $this->vocabMembers['property'][$term]],
                    'o:alternate_label' => $prop[0],
                    'o:alternate_comment' => $prop[1],
                    'o:data_type' => $prop[2],
                    'o:is_required' => $prop[3],
                ];
            }
            $resTemps[$key] = $resTemp;
        }
        $api = $this->services->get('Omeka\ApiManager');
        $response = $api->batchCreate('resource_templates', $resTemps);
        foreach ($response->getContent() as $key => $resTemp) {
            $this->resourceTemplates[$key] = $resTemp->id();
        }
    }

    /**
     * Prepare cache (in-memory PWD data) for migration.
     */
    public function prepareCache()
    {
        // Cache name instance data.
        foreach ($this->getPwdTable('documents_names') as $row) {
            $this->tableCache['documents_names'][$row['documentID']][] = [
                'nameID' => $row['nameID'],
                'author' => $row['author'],
                'recipient' => $row['recipient'],
                'nameLocation' => $row['nameLocation'],
                'primaryName' => $row['primaryName'],
                'document_nameNotes' => $row['document_nameNotes'],
            ];
        }

        // Cache document instance data.
        foreach (['collection', 'microfilm', 'publication'] as $table) {
            foreach ($this->getPwdTable("documents_{$table}s") as $row) {
                $this->tableCache["documents_{$table}s"][$row['documentID']][] = [
                    "{$table}ID" => $row["{$table}ID"],
                    'imageID' => $row['imageID'],
                    'imagePageNumber' => $row['imagePageNumber'],
                    'pageCount' => $row['pageCount'],
                    "{$table}Location" => $row["{$table}Location"],
                    'primary' . ucfirst($table) => $row['primary' . ucfirst($table)],
                ];
            }
        }
    }

    /**
     * Migrate PWD repositories into Omeka.
     */
    public function migrateRepositories()
    {
        $repositories = [];
        foreach ($this->getPwdTable('repositories') as $row) {
            if (in_array($row['repositoryID'], $this->excludeRepositories)) {
                continue;
            }
            $data = [
                'o:item_set' => [
                    'o:id' => $this->itemSets['repositories'],
                ],
                'o:resource_template' => [
                    'o:id' => $this->resourceTemplates['repositories'],
                ],
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class']['pwd:Repository'],
                ],
            ];

            // dcterms:title
            $title = [$row['repositoryName1'], $row['repositoryName2']];
            $title = implode(': ', array_filter(array_map('trim', $title)));

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
                [$title, 'foaf:name', 'literal'],
                [$row['repositoryMARCOrganizationCode'], 'dcterms:identifier', 'literal'],
                [$row['repositoryName1'], 'vcard:organization-name', 'literal'],
                [$row['repositoryName2'], 'vcard:organization-unit', 'literal'],
                [$address, 'vcard:street-address', 'literal'],
                [$row['repositoryCity'], 'vcard:locality', 'literal'],
                [$row['repositoryState'], 'vcard:region', 'literal'],
                [$row['repositoryZipCode'], 'vcard:postal-code', 'literal'],
                [$row['repositoryPhoneNumber'], 'foaf:phone', 'literal'],
                [$row['repositoryRepositoryNotes'], 'pwd:note', 'literal'],
            ];
            $repositories[$row['repositoryID']] = $this->addValues($data, $mapping);
        }

        $api = $this->services->get('Omeka\ApiManager');
        foreach (array_chunk($repositories, 100, true) as $repositoriesChunk) {
            $response = $api->batchCreate('items', $repositoriesChunk);
            $this->mapTable('pwd_repositories', $response->getContent());
        }
    }

    /**
     * Migrate PWD collections into Omeka.
     */
    public function migrateCollections()
    {
        $collections = [];
        foreach ($this->getPwdTable('collections') as $row) {
            if (isset($this->specialCollections[$row['collectionID']])) {
                $row['collectionLongName'] = $this->specialCollections[$row['collectionID']];
            }
            $data = [
                'o:item_set' => [
                    'o:id' => $this->itemSets['collections'],
                ],
                'o:resource_template' => [
                    'o:id' => $this->resourceTemplates['collections'],
                ],
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
            // PWD collections without a repository: 1, 821. Do not assign excluded repositories.
            if ($row['repositoryID'] && !in_array($row['repositoryID'], $this->excludeRepositories)) {
                $mapping[] = [$this->mappings['pwd_repositories'][$row['repositoryID']], 'pwd:repository', 'resource'];
            }
            $collections[$row['collectionID']] = $this->addValues($data, $mapping);
        }

        $api = $this->services->get('Omeka\ApiManager');
        foreach (array_chunk($collections, 100, true) as $collectionsChunk) {
            $response = $api->batchCreate('items', $collectionsChunk);
            $this->mapTable('pwd_collections', $response->getContent());
        }
    }

    /**
     * Migrate PWD microfilms into Omeka.
     */
    public function migrateMicrofilms()
    {
        $microfilms = [];
        foreach ($this->getPwdTable('microfilms') as $row) {
            $data = [
                'o:item_set' => [
                    'o:id' => $this->itemSets['microfilms'],
                ],
                'o:resource_template' => [
                    'o:id' => $this->resourceTemplates['microfilms'],
                ],
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class']['pwd:Microfilm'],
                ],
            ];

            $mapping = [
                [$row['microfilmCitation'], 'dcterms:bibliographicCitation', 'literal'],
                [$row['microfilmShortTitle'], 'dcterms:title', 'literal'],
            ];
            // PWD microfilms without a repository: 55-70,72-81,84-92,96-111,116-120,128
            if ($row['repositoryID']) {
                $mapping[] = [$this->mappings['pwd_repositories'][$row['repositoryID']], 'pwd:repository', 'resource'];
            }
            $microfilms[$row['microfilmID']] = $this->addValues($data, $mapping);
        }

        $api = $this->services->get('Omeka\ApiManager');
        foreach (array_chunk($microfilms, 100, true) as $microfilmsChunk) {
            $response = $api->batchCreate('items', $microfilmsChunk);
            $this->mapTable('pwd_microfilms', $response->getContent());
        }
    }

    /**
     * Migrate PWD publications into Omeka.
     */
    public function migratePublications()
    {
        $publications = [];
        foreach ($this->getPwdTable('publications') as $row) {
            $data = [
                'o:item_set' => [
                    'o:id' => $this->itemSets['publications'],
                ],
                'o:resource_template' => [
                    'o:id' => $this->resourceTemplates['publications'],
                ],
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class']['pwd:Publication'],
                ],
            ];

            $creator = [$row['publicationAuthorFirstName'], $row['publicationAuthorLastName']];
            $creator = implode(' ', array_filter(array_map('trim', $creator)));

            $mapping = [
                [$creator, 'dcterms:creator', 'literal'],
                [$row['publicationYear'], 'dcterms:issued', 'literal'],
                [$row['publicationCitation'], 'dcterms:bibliographicCitation', 'literal'],
                [$row['publicationShortTitle'], 'dcterms:title', 'literal'],
            ];

            $publications[$row['publicationID']] = $this->addValues($data, $mapping);
        }

        $api = $this->services->get('Omeka\ApiManager');
        foreach (array_chunk($publications, 100, true) as $publicationsChunk) {
            $response = $api->batchCreate('items', $publicationsChunk);
            $this->mapTable('pwd_publications', $response->getContent());
        }
    }

    /**
     * Migrate PWD names into Omeka.
     */
    public function migrateNames()
    {
        $names = [];
        foreach ($this->getPwdTable('names') as $row) {
            $data = [
                'o:item_set' => [
                    'o:id' => $this->itemSets['names'],
                ],
                'o:resource_template' => [
                    'o:id' => $this->resourceTemplates['names'],
                ],
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class']['pwd:Name'],
                ],
            ];

            $title = [
                $row['nameTitle'], $row['nameFirst'], $row['nameMiddle'],
                $row['nameLast'], $row['nameSuffix']
            ];
            $title = implode(' ', array_filter(array_map('trim', $title)));

            $mapping = [
                [$title, 'dcterms:title', 'literal'],
                [$title, 'foaf:name', 'literal'],
                [$row['nameTitle'], 'vcard:honorific-prefix', 'literal'],
                [$row['nameFirst'], 'vcard:given-name', 'literal'],
                [$row['nameMiddle'], 'pwd:middleName', 'literal'],
                [$row['nameLast'], 'vcard:family-name', 'literal'],
                [$row['nameSuffix'], 'vcard:honorific-suffix', 'literal'],
                [$row['nameDescription'], 'dcterms:description', 'literal'],
                [$row['nameNotes'], 'vcard:note', 'literal'],
            ];

            $names[$row['nameID']] = $this->addValues($data, $mapping);
        }

        $api = $this->services->get('Omeka\ApiManager');
        foreach (array_chunk($names, 100, true) as $namesChunk) {
            $response = $api->batchCreate('items', $namesChunk);
            $this->mapTable('pwd_names', $response->getContent());
        }
    }

    /**
     * Migrate PWD images into Omeka.
     *
     * Ingest media *once* then use backups of `media` and `pwd_images` to
     * insert media back into the database after the last migration process.
     * Then move a backup of the files/ directory back to its original location.
     * This avoids repeating the lengthy derivative creation process, which can
     * take over 24 hours to complete.
     *
     * Disable "Ingest media" block after it's been run once.
     *
     * @param bool $ingestMedia Whether to ingest media (very long process)
     * @param int $ingestLimit Limit how many items should ingest their media
     */
    public function migrateImages($ingestMedia = false, $ingestLimit = null)
    {
        if ($ingestMedia)  {
            $imageFiles = [];
            foreach ($this->getPwdTable('imageFiles') as $row) {
                $imageFiles[$row['imageID']][] = $row['imageFilePath'];
            }
        }

        $images = [];
        foreach ($this->getPwdTable('images') as $index => $row) {
            if (in_array($row['imageID'], $this->excludeImages)) {
                continue;
            }

            $data = [
                'o:item_set' => [
                    'o:id' => $this->itemSets['images'],
                ],
                'o:resource_template' => [
                    'o:id' => $this->resourceTemplates['images'],
                ],
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class']['pwd:Image'],
                ],
            ];

            if ($ingestMedia && (null === $ingestLimit || $index <= $ingestLimit)) {
                $files = $imageFiles[$row['imageID']] ?? [];
                natcasesort($files);
                foreach ($files as $file) {
                    $data['o:media'][] = [
                        'o:ingester' => 'sideload',
                        'ingest_filename' => $file,
                    ];
                }
            }

            $mapping = [
                [$row['imageName'], 'dcterms:title', 'literal'],
                [$row['imagePageCount'], 'bibo:numPages', 'literal'],
            ];

            $images[$row['imageID']] = $this->addValues($data, $mapping);
        }

        $api = $this->services->get('Omeka\ApiManager');
        foreach (array_chunk($images, 100, true) as $imagesChunk) {
            $response = $api->batchCreate('items', $imagesChunk);
            $this->mapTable('pwd_images', $response->getContent());
        }
    }

    /**
     * Insert previously ingested media into the media table.
     *
     * Assumes all images have already been ingested and that there are backups
     * of the `media` and `pwd_images` tables from when the ingestion took
     * place.
     *
     * Disable this method when ingesting media.
     */
    public function insertIngestedMedia()
    {
        $conn = $this->services->get('Omeka\Connection');

        $pwdImagesBackup = [];
        foreach ($conn->query('SELECT * FROM pwd_images_backup') as $row) {
            $pwdImagesBackup[$row['id_omeka']] = $row['id_pwd'];
        }
        $conn->beginTransaction();
        foreach ($conn->query('SELECT * FROM media_backup') as $row) {
            $imageId = $pwdImagesBackup[$row['item_id']];
            if (in_array($imageId, $this->excludeImages)) {
                continue;
            }

            $sql = '
            INSERT INTO resource (
                resource_type, is_public, created, modified
            ) VALUES (
                ?, 1, NOW(), NOW()
            )';
            $stmt = $conn->prepare($sql);
            $stmt->execute(['Omeka\Entity\Media']);

            $sql = '
            INSERT INTO media (
                id, item_id, source, storage_id, sha256, position, ingester, renderer, media_type, extension, has_original, has_thumbnails
            ) VALUES (
                ?, ?, ?, ?, ?, ?, "sideload", "file", "image/jpeg", "jpg", 1, 1
            )';
            $insertValues = [
                $conn->lastInsertId(),
                $this->mappings['pwd_images'][$imageId],
                $row['source'],
                $row['storage_id'],
                $row['sha256'],
                $row['position']
            ];
            $stmt = $conn->prepare($sql);
            $stmt->execute($insertValues);
        }
        $conn->commit();
    }

    /**
     * Migrate PWD documents into Omeka.
     */
    public function migrateDocuments()
    {
        $citeCodes = [];
        foreach ($this->getPwdTable('citeCodes') as $row) {
            $citeCodes[$row['citeCodeID']] = $row['citeCodeName'];
        }
        $documentFormats = [];
        foreach ($this->getPwdTable('documentFormats') as $row) {
            $documentFormats[$row['documentFormatID']] = $row['documentFormatName'];
        }

        $documents = [];
        $transcriptions = [];
        foreach ($this->getPwdTable('documents') as $index => $row) {
            $data = [
                'o:item_set' => [
                    'o:id' => $this->itemSets['documents'],
                ],
                'o:resource_template' => [
                    'o:id' => $this->resourceTemplates['documents'],
                ],
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class']['pwd:Document'],
                ],
            ];

            $mapping = [
                [$row['documentNumber'], 'pwd:documentNumber', 'literal'],
                [$row['documentImagePageNumber'], 'bibo:pageStart', 'literal'],
                [$row['documentDateNotes'], 'pwd:createdNote', 'literal'],
                [$row['documentTitle'], 'dcterms:title', 'literal'],
                [$row['documentNotes'], 'pwd:note', 'literal'],
                [$row['documentPageCount'], 'bibo:numPages', 'literal'],
                [$row['documentFullGist'], 'dcterms:description', 'literal'],
                [$row['documentShortGist'], 'bibo:shortDescription', 'literal'],
                [$row['documentContentNotes'], 'pwd:contentNote', 'literal'],
                [$row['documentOtherAuthors'], 'pwd:authorNote', 'literal'],
                [$row['documentOtherRecipients'], 'pwd:recipientNote', 'literal']
            ];

            // Note the omission of the legacy "documentDate" in favor of the
            // separate (and more accurate) dates below. "9999" and "99" were
            // used as placeholders for an unknown year, month, or day. Here an
            // omitted property implies an unknown date.
            if ('9999' !== $row['documentDateYear']) {
                $mapping[] = [$row['documentDateYear'], 'pwd:createdYear', 'literal'];
            }
            if ('99' !== $row['documentDateMonth']) {
                $mapping[] = [$row['documentDateMonth'], 'pwd:createdMonth', 'literal'];
            }
            if ('99' !== $row['documentDateDay']) {
                $mapping[] = [$row['documentDateDay'], 'pwd:createdDay', 'literal'];
            }

            // Do not migrate citeCodeID #1 ("Document in hand or verified in a
            // collection, not a cited document").
            if ($row['documentCiteCodeID'] && 1 != $row['documentCiteCodeID']) {
                $mapping[] = [$citeCodes[$row['documentCiteCodeID']], 'pwd:citedNote', 'literal'];
            }
            if ($row['documentFormatID']) {
                $mapping[] = [$documentFormats[$row['documentFormatID']], 'dcterms:type', 'literal'];
            }
            foreach (explode(';', $row['documentPersonsGroups']) as $value) {
                $mapping[] = [$value, 'pwd:notablePersonGroup', 'literal'];
            }
            foreach (explode(';', $row['documentLocations']) as $value) {
                $mapping[] = [$value, 'pwd:notableLocation', 'literal'];
            }
            foreach (explode(';', $row['documentItemsThings']) as $value) {
                $mapping[] = [$value, 'pwd:notableItemThing', 'literal'];
            }
            foreach (explode(';', $row['documentIdeasIssuesEtc']) as $value) {
                $mapping[] = [$value, 'pwd:notableIdeaIssue', 'literal'];
            }
            foreach (explode(';', $row['documentPhrases']) as $value) {
                $mapping[] = [$value, 'pwd:notablePhrase', 'literal'];
            }

            // Map names. Note that we don't map names that don't exist and that
            // we don't map locations more than once.
            $names = $this->tableCache['documents_names'][$row['documentID']] ?? [];
            $sentFromLocationsToMap = [];
            $sentToLocationsToMap = [];
            foreach ($names as $value) {
                $oNameId = $this->mappings['pwd_names'][$value['nameID']] ?? null;
                if ($oNameId) {
                    $term = $value['author']
                        ? ($value['primaryName'] ? 'dcterms:creator' : 'pwd:secondaryAuthor')
                        : ($value['primaryName'] ? 'bibo:recipient' : 'pwd:secondaryRecipient');
                    $mapping[] = [$oNameId, $term, 'resource'];
                }
                if ($value['nameLocation']) {
                    if ($value['author']) {
                        $sentFromLocationsToMap[$value['nameLocation']] = 'pwd:sentFromLocation';
                    } else {
                        $sentToLocationsToMap[$value['nameLocation']] = 'pwd:sentToLocation';
                    }
                }
            }
            foreach ($sentFromLocationsToMap as $key => $value) {
                $mapping[] = [$key, $value, 'literal'];
            }
            foreach ($sentToLocationsToMap as $key => $value) {
                $mapping[] = [$key, $value, 'literal'];
            }

            // Map resources (documents, microfilms, publications, and images).
            // Note that we don't map resources that don't exist and that we
            // don't map an individual resource more than once.
            $resourceVars = [
                ['documents_collections', 'pwd_collections', 'collectionID', 'pwd:collection'],
                ['documents_microfilms', 'pwd_microfilms', 'microfilmID', 'pwd:microfilm'],
                ['documents_publications', 'pwd_publications', 'publicationID', 'pwd:publication'],
            ];
            $resourcesToMap = [];
            foreach ($resourceVars as $resourceVar) {
                $resources = $this->tableCache[$resourceVar[0]][$row['documentID']] ?? [];
                foreach ($resources as $value) {
                    $oId = $this->mappings[$resourceVar[1]][$value[$resourceVar[2]]] ?? null;
                    if ($oId) {
                        $resourcesToMap[$oId] = $resourceVar[3];
                    }
                    $oId = $this->mappings['pwd_images'][$value['imageID']] ?? null;
                    if ($oId) {
                        $resourcesToMap[$oId] = 'pwd:image';
                    }
                }
            }
            foreach ($resourcesToMap as $key => $value) {
                $mapping[] = [$key, $value, 'resource'];
            }

            $documents[$row['documentID']] = $this->addValues($data, $mapping);
            $transcriptions[$row['documentID']] = $row['documentTranscription'];
        }

        $api = $this->services->get('Omeka\ApiManager');

        foreach (array_chunk($documents, 100, true) as $documentsChunk) {
            $response = $api->batchCreate('items', $documentsChunk);
            $this->mapTable('pwd_documents', $response->getContent());

            // Map and save document transcription data.
            $tokenCount = 0;
            $insertValues = [];
            foreach ($response->getContent() as $key => $value) {
                if ($transcriptions[$key]) {
                    $insertValues[] = $value->id();
                    $insertValues[] = utf8_encode($transcriptions[$key]);
                    $tokenCount++;
                }
            }
            if ($tokenCount) {
                $sql = sprintf(
                    'INSERT INTO pwd_transcriptions (id_omeka, transcription) VALUES %s',
                    implode(', ', array_fill(0, $tokenCount, '(?, ?)'))
                );
                $conn = $this->services->get('Omeka\Connection');
                $stmt = $conn->prepare($sql);
                $stmt->execute($insertValues);
            }
        }
    }

    /**
     * Map name and document data.
     */
    public function mapData()
    {
        $conn = $this->services->get('Omeka\Connection');

        // Map document name table.
        $tokenCount = 0;
        $insertValues = [];
        foreach ($this->tableCache['documents_names'] as $key => $values) {
            foreach ($values as $value) {
                $insertValues[] = $this->mappings['pwd_documents'][$key];
                $insertValues[] = $this->mappings['pwd_names'][$value['nameID']] ?? null;
                $insertValues[] = $value['author'];
                $insertValues[] = $value['recipient'];
                $insertValues[] = $value['primaryName'];
                $insertValues[] = $value['nameLocation'] ? utf8_encode($value['nameLocation']) : null;
                $insertValues[] = $value['document_nameNotes'] ? utf8_encode($value['document_nameNotes']) : null;
                $tokenCount++;
            }
        }
        $sql = sprintf(
            'INSERT INTO pwd_document_name (
                document_id, name_id, is_author, is_recipient, is_primary, location, notes
            ) VALUES %s',
            implode(', ', array_fill(0, $tokenCount, '(?, ?, ?, ?, ?, ?, ?)'))
        );
        $stmt = $conn->prepare($sql);
        $stmt->execute($insertValues);

        // Map document instance tables.
        foreach (['collection', 'microfilm', 'publication'] as $table) {
            $tokenCount = 0;
            $insertValues = [];
            foreach ($this->tableCache["documents_{$table}s"] as $key => $values) {
                if (!isset($this->mappings['pwd_documents'][$key])) {
                    // Documents listed in instance tables may not exist.
                    continue;
                }
                foreach ($values as $value) {
                    $insertValues[] = $this->mappings['pwd_documents'][$key];
                    $insertValues[] = $this->mappings['pwd_images'][$value['imageID']] ?? null;
                    $insertValues[] = $this->mappings["pwd_{$table}s"][$value["{$table}ID"]] ?? null;
                    $insertValues[] = $table;
                    $insertValues[] = $value["{$table}Location"] ? utf8_encode($value["{$table}Location"]) : null;
                    $insertValues[] = $value['primary' . ucfirst($table)];
                    $insertValues[] = $value['imagePageNumber'];
                    $insertValues[] = $value['pageCount'];
                    $tokenCount++;
                }
            }
            $sql = sprintf(
                "INSERT INTO pwd_document_instance (
                    document_id, image_id, source_id, source_type, location, is_primary, page_number, page_count
                ) VALUES %s",
                implode(', ', array_fill(0, $tokenCount, '(?, ?, ?, ?, ?, ?, ?, ?)'))
            );
            $stmt = $conn->prepare($sql);
            $stmt->execute($insertValues);
        }
    }

    /**
     * Get the PDO statement for iterating all rows of a PWD table.
     *
     * @param string $table The PWD table name
     * @return PDOStatement
     */
    protected function getPwdTable($table)
    {
        return $this->conn->query(sprintf('SELECT * FROM %s', $table));
    }

    /**
     * Map PWD identifiers to Omeka identifiers.
     *
     * @param string $table Mapping table name
     * @param array $content Batch create response content
     */
    protected function mapTable($table, $content)
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
    protected function addValue(array $data, $value, $term, $type)
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
                    // Encode ISO-8859-1 strings to UTF-8.
                    $dataValue['@value'] = utf8_encode($value);
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
    protected function addValues(array $data, array $mapping)
    {
        foreach ($mapping as $map) {
            $data = $this->addValue($data, $map[0], $map[1], $map[2]);
        }
        return $data;
    }
}
