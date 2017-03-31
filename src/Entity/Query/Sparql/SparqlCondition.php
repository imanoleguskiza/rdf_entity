<?php

namespace Drupal\rdf_entity\Entity\Query\Sparql;

use Drupal\Core\Entity\Query\ConditionFundamentals;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\rdf_entity\RdfGraphHandler;
use Drupal\rdf_entity\RdfMappingHandler;

/**
 * Defines the condition class for the null entity query.
 *
 * @todo: Build a ConditionInterface that extends the ConditionInterface below.
 */
class SparqlCondition extends ConditionFundamentals implements ConditionInterface {

  /**
   * The rdf graph handler service object.
   *
   * @var \Drupal\rdf_entity\RdfGraphHandler
   */
  protected $graphHandler;

  /**
   * The rdf mapping handler service object.
   *
   * @var \Drupal\rdf_entity\RdfMappingHandler
   */
  protected $mappingHandler;

  /**
   * Provides a map of filter operators to operator options.
   *
   * @var array
   */
  protected static $filterOperatorMap = [
    'IN' => ['delimiter' => ' ', 'prefix' => '', 'suffix' => ''],
    'NOT IN' => ['delimiter' => ', ', 'prefix' => '', 'suffix' => ''],
    'IS NULL' => ['use_value' => FALSE],
    'IS NOT NULL' => ['use_value' => FALSE],
    'LIKE' => ['prefix' => 'regex(', 'suffix' => ')'],
    'NOT LIKE' => ['prefix' => '!regex(', 'suffix' => ')'],
    'EXISTS' => ['prefix' => 'EXISTS {', 'suffix' => '}'],
    'NOT EXISTS' => ['prefix' => 'NOT EXISTS {', 'suffix' => '}'],
    '<' => ['prefix' => '', 'suffix' => ''],
    '>' => ['prefix' => '', 'suffix' => ''],
    '>=' => ['prefix' => '', 'suffix' => ''],
    '<=' => ['prefix' => '', 'suffix' => ''],
  ];

  /**
   * Whether the conditions have been changed.
   *
   * TRUE if the condition has been changed since the last compile.
   * FALSE if the condition has been compiled and not changed.
   *
   * @var bool
   */
  protected $changed = TRUE;

  /**
   * Whether the conditions do not have a triple.
   *
   * This will be turned to false if there is at least one condition that does
   * not involve the id.
   *
   * @var bool
   */
  protected $needsDefault = TRUE;

  /**
   * The default bundle predicate.
   *
   * @var array
   */
  protected $typePredicate = '<http://www.w3.org/1999/02/22-rdf-syntax-ns#type>';


  /**
   * An array of conditions in their string version.
   *
   * These are formed during the compilation phase.
   *
   * @var string[]
   */
  protected $conditionFragments;

  /**
   * An array of field names and their corresponding mapping.
   *
   * @var string[]
   */
  protected $fieldMappings;

  /**
   * An array of conditions regarding fields with multiple possible mappings.
   *
   * @var array
   */
  protected $fieldMappingConditions;

  /**
   * The entity type id key.
   *
   * @var string
   */
  protected $idKey;

  /**
   * The entity type bundle key.
   *
   * @var string
   */
  protected $bundleKey;

  /**
   * The entity type label key.
   *
   * @var string
   */
  protected $labelKey;

  /**
   * The bundle id.
   *
   * @var string
   */
  protected $entityBundle;

  /**
   * The string version of the condition fragments.
   *
   * @var string
   */
  protected $stringVersion;

  /**
   * Whether the condition has been compiled.
   *
   * @var bool
   */
  private $isCompiled;

  // @todo: Do we need this?
  // @todo: This has to go to the interface.
  const ID_KEY = '?entity';

  /**
   * {@inheritdoc}
   */
  public function __construct($conjunction, QueryInterface $query, array $namespaces, RdfGraphHandler $rdf_graph_handler, RdfMappingHandler $rdf_mapping_handler) {
    parent::__construct($conjunction, $query, $namespaces);
    $this->graphHandler = $rdf_graph_handler;
    $this->mappingHandler = $rdf_mapping_handler;
    $this->bundleKey = $query->getEntityType()->getKey('bundle');
    $this->idKey = $query->getEntityType()->getKey('id');
    $this->labelKey = $query->getEntityType()->getKey('label');
    // Initialize variable to avoid warnings;.
    $this->fieldMappingConditions = [];
  }

  /**
   * A list of properties regarding the query conjunction.
   *
   * @var array
   */
  protected static $conjunctionMap = [
    'AND' => ['delimeter' => ' . ', 'prefix' => '', 'suffix' => ''],
    'OR' => ['delimeter' => ' UNION ', 'prefix' => '{ ', 'suffix' => ' }'],
  ];

  /**
   * {@inheritdoc}
   *
   * @todo: handle the langcode.
   */
  public function condition($field = NULL, $value = NULL, $operator = NULL, $langcode = NULL) {
    // In case the field name includes the column, explode it.
    // @see \Drupal\og\MembershipManager::getGroupContentIds
    $field_name_parts = explode('.', $field);
    $field = reset($field_name_parts);
    if ($this->conjunction == 'OR') {
      $sub_condition = $this->query->andConditionGroup();
      $sub_condition->condition($field, $value, $operator, $langcode);
      $this->conditions[] = ['field' => $sub_condition];
      return $this;
    }

    if ($operator === NULL) {
      $operator = '=';
    }

    switch ($field) {
      case $this->bundleKey:
        // If a bundle filter is passed, then there is no need for a default
        // condition.
        $this->needsDefault = FALSE;

      case $this->idKey:
        $this->keyCondition($field, $value, $operator);
        break;

      default:
        $this->needsDefault = FALSE;
        $this->conditions[] = [
          'field' => $field,
          'value' => $value,
          'operator' => $operator,
          'langcode' => $langcode,
        ];
    }

    return $this;
  }

  /**
   * Handle the id and bundle keys.
   *
   * @param string $field
   *   The field name. Should be either the id or the bundle key.
   * @param string|array $value
   *   A string or an array of strings.
   * @param string $operator
   *   The operator.
   *
   * @return \Drupal\Core\Entity\Query\ConditionInterface
   *   The current object.
   *
   * @throws \Exception
   *    Thrown if the value is NULL or the operator is not allowed.
   */
  public function keyCondition($field, $value, $operator) {
    // @todo: Add support for loadMultiple with empty Id (load all).
    if ($value == NULL) {
      throw new \Exception('The value cannot be NULL for conditions related to the Id and bundle keys.');
    }
    if (!in_array($operator, ['=', '!=', 'IN', 'NOT IN'])) {
      throw new \Exception("Only '=', '!=', 'IN', 'NOT IN' operators are allowed for the Id and bundle keys.");
    }

    switch ($operator) {
      case '=':
        $value = [$value];
        $operator = 'IN';

      case 'IN':
        $this->fieldMappingConditions[] = [
          'field' => $field,
          'value' => $value,
          'operator' => $operator,
        ];

        break;

      case '!=':
        $value = [$value];
        $operator = 'NOT IN';

      case 'NOT IN':
        $this->conditions[] = [
          'field' => $field,
          'value' => $value,
          'operator' => $operator,
        ];
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * Map the field names with the corresponding resource IDs.
   * The predicate mapping can not added as a direct filter. It is being
   * loaded from the database. There is no way that in a single request, the
   * same predicate is found with a single and multiple mappings.
   * There is no filter per bundle in the query. That makes it safe to not check
   * on the predicate mappings that are already in the query.
   */
  public function compile($query) {
    $entity_type = $query->getEntityType();
    $condition_stack = array_merge($this->conditions, $this->fieldMappingConditions);
    foreach ($condition_stack as $index => $condition) {
      if ($condition['field'] instanceof ConditionInterface) {
        $condition['field']->compile($query);
        continue;
      }
      elseif ($condition['field'] === $this->idKey) {
        $mappings = [self::ID_KEY];
      }
      elseif ($condition['field'] === $this->bundleKey) {
        $mappings = [$this->typePredicate];
      }
      else {
        $mappings = $this->mappingHandler->getFieldRdfMapping($entity_type->id(), $condition['field']);
      }
      if (count($mappings) == 1) {
        $this->fieldMappings[$condition['field']] = reset($mappings);
      }
      else {
        if (!isset($this->fieldMappings[$condition['field']])) {
          $this->fieldMappings[$condition['field']] = $this->toVar($condition['field'] . '_predicate');
        }
        // The predicate mapping is not added as a direct filter. It is being
        // loaded by the database. There is no way that in a single request, the
        // same predicate is found with a single and multiple mappings.
        // There is no filter per bundle in the query.
        $this->fieldMappingConditions[] = [
          'field' => $condition['field'] . '_predicate',
          'value' => array_values($mappings),
          'operator' => 'IN',
        ];
      }

      // Finally, handle the case where the field is a reference field.
      // @todo: Should this be moved to the pre execute phase?
      // $conditions[$index]['value'] = $this->escapeValue(
      // $condition['field'], $condition['value']);.
    }
  }

  /**
   * Returns the string version of the conditions.
   *
   * @return string
   *   The string version of the conditions.
   */
  public function toString() {
    $filter_fragments = [];

    if ($this->needsDefault) {
      $this->addConditionFragment(self::ID_KEY . ' ' . $this->typePredicate . ' ' . $this->toVar($this->bundleKey, TRUE));
      $this->fieldMappings[$this->bundleKey] = $this->typePredicate;
    }

    // Add first the field mapping conditions. These conditions include the
    // bundle and the id filter and 'IN' clauses are converted into SPARQL
    // 'VALUES' clauses so it improves performance.
    foreach ($this->fieldMappingConditions as $condition) {
      if ($condition['field'] === $this->idKey) {
        $condition['field'] = $this->fieldMappings[$condition['field']];
      }
      else {
        $this->addConditionFragment(self::ID_KEY . ' ' . $this->fieldMappings[$condition['field']] . ' ' . $this->toVar($condition['field']));
      }
      $this->addConditionFragment($this->compileValuesFilter($condition));
    }

    foreach ($this->conditions() as $condition) {
      // @todo: Change this to the SparqlCondition interface when it is created.
      if ($condition['field'] instanceof ConditionInterface) {
        $this->addConditionFragment($condition['field']->toString());
      }
      else {
        // If it is not a direct triple, it is a filter so a variable is being
        // added for the value.
        if ($condition['operator'] !== '=' && $condition['field'] !== $this->idKey) {
          $this->addConditionFragment(self::ID_KEY . ' ' . $this->fieldMappings[$condition['field']] . ' ' . $this->toVar($condition['field']));
        }
        switch ($condition['operator']) {
          case '=':
            $this->addConditionFragment(self::ID_KEY . ' ' . $this->fieldMappings[$condition['field']] . ' ' . $condition['value']);
            break;

          case 'EXISTS':
          case 'NOT EXISTS':
            $this->addConditionFragment($this->compileExists($condition));
            break;

          case 'LIKE':
          case 'NOT LIKE':
            $this->addConditionFragment($this->compileLike($condition));
            break;

          case 'IN':
            $this->addConditionFragment($this->compileValuesFilter($condition));

          default:
            $filter_fragments[] = $this->compileFilter($condition);

        }
      }
    }

    // Finally, bring the filters together.
    if (!empty($filter_fragments)) {
      $this->addConditionFragment($this->compileFilters($filter_fragments));
    }

    // Put together everything.
    $this->stringVersion = implode(self::$conjunctionMap[$this->conjunction]['delimeter'], array_unique($this->conditionFragments));
    return $this->stringVersion;
  }

  /**
   * Adds a condition string to the condition fragments.
   *
   * The encapsulation of the condition according to the conjunction is taking
   * place here.
   *
   * @param string $condition_string
   *   A string version of the condition.
   */
  protected function addConditionFragment($condition_string) {
    $prefix = self::$conjunctionMap[$this->conjunction]['prefix'];
    $suffix = self::$conjunctionMap[$this->conjunction]['suffix'];
    $this->conditionFragments[] = $prefix . $condition_string . $suffix;
  }

  /**
   * Compiles a filter exists (or not exists) condition.
   *
   * @param array $condition
   *   An array that contains the 'field', 'value', 'operator' values.
   *
   * @return string
   *   A condition fragment string.
   */
  protected function compileExists(array $condition) {
    $prefix = self::$filterOperatorMap[$condition['operator']]['prefix'];
    $suffix = self::$filterOperatorMap[$condition['operator']]['suffix'];
    return $prefix . $this->toVar($condition['field']) . $suffix;
  }

  /**
   * Compiles a filter 'LIKE' condition using a regex.
   *
   * @param array $condition
   *   An array that contains the 'field', 'value', 'operator' values.
   *
   * @return string
   *   A condition fragment string.
   */
  protected function compileLike(array $condition) {
    $prefix = self::$filterOperatorMap[$condition['operator']]['prefix'];
    $suffix = self::$filterOperatorMap[$condition['operator']]['suffix'];
    $value = $this->toVar($condition['field']) . ', ' . addslashes($condition['value']);
    return $prefix . $value . $suffix;
  }

  /**
   * Compiles a filter condition.
   *
   * @param array $condition
   *   An array that contains the 'field', 'value', 'operator' values.
   *
   * @return string
   *   A condition fragment string.
   *
   * @throws \Exception
   *    Thrown when a value is an array but a string is expected.
   */
  protected function compileFilter(array $condition) {
    $prefix = self::$filterOperatorMap[$condition['operator']]['prefix'];
    $suffix = self::$filterOperatorMap[$condition['operator']]['suffix'];
    if (is_array($condition['value'])) {
      if (!isset(self::$filterOperatorMap[$condition['operator']]['delimiter'])) {
        throw new \Exception("An array value is not supported for this operator.");
      }
      $condition['value'] = '(' . implode(self::$filterOperatorMap[$condition['operator']]['delimiter'], $condition['value']) . ')';
    }
    $condition['field'] = $this->toVar($condition['field']);
    return $prefix . $condition['field'] . ' ' . $condition['operator'] . ' ' . $condition['value'] . $suffix;
  }

  /**
   * Compiles an 'IN' condition as a SPARQL 'VALUES'.
   *
   * 'VALUES' is preferred over 'FILTER IN' for performance.
   * This should only be called for subject and predicate filter as it considers
   * values to be resources.
   *
   * @param array $condition
   *   The condition array.
   *
   * @return string
   *   The string version of the condition.
   */
  protected function compileValuesFilter(array $condition) {
    if (is_string($condition['value'])) {
      $value = [$condition['value']];
    }
    else {
      $value = $condition['value'];
    }
    // $value = SparqlArg::toResourceUris($value);
    return 'VALUES ' . $this->toVar($condition['field']) . ' {' . implode(' ', $value) . '}';
  }

  /**
   * Compiles a filter condition.
   *
   * @param array $filter_fragments
   *   An array of filter strings.
   *
   * @return string
   *   A condition fragment string.
   */
  protected function compileFilters(array $filter_fragments) {
    // The delimiter is always a '&&' because otherwise it would be a separate
    // condition class.
    $delimiter = '&&';
    if (count($filter_fragments) > 1) {
      $compiled_filter = '(' . implode(') ' . $delimiter . '(', $filter_fragments) . ')';
    }
    else {
      $compiled_filter = reset($filter_fragments);
    }

    return 'FILTER (' . $compiled_filter . ')';
  }

  /**
   * Implements \Drupal\Core\Entity\Query\ConditionInterface::exists().
   */
  public function exists($field, $langcode = NULL) {
    $this->condition($field, NULL, 'EXISTS');
  }

  /**
   * Implements \Drupal\Core\Entity\Query\ConditionInterface::notExists().
   */
  public function notExists($field, $langcode = NULL) {
    $this->condition($field, NULL, 'NOT EXISTS');
  }

  /**
   * Prefixes a keyword with a prefix in order to be treated as a variable.
   *
   * @param string $key
   *   The name of the variable.
   * @param bool $blank
   *   Whether or not to be a blank note.
   *
   * @return string
   *   The variable.
   */
  protected function toVar($key, $blank = FALSE) {
    if (strpos($key, '?') === FALSE && strpos($key, '_:') === FALSE) {
      return ($blank ? '_:' : '?') . $key;
    }

    // Do not alter the string if it is already prefixed as a variable.
    return $key;
  }

  /**
   * Check if the field is a resource reference field.
   *
   * This method is merely a helper method to shorten the method call.
   *
   * @param string $field_name
   *   The field machine name.
   * @param string|array $value
   *   A value or an array of values.
   *
   * @return string|array
   *   The altered $value.
   *
   * @todo: This should include better handling and more value format supporting.
   */
  protected function escapeValue($field_name, $value) {
    if (!$this->mappingHandler->fieldIsRdfReference($this->query->getEntityTypeId(), $field_name)) {
      return SparqlArg::literal($value);
    }

    if (!is_array($value)) {
      return SparqlArg::uri($value);
    }

    return SparqlArg::toResourceUris($value);
  }

  /**
   * {@inheritdoc}
   */
  public function isCompiled() {
    return (bool) $this->isCompiled;
  }

  /**
   * {@inheritdoc}
   */
  public function __clone() {}

}
