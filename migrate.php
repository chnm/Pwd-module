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

    public function migrate()
    {
        $this->createMappingTables();
        $this->importVocabs();
        $this->cacheVocabMembers();

        // Migrate PWD repositories.
        foreach ($this->getTable('repositories') as $row) {
            echo $row['repositoryMARCOrganizationCode'] . "\n";
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

    public function getTable($table)
    {
        return $this->conn->query(sprintf('SELECT * FROM %s', $table));
    }
}

require 'config.php';
$pwd = new Pwd(PWD_DB_HOST, PWD_DB_NAME, PWD_DB_USERNAME, PWD_DB_PASSWORD, PWD_OMEKA_PATH);
