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
     * Cache of PWD/Omeka identifier mappings
     *
     * @var array
     */
    protected $mappings = [];

    /**
     * Cache of Omeka item sets
     *
     * @var array
     */
    protected $itemSets = [];

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
            ['Names referenced within War Department documents.', 'dcterms:description', 'literal'],
        ],
        'documents' => [
            ['Documents', 'dcterms:title', 'literal'],
            ['War Department documents.', 'dcterms:description', 'literal'],
        ],
    ];

    /**
     * Document formats, keyed by PWD identifier
     *
     * After modifying this array be sure to reflect the changes in pwd.n3 using
     * the output of self::printDocumentFormatsN3().
     *
     * @var array
     */
    protected $documentFormats = [
        // letter
        5 =>  ['Letter', 'Letter'],
        8 =>  ['LetterAutograph', 'Autograph Letter'],
        44 => ['LetterAutographDraft', 'Autograph Draft Letter'],
        62 => ['LetterAutographDraftSigned', 'Autograph Draft Letter Signed'],
        68 => ['LetterAutographFragment', 'Autograph Letter fragment'],
        60 => ['LetterAutographFragmentSigned', 'Autograph Letter fragment signed'],
        3 =>  ['LetterAutographSigned', 'Autograph Letter Signed'],
        33 => ['LetterAutographUndeterminedType', 'Autograph Letter of Undetermined Type'],
        19 => ['LetterContemporaryCopy', 'Contemporary Copy of Letter'],
        17 => ['LetterContemporaryCopyAuthorFiles', 'Contemporary Copy of Letter made from Author\'s Files'],
        21 => ['LetterContemporaryCopyRecipientFiles', 'Contemporary Copy of Letter made from Recipient\'s Files'],
        35 => ['LetterContemporaryCopySigned', 'Contemporary Copy of Letter Signed'],
        22 => ['LetterDraft', 'Draft Letter'],
        43 => ['LetterDraftSigned', 'Draft Letter Signed'],
        20 => ['LetterExtract', 'Extract of Letter'],
        54 => ['LetterFragment', 'Letter fragment'],
        26 => ['LetterManuscriptTranslated', 'Manuscript Translation of Letter'],
        40 => ['LetterModernCopyTranscribed', 'Printed transcription/modern copy of letter'],
        37 => ['LetterPrintedPublished', 'Printed or published letter'],
        1 =>  ['LetterSigned', 'Letter Signed'],
        41 => ['LetterTranslated', 'Translation (Contemporary or Modern) of Letter'],
        58 => ['LetterTranslated2', 'Translated letter (implies transcription)'],
        56 => ['LetterTyped', 'Typed letter'],
        29 => ['LetterUndeterminedType', 'Letter, Type Undetermined'],
        // document
        6 =>  ['Document', 'Document'],
        25 => ['DocumentAutograph', 'Autograph Document'],
        65 => ['DocumentAutographDraft', 'Autograph Draft Document'],
        7 =>  ['DocumentAutographSigned', 'Autograph Document Signed'],
        55 => ['DocumentAutographDraftSigned', 'Autograph Draft Document Signed'],
        18 => ['DocumentCopy', 'Copy of document'],
        36 => ['DocumentCopySigned', 'Copy of Signed Document'],
        24 => ['DocumentDraft', 'Draft Document'],
        64 => ['DocumentDraftSigned', 'Draft Document Signed'],
        50 => ['DocumentDraftModernCopyHandTranscribed', 'Draft document, hand-written transcription/modern copy'],
        32 => ['DocumentManuscriptTranslated', 'Manuscript Translation of Document'],
        74 => ['DocumentModernCopyPrintTranscribed', 'Printed transcription/modern copy of Document'],
        42 => ['DocumentPrinted', 'Printed Document'],
        46 => ['DocumentPrintedSigned', 'Printed Document Signed'],
        30 => ['DocumentPrintedPublished', 'Printed or published document'],
        2 =>  ['DocumentSigned', 'Document Signed'],
        52 => ['DocumentTranslated', 'Translation (Contemporary or Modern) of Document'],
        57 => ['DocumentTyped', 'Typed Document'],
        31 => ['DocumentUndeterminedType', 'Document, type undetermined'],
        // letter/document
        70 => ['LetterDocumentAutograph', 'Autograph Letter / Autograph Document'],
        71 => ['LetterDocumentAutographSigned', 'Autograph Letter / Autograph Document Signed'],
        66 => ['LetterDocumentAutographSigned2', 'Autograph Letter/Document Signed'],
        38 => ['LetterDocumentCited', 'Cited letter or document'],
        82 => ['LetterDocumentContemporaryCopy', 'Contemporary Printed Copy of Letter/Document (other than PL/PD)'],
        72 => ['LetterDocumentContemporaryCopyUnsigned', 'Letter/Document (contemporary copy, in third hand, unsigned)'],
        73 => ['LetterDocumentDraft', 'Draft Letter / Draft Document'],
        76 => ['LetterDocumentModernCopyPrintTranscribed', 'Modern Printed Transcription of Letter/Document'],
        75 => ['LetterDocumentUndeterminedType', 'Letter/Document Type undetermined'],
        // draft
        47 => ['AutographDraft', 'Autograph Draft'],
        39 => ['AutographDraftSigned', 'Autograph Signed Draft'],
        53 => ['DraftFragment', 'Draft Fragment'],
        // copy
        11 => ['ContemporaryCertifiedCopyAutographSigned', 'Autograph, Contemporaneous or Certified Copy, Signed'],
        27 => ['ContemporaryCertifiedCopy', 'Contemporaneous or Certified Copy (made for information of action)'],
        78 => ['ModernCopyPrintTranscribed', 'Printed or published transcription/modern copy'],
        28 => ['ModernCopyHandTranscribed', 'Transcription/modern copy (hand written)'],
        // extract
        14 => ['Extract', 'Extract'],
        // letterbook
        12 => ['Letterbook', 'Letterbook'],
        23 => ['LetterbookCopy', 'Letterbook Copy'],
        16 => ['LetterbookAuthorCopy', 'Author\'s Letterbook Copy'],
        79 => ['LetterbookAuthorCopyAuthor', 'Author\'s Letterbook Copy, in hand of author'],
        67 => ['LetterbookRecipientCopy', 'Recipient\'s Letterbook Copy'],
        // undetermined
        69 => ['UndeterminedType', 'Type Undetermined'],
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
        $this->createItemSets();

        // Migrate
        $this->migrateRepositories();
        $this->migrateCollections();
        $this->migrateMicrofilms();
        $this->migratePublications();
        $this->migrateNames();
    }

    /**
     * Print document formats in RDF turtle format (N3).
     *
     * Pipe output to "xclip -selection clipboard" to cut-and-paste.
     *
     * @return string
     */
    public function printDocumentFormatsN3()
    {
        foreach ($this->documentFormats as $format) {
            printf(
                ":%s a rdfs:Class ;\n    rdfs:label \"%s\"@en ;\n    rdfs:comment \"%s\"@en .\n\n",
                $format[0],
                implode(' ', array_filter(preg_split('/(?=[A-Z])/', $format[0]))),
                $format[1]
            );
        }
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
     * Create item sets needed for PWD migration.
     */
    public function createItemSets()
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
                'o:item_set' => [
                    'o:id' => $this->itemSets['repositories'],
                ],
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class']['foaf:Organization'],
                ],
            ];

            // dcterms:title
            $title = [$row['repositoryName1'], $row['repositoryName2']];
            $title = implode(': ', array_filter(array_map('trim', $title)));

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
                [$title, 'foaf:name', 'literal'],
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
                'o:item_set' => [
                    'o:id' => $this->itemSets['collections'],
                ],
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class']['bibo:Collection'],
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
                $mapping[] = [$this->mappings['pwd_repositories'][$row['repositoryID']], 'dcterms:publisher', 'resource'];
            }
            $collections[$row['collectionID']] = $this->addValues($data, $mapping);
        }

        $api = $this->services->get('Omeka\ApiManager');
        $response = $api->batchCreate('items', $collections);
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
                'o:item_set' => [
                    'o:id' => $this->itemSets['microfilms'],
                ],
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class']['bibo:CollectedDocument'],
                ],
            ];

            $mapping = [
                [$row['microfilmCitation'], 'dcterms:bibliographicCitation', 'literal'],
                [$row['microfilmShortTitle'], 'dcterms:title', 'literal'],
            ];
            // PWD microfilms without a repository: 55-70,72-81,84-92,96-111,116-120,128
            if ($row['repositoryID']) {
                $mapping[] = [$this->mappings['pwd_repositories'][$row['repositoryID']], 'dcterms:publisher', 'resource'];
            }
            $microfilms[$row['microfilmID']] = $this->addValues($data, $mapping);
        }

        $api = $this->services->get('Omeka\ApiManager');
        $response = $api->batchCreate('items', $microfilms);
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
                'o:item_set' => [
                    'o:id' => $this->itemSets['publications'],
                ],
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class']['dcterms:BibliographicResource'],
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
        $response = $api->batchCreate('items', $publications);
        $this->mapTable('pwd_publications', $response->getContent());
    }

    /**
     * Migrate PWD names into Omeka.
     */
    public function migrateNames()
    {
        $names = [];
        foreach ($this->getTable('names') as $row) {
            $data = [
                'o:item_set' => [
                    'o:id' => $this->itemSets['names'],
                ],
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class']['foaf:Agent'],
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
                [$row['nameLast'], 'vcard:family-name', 'literal'],
                [$row['nameSuffix'], 'vcard:honorific-suffix', 'literal'],
                [$row['nameDescription'], 'dcterms:description', 'literal'],
                [$row['nameNotes'], 'vcard:note', 'literal'],
            ];

            $names[$row['nameID']] = $this->addValues($data, $mapping);
        }

        $api = $this->services->get('Omeka\ApiManager');
        $response = $api->batchCreate('items', $names);
        $this->mapTable('pwd_names', $response->getContent());
    }
}

require 'config.php';
$pwd = new Pwd(PWD_DB_HOST, PWD_DB_NAME, PWD_DB_USERNAME, PWD_DB_PASSWORD, PWD_OMEKA_PATH);
//~ $pwd->printDocumentFormatsN3();
$pwd->migrate();
