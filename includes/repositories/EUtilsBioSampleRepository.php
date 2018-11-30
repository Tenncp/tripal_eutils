<?php

class EUtilsBioSampleRepository extends EUtilsRepositoryInterface{

  /**
   * Required attributes when using the create method.
   *
   * @var array
   */
  protected $required_fields = [
    'name',
    'description',
    'attributes',
    'accessions',
  ];

  /**
   * Cache of data per run.
   *
   * @var array
   */
  protected static $cache = [
    'db' => [],
    'accessions' => [],
    'biosamples',
  ];

  /**
   * Takes data from the EUtilsBioSampleParser and creates the chado records
   * needed including biosample, accessions and props.
   *
   * @param array $data
   *
   * @return object
   * @throws \Exception
   * @see \EUtilsBioSampleParser::parse() to get the data array needed.
   */
  public function create($data) {
    $this->validateFields($data);

    // Create the base record
    $bio_sample = $this->createBioSample([
      'name' => $data['name'],
      'description' => is_array($data['description']) ? implode("\n",
        $data['description']) : $data['description'],
    ]);

    // Create the accessions
    $this->createAccessions($bio_sample, $data['accessions']);

    // Create the props (from attributes)
    $this->createProps($bio_sample, $data['attributes']);

    return $bio_sample;
  }

  /**
   * Create a bio sample record.
   *
   * @param array $data See chado.biomaterial schema
   *
   * @return mixed
   * @throws \Exception
   */
  public function createBioSample(array $data) {
    // Name is unique so find the biomaterial first.
    $biosample = $this->getBioSample($data['name']);

    if (!empty($biosample)) {
      return $biosample;
    }

    $id = db_insert('chado.biomaterial')->fields([
      'name' => $data['name'] ?? '',
      'description' => $data['description'] ?? '',
    ])->execute();

    if (!$id) {
      throw new Exception('Unable to create chado.biomaterial record');
    }

    $biosample = db_select('chado.biomaterial', 'B')
      ->fields('B')
      ->condition('biomaterial_id', $id)
      ->execute()
      ->fetchObject();

    return static::$cache['biosamples'][$biosample->name] = $biosample;
  }

  /**
   * Get biosample from db or cache.
   *
   * @param string $name
   *
   * @return null
   */
  public function getBioSample($name) {
    if (isset(static::$cache['biosamples'][$name])) {
      return static::$cache['biosamples'][$name];
    }

    $biosample = db_select('chado.biomaterial', 'b')
      ->fields('b')
      ->condition('name', $name)
      ->execute()
      ->fetchObject();

    if (!empty($biosample)) {
      return static::$cache['biosamples'][$name] = $biosample;
    }

    return NULL;
  }

  /**
   * Creates a set of accessions attaches them with the given biosample.
   *
   * @param object $bio_sample The BioSample created by createBioSample()
   * @param array $accessions
   *
   * @return array
   */
  public function createAccessions($bio_sample, array $accessions) {
    $data = [];

    foreach ($accessions as $accession) {
      try {
        $data[] = $this->createAccession($bio_sample, $accession);
      } catch (Exception $exception) {
      }
    }

    return $data;
  }

  /**
   * Creates a new accession record if does not exist and attaches it to
   * the given biosample.
   *
   * @param object $bio_sample
   * @param object $accession
   *
   * @return mixed
   * @throws \Exception
   */
  public function createAccession($bio_sample, $accession) {
    if (!isset($accession['db'])) {
      throw new Exception('DB not provided for accession ' . $accession['value']);
    }

    $db = $this->getDB('NCBI ' . $accession['db']);

    if (empty($db)) {
      throw new Exception('Unable to find DB NCBI ' . $accession['db'] . '. Please create DB first.');
    }

    $dbxref = $this->getAccessionByName($accession['value'], $db->db_id);

    if (!empty($dbxref)) {
      $this->linkBioSampleToAccession($bio_sample->biomaterial_id,
        $dbxref->dbxref_id);

      return $dbxref;
    }

    if (!empty($db)) {
      $id = db_insert('chado.dbxref')->fields([
        'db_id' => $db->db_id,
        'accession' => $accession['value'],
      ])->execute();

      $this->linkBioSampleToAccession($bio_sample->biomaterial_id, $id);

      return static::$cache['accessions'][$accession['value']] = $this->getAccessionByID($id);
    }

    return NULL;
  }

  /**
   * Attach an accession to a biosample.
   *
   * @param int $bio_sample_id
   * @param int $accession_id
   *
   * @return \DatabaseStatementInterface|int
   * @throws \Exception
   */
  public function linkBioSampleToAccession($bio_sample_id, $accession_id) {
    return db_insert('chado.biomaterial_dbxref')->fields([
      'biomaterial_id' => $bio_sample_id,
      'dbxref_id' => $accession_id,
    ])->execute();
  }

  /**
   * Get accession by dbxref id.
   *
   * @param int $id
   *
   * @return mixed
   */
  public function getAccessionByID($id) {
    return db_select('chado.dbxref', 'd')
      ->fields('d')
      ->condition('dbxref_id', $id)
      ->execute()
      ->fetchObject();
  }

  /**
   * Look up an accession in chado.dbxref. Retrieves record from cache
   * if predetermined.
   *
   * @param string $name The accession identifier (dbxref.accession).
   * @param int $db_id Name of the DB ID.
   *
   * @return mixed
   */
  public function getAccessionByName($name, $db_id) {
    if (isset(static::$cache['accessions'][$name])) {
      return static::$cache['accessions'][$name];
    }

    $accession = db_select('chado.dbxref', 'd')
      ->fields('d')
      ->condition('accession', $name)
      ->condition('db_id', $db_id)
      ->execute()
      ->fetchObject();

    if (!empty($accession)) {
      static::$cache['accessions'][$accession->accession] = $accession;
    }

    return $accession;
  }

  /**
   * Get chado.db record by name. Retrieves data from cache if predetermined.
   *
   * @param string $name
   *
   * @return mixed
   */
  public function getDB($name) {
    if (isset(static::$cache['db'][$name])) {
      return static::$cache['db'][$name];
    }

    $db = db_select('chado.db', 'db')
      ->fields('db')
      ->condition('name', $name)
      ->execute()
      ->fetchObject();

    if ($db) {
      return static::$cache['db'][$name] = $db;
    }

    return NULL;
  }

  public function createProps($bio_sample, $props) {

  }
}
