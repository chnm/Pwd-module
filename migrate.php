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
     * PWD/Omeka mapping tables.
     *
     * @var array
     */
    protected $mappingTables = ['pwd_repositories', 'pwd_collections'];

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
                'o:comment' =>  'Ontology for describing People and Organizations',
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

        // Migrate PWD repositories.
        foreach ($this->getTable('repositories') as $row) {
            echo $row['repositoryMARCOrganizationCode'] . "\n";
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

    public function importVocabs()
    {
        $importer = $this->services->get('Omeka\RdfImporter');
        foreach ($this->vocabs as $vocab) {
            $importer->import($vocab['strategy'], $vocab['vocab'], $vocab['options']);
        }
    }

    public function getTable($table)
    {
        return $this->conn->query(sprintf('SELECT * FROM %s', $table));
    }
}

require 'config.php';
$pwd = new Pwd(PWD_DB_HOST, PWD_DB_NAME, PWD_DB_USERNAME, PWD_DB_PASSWORD, PWD_OMEKA_PATH);
$pwd->migrate();
