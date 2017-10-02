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
     * Cache of vocabulary members.
     *
     * @var array
     */
    protected $vocabMembers = [];

    /**
     * Cache of reification table data.
     *
     * @var array
     */
    protected $reificationData = [];

    /**
     * Cache of PWD image files.
     *
     * @var array
     */
    protected $imageFiles = [];

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
     * Do not migrate these special PWD collections:
     *
     * - 9: "Printed Version only" (assumed by existence of pwd:publication)
     * - 13: "Typed Version only" (unknown use, likely obsolete)
     * - 150: "Handwritten Transcript only" (unknown use, likely obsolete)
     * - 422: "Cite only--no image" (assumed by existence of pwd:citedNote)
     * - 800: "Document listed in Syrett's appendicies" (assumed by content of pwd:note)
     *
     * @var array
     */
    protected $excludeCollections = [9, 13, 150, 422, 800];

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
                'file' => __DIR__ . '/vocabs/bio.rdf',
                'format' => 'rdfxml',
            ],
            'vocab' => [
                'o:namespace_uri' => 'http://purl.org/vocab/bio/0.1/',
                'o:prefix' => 'bio',
                'o:label' => 'BIO',
                'o:comment' =>  'A vocabulary for describing biographical information about people, both living and dead.',
            ],
        ],
        [
            'strategy' => 'file',
            'options' => [
                'file' => __DIR__ . '/vocabs/time.rdf',
                'format' => 'rdfxml',
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
            ['Agents', 'dcterms:title', 'literal'],
            ['People and groups referenced within War Department documents.', 'dcterms:description', 'literal'],
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
            'resource_class' => 'foaf:Organization',
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
            'resource_class' => 'bibo:Collection',
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
            'resource_class' => 'dcterms:BibliographicResource',
            'resource_template_property' => [
                'dcterms:title' => [null, null, 'literal', false],
                'dcterms:creator' => ['author', null, 'literal', false],
                'dcterms:issued' => ['published', null, 'literal', false],
                'dcterms:bibliographicCitation' => [null, null, 'literal', false],
            ],
        ],
        'names' => [
            'label' => 'Agent',
            'resource_class' => 'foaf:Agent',
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
            'resource_class' => 'bibo:Document',
            'resource_template_property' => [
                'dcterms:title' => [null, null, 'literal', false],
                'dcterms:description' => [null, null, 'literal', false],
                'bibo:shortDescription' => ['short description', null, 'literal', false],
                'dcterms:created' => [null, null, 'literal', false],
                'dcterms:creator' => ['author', null, 'literal', false],
                'pwd:secondaryAuthor' => [null, null, 'literal', false],
                'bibo:recipient' => [null, null, 'literal', false],
                'pwd:secondaryRecipient' => [null, null, 'literal', false],
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
                'pwd:notableAgent' => [null, null, 'literal', false],
                'pwd:notableLocation' => [null, null, 'literal', false],
                'pwd:notableItemThing' => [null, null, 'literal', false],
                'pwd:notableIdeaIssue' => [null, null, 'literal', false],
                'pwd:notablePhrase' => [null, null, 'literal', false],
                'pwd:documentNumber' => [null, null, 'literal', false],
                't:year' => ['year created', null, 'literal', false],
                't:month' => ['month created', null, 'literal', false],
                't:day' => ['day created', null, 'literal', false],
                'bibo:pageStart' => [null, null, 'literal', false],
                'bibo:numPages' => [null, null, 'literal', false],
            ],
        ],
        'images' => [
            'label' => 'Image',
            'resource_class' => 'bibo:Image',
            'resource_template_property' => [
                'dcterms:title' => ['name', null, 'literal', false],
                'dcterms:created' => [null, null, 'literal', false],
                'bibo:numPages' => [null, null, 'literal', false],
            ],
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

        // Initialize the Omeka application.
        require "$omekaPath/bootstrap.php";
        $config = "$omekaPath/application/config/application.config.php";
        $application = Omeka\Mvc\Application::init(require $config);
        $this->services = $application->getServiceManager();

        // Authenticate the administrative user.
        $user = $this->services->get('Omeka\EntityManager')->find('Omeka\Entity\User', 1);
        $this->services->get('Omeka\AuthenticationService')->getStorage()->write($user);

        // Verify module dependencies.
        $modules = $this->services->get('Omeka\ModuleManager');
        foreach (['FileSideload'] as $moduleName) {
            $module = $modules->getModule($moduleName);
            if (!$module || 'active' !== $module->getState())  {
                throw new Exception(sprintf('The %s module must be installed.', $moduleName));
            }
        }
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
     * Create Omeka tables needed for PWD migration.
     */
    public function createTables()
    {
        $conn = $this->services->get('Omeka\Connection');

        // Create PWD/Omeka mapping tables.
        $sql = sprintf('DROP TABLE IF EXISTS %s', implode(',', $this->mappingTables));
        $conn->exec($sql);
        foreach ($this->mappingTables as $table) {
            $sql = sprintf('CREATE TABLE %s (id_pwd int(11) NOT NULL, id_omeka int(11) NOT NULL)', $table);
            $conn->exec($sql);
        }

        // Create document transcription table.
        $conn->exec('DROP TABLE IF EXISTS pwd_transcriptions');
        $conn->exec('CREATE TABLE pwd_transcriptions (
            id_omeka int(11) NOT NULL,
            nominate tinyint(1) DEFAULT NULL,
            transcription MEDIUMTEXT COLLATE utf8mb4_unicode_ci
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Create documents/names reification table.
        $conn->exec('DROP TABLE IF EXISTS pwd_document_name');
        $conn->exec('CREATE TABLE pwd_document_name (
            document_id int(11) NOT NULL,
            name_id int(11) DEFAULT NULL,
            is_author tinyint(1) DEFAULT NULL,
            is_recipient tinyint(1) DEFAULT NULL,
            is_primary tinyint(1) DEFAULT NULL,
            location TEXT COLLATE utf8mb4_unicode_ci,
            notes TEXT COLLATE utf8mb4_unicode_ci
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Create document/image reification tables.
        foreach (['collection', 'microfilm', 'publication'] as $table) {
            $conn->exec("DROP TABLE IF EXISTS pwd_document_$table");
            $conn->exec("CREATE TABLE pwd_document_{$table} (
                document_id int(11) NOT NULL,
                {$table}_id int(11) DEFAULT NULL,
                image_id int(11) DEFAULT NULL,
                is_primary tinyint(1) DEFAULT NULL,
                page_number int(11) DEFAULT NULL,
                page_count int(11) DEFAULT NULL,
                location TEXT COLLATE utf8mb4_unicode_ci
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    }

    /**
     * Import vocabularies needed for PWD migration.
     */
    public function importVocabs()
    {
        $importer = $this->services->get('Omeka\RdfImporter');

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
            printf("\n\t- importing vocab %s", $vocab['vocab']['o:namespace_uri']);
            $importer->import($vocab['strategy'], $vocab['vocab'], $vocab['options']);
        }
    }

    /**
     * Cache data.
     */
    public function cacheData()
    {
        // Cache vocabulary data (classes and properties).
        $conn = $this->services->get('Omeka\Connection');
        foreach (['resource_class', 'property'] as $member) {
            $sql = 'SELECT m.id, m.local_name, v.prefix FROM %s m JOIN vocabulary v ON m.vocabulary_id = v.id';
            $stmt = $conn->query(sprintf($sql, $member));
            $this->vocabMembers[$member] = [];
            foreach ($stmt as $row) {
                $this->vocabMembers[$member][sprintf('%s:%s', $row['prefix'], $row['local_name'])] = $row['id'];
            }
        }

        // Cache document/name reification data.
        foreach ($this->getTable('documents_names') as $row) {
            $this->reificationData['documents_names'][$row['documentID']][] = [
                'nameID' => $row['nameID'],
                'author' => $row['author'],
                'recipient' => $row['recipient'],
                'nameLocation' => $row['nameLocation'],
                'primaryName' => $row['primaryName'],
                'document_nameNotes' => $row['document_nameNotes'],
            ];
        }

        // Cache document/image refication data.
        foreach (['collection', 'microfilm', 'publication'] as $table) {
            foreach ($this->getTable("documents_{$table}s") as $row) {
                $this->reificationData["documents_{$table}s"][$row['documentID']][] = [
                    "{$table}ID" => $row["{$table}ID"],
                    'imageID' => $row['imageID'],
                    'imagePageNumber' => $row['imagePageNumber'],
                    'pageCount' => $row['pageCount'],
                    "{$table}Location" => $row["{$table}Location"],
                    'primary' . ucfirst($table) => $row['primary' . ucfirst($table)],
                ];
            }
        }

        // Cache imageFiles data.
        foreach ($this->getTable('imageFiles') as $row) {
            $this->imageFiles[$row['imageID']][] = $row['imageFilePath'];
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
     * Create resource templates needed for PWD migration.
     */
    public function createResourceTemplates()
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
                'o:resource_template' => [
                    'o:id' => $this->resourceTemplates['repositories'],
                ],
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class']['foaf:Organization'],
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
        foreach ($this->getTable('collections') as $row) {
            if (in_array($row['collectionID'], $this->excludeCollections)) {
                continue;
            }
            $data = [
                'o:item_set' => [
                    'o:id' => $this->itemSets['collections'],
                ],
                'o:resource_template' => [
                    'o:id' => $this->resourceTemplates['collections'],
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
        foreach ($this->getTable('microfilms') as $row) {
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
        foreach ($this->getTable('publications') as $row) {
            $data = [
                'o:item_set' => [
                    'o:id' => $this->itemSets['publications'],
                ],
                'o:resource_template' => [
                    'o:id' => $this->resourceTemplates['publications'],
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
        foreach ($this->getTable('names') as $row) {
            $data = [
                'o:item_set' => [
                    'o:id' => $this->itemSets['names'],
                ],
                'o:resource_template' => [
                    'o:id' => $this->resourceTemplates['names'],
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
     * @param int $limit Limit images for testing
     */
    public function migrateImages($limit = null)
    {
        $images = [];
        foreach ($this->getTable('images') as $index => $row) {
            if (is_numeric($limit) && $limit <= $index) break;
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
                    'o:id' => $this->vocabMembers['resource_class']['bibo:Image'],
                ],
            ];

            // Ingest media *once* then use backups of `media` and `pwd_images`
            // to interleave media back into the database after the last
            // migration process. Then move a backup of the files/ directory
            // back to its original location. This avoids repeating the lengthy
            // derivative creation process, which took over 24 hours to
            // complete.

            // Ingest media
            //~ $imageFiles = $this->imageFiles[$row['imageID']] ?? [];
            //~ natcasesort($imageFiles);
            //~ foreach ($imageFiles as $imageFile) {
                //~ $data['o:media'][] = [
                    //~ 'o:ingester' => 'sideload',
                    //~ 'ingest_filename' => $imageFile,
                //~ ];
            //~ }

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
     * Migrate PWD documents into Omeka.
     *
     * @param int $limit Limit documents for testing
     */
    public function migrateDocuments($limit = null)
    {
        $citeCodes = [];
        foreach ($this->getTable('citeCodes') as $row) {
            $citeCodes[$row['citeCodeID']] = $row['citeCodeName'];
        }

        $documents = [];
        $transcriptionData = [];
        foreach ($this->getTable('documents') as $index => $row) {
            if (is_numeric($limit) && $limit <= $index) break;

            if ($row['documentFormatID']) {
                $localName = $this->documentFormats[$row['documentFormatID']][0];
                $prefix = in_array($localName, ['Document', 'Letter']) ? 'bibo' : 'pwd';
                $resourceClass = "$prefix:$localName";
            } else {
                $resourceClass = 'bibo:Document';
            }
            $data = [
                'o:item_set' => [
                    'o:id' => $this->itemSets['documents'],
                ],
                'o:resource_template' => [
                    'o:id' => $this->resourceTemplates['documents'],
                ],
                'o:resource_class' => [
                    'o:id' => $this->vocabMembers['resource_class'][$resourceClass],
                ],
            ];

            $mapping = [
                [$row['documentNumber'], 'pwd:documentNumber', 'literal'],
                [$row['documentImagePageNumber'], 'bibo:pageStart', 'literal'],
                [$row['documentDate'], 'dcterms:created', 'literal'],
                [$row['documentDateYear'], 't:year', 'literal'],
                [$row['documentDateMonth'], 't:month', 'literal'],
                [$row['documentDateDay'], 't:day', 'literal'],
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

            // Do not migrate citeCodeID #1 ("Document in hand or verified in a
            // collection, not a cited document").
            if ($row['documentCiteCodeID'] && 1 != $row['documentCiteCodeID']) {
                $mapping[] = [$citeCodes[$row['documentCiteCodeID']], 'pwd:citedNote', 'literal'];
            }
            foreach (explode(';', $row['documentPersonsGroups']) as $value) {
                $mapping[] = [$value, 'pwd:notableAgent', 'literal'];
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

            // Map names. Note that we don't map names that don't exist.
            $names = $this->reificationData['documents_names'][$row['documentID']] ?? [];
            foreach ($names as $value) {
                $oNameId = $this->mappings['pwd_names'][$value['nameID']] ?? null;
                if ($oNameId) {
                    $term = $value['author']
                        ? ($value['primaryName'] ? 'dcterms:creator' : 'pwd:secondaryAuthor')
                        : ($value['primaryName'] ? 'bibo:recipient' : 'pwd:secondaryRecipient');
                    $mapping[] = [$oNameId, $term, 'resource'];
                }
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
                $resources = $this->reificationData[$resourceVar[0]][$row['documentID']] ?? [];
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
            $transcriptionData[$row['documentID']] = [
                $row['documentNominateForTranscription'],
                $row['documentTranscription'],
            ];
        }

        $api = $this->services->get('Omeka\ApiManager');

        foreach (array_chunk($documents, 100, true) as $documentsChunk) {
            $response = $api->batchCreate('items', $documentsChunk);
            $this->mapTable('pwd_documents', $response->getContent());

            // Map and save document transcription data.
            $tokenCount = 0;
            $insertValues = [];
            foreach ($response->getContent() as $key => $value) {
                if (null === $transcriptionData[$key][0] && !$transcriptionData[$key][1]) {
                    // No transcription data to save.
                    continue;
                }
                $insertValues[] = $value->id();
                $insertValues[] = $transcriptionData[$key][0];
                $insertValues[] = utf8_encode($transcriptionData[$key][1]);
                $tokenCount++;
            }
            $sql = sprintf(
                'INSERT INTO pwd_transcriptions (id_omeka, nominate, transcription) VALUES %s',
                implode(', ', array_fill(0, $tokenCount, '(?, ?, ?)'))
            );
            $conn = $this->services->get('Omeka\Connection');
            $stmt = $conn->prepare($sql);
            $stmt->execute($insertValues);
        }
    }

    /**
     * Map document/name and document/image reification data.
     */
    public function mapReificationData()
    {
        $conn = $this->services->get('Omeka\Connection');

        // Map documents/names reification table.
        $tokenCount = 0;
        $insertValues = [];
        foreach ($this->reificationData['documents_names'] as $key => $values) {
            if (!isset($this->mappings['pwd_documents'][$key])) {
                // Avoid error if a document limit was set.
                continue;
            }
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

        // Map document/image reification tables.
        foreach (['collection', 'microfilm', 'publication'] as $table) {
            $tokenCount = 0;
            $insertValues = [];
            foreach ($this->reificationData["documents_{$table}s"] as $key => $values) {
                if (!isset($this->mappings['pwd_documents'][$key])) {
                    // Avoid error if a document limit was set.
                    continue;
                }
                foreach ($values as $value) {
                    $insertValues[] = $this->mappings['pwd_documents'][$key];
                    $insertValues[] = $this->mappings["pwd_{$table}s"][$value["{$table}ID"]] ?? null;
                    $insertValues[] = $this->mappings['pwd_images'][$value['imageID']] ?? null;
                    $insertValues[] = $value['primary' . ucfirst($table)];
                    $insertValues[] = $value['imagePageNumber'];
                    $insertValues[] = $value['pageCount'];
                    $insertValues[] = $value["{$table}Location"] ? utf8_encode($value["{$table}Location"]) : null;
                    $tokenCount++;
                }
            }
            $sql = sprintf(
                "INSERT INTO pwd_document_{$table} (
                    document_id, {$table}_id, image_id, is_primary, page_number, page_count, location
                ) VALUES %s",
                implode(', ', array_fill(0, $tokenCount, '(?, ?, ?, ?, ?, ?, ?)'))
            );
            $stmt = $conn->prepare($sql);
            $stmt->execute($insertValues);
        }
    }
}
