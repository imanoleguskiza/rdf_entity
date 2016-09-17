<?php

namespace Drupal\rdf_entity;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\rdf_entity\Entity\RdfEntitySparqlStorage;

/**
 * Contains helper methods that help with the uri mappings of Drupal elements.
 *
 * @package Drupal\rdf_entity
 */
class RdfMappingHelper {
  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $moduleHandler;

  /**
   * Constructs a QueryFactory object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   *    The entity type manager.
   */
  public function __construct(EntityTypeManager $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $this->getModuleHanlderService();
  }

  /**
   * Returns all bundle key mappings of the passed rdf entity type.
   *
   * @param string $entity_type
   *    The machine name of the entity type.
   *
   * @return array
   *    A list of bundle key mappings from all bundles of the passed entity
   *    type. The returned array is indexed by entity type id and by bundle
   *    entity id.
   *
   * @throws \Exception
   *    Thrown when the rdf entity bundle has no mapped type uri.
   */
  public function getRdfBundleMapping($entity_type) {
    $bundle_rdf_bundle_mapping = [];
    $storage = $this->getRdfStorage($entity_type);
    foreach ($storage->loadMultiple() as $bundle_entity) {
      $settings = $bundle_entity->getThirdPartySetting('rdf_entity', 'mapping_' . $this->bundleKey, FALSE);
      if (!is_array($settings)) {
        throw new \Exception('No rdf:type mapping set for bundle ' . $bundle_entity->label());
      }
      $type = array_pop($settings);
      $bundle_rdf_bundle_mapping[$bundle_entity->getEntityTypeId()][$bundle_entity->id()] = $type;
    }

    // Allow modules to interact and tamper with the passed list.
    $this->moduleHandler->alter('bundle_mapping', $bundle_rdf_bundle_mapping);
    return $bundle_rdf_bundle_mapping;
  }

  /**
   * Returns the storage of the passed entity type.
   *
   * @param string $entity_type
   *    The entity type machine name.
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *    The storage of the rdf entity type passed.
   *
   * @throws \Exception
   *    As this class is meant to be used for rdf entities, loading a different
   *    storage, will cause issues, so an exception is thrown in that case.
   */
  protected function getRdfStorage($entity_type) {
    $storage = $this->entityTypeManager->getStorage($entity_type);
    if (!($storage instanceof RdfEntitySparqlStorage)) {
      throw new \Exception('Storage must be an instance of RdfEntitySparqlStorage.');
    }

    return $storage;
  }

  /**
   * Returns the module hanler service object.
   *
   * @todo: Check how we can inject this.
   */
  protected function getModuleHanlderService() {
    return \Drupal::moduleHandler();
  }
}