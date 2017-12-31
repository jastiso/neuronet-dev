<?php

namespace Drupal\taxonomy_manager\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\FormElement;
use Drupal\Component\Utility\Html;

/**
 * Taxonomy Manager Tree Form Element
 *
 * @FormElement("taxonomy_manager_tree")
 */
class TaxonomyManagerTree extends FormElement {

  public function getInfo() {
    $class = get_class($this);

    return array(
      '#input' => TRUE,
      '#process' => array(
        array($class, 'processTree')
      ),
    );
  }

  public static function processTree(&$element, FormStateInterface $form_state, &$complete_form) {
    $element['#tree'] = TRUE;

    if (!empty($element['#vocabulary'])) {
      $taxonomy_vocabulary = \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->load($element['#vocabulary']);
      $pager_size = isset($element['#pager_size']) ? $element['#pager_size'] : -1;
      $terms = TaxonomyManagerTree::loadTerms($taxonomy_vocabulary, 0, $pager_size);
      $list = TaxonomyManagerTree::getNestedListJSONArray($terms);

      // Expand tree to given terms.
      if (isset($element['#terms_to_expand'])) {
        $terms_to_expand = is_array($element['#terms_to_expand']) ? $element['#terms_to_expand'] : array($element['#terms_to_expand']);
        foreach ($terms_to_expand as $term_to_expand) {
          TaxonomyManagerTree:self::getFirstPath($term_to_expand, $list);
        }
      }

      $element['#attached']['library'][] = 'taxonomy_manager/tree';
      $element['#attached']['drupalSettings']['taxonomy_manager']['tree'][] = array(
        'id' => $element['#id'],
        'name' => $element['#name'],
        'source' => $list,
      );

      $element['tree'] = array();
      $element['tree']['#prefix'] = '<div id="' . $element['#id'] . '">';
      $element['tree']['#suffix'] = '</div>';
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    // Validate that all submitted terms belong to the original vocabulary and
    // are not faked via manual $_POST changes.
    $selected_terms = array();
    if (is_array($input) && !empty($input)) {
      foreach ($input as $tid) {
        $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($tid);
        if ($term && $term->getVocabularyId() == $element['#vocabulary']) {
          $selected_terms[] = $tid;
        }
      }
    }
    return $selected_terms;
  }

  /**
   * Load one single level of terms, sorted by weight and alphabet.
   */
  public static function loadTerms($vocabulary, $parent = 0, $pager_size = -1) {
    $database = \Drupal::database();
    if ($pager_size > 0) {
      $query = $database->select('taxonomy_term_data', 'td')->extend('Drupal\Core\Database\Query\PagerSelectExtender');
    }
    else {
      $query = $database->select('taxonomy_term_data', 'td');
    }
    $query->fields('td', array('tid'));
    $query->condition('td.vid', $vocabulary->id());
    $query->join('taxonomy_term_hierarchy', 'th', 'td.tid = th.tid AND th.parent = :parent', array(':parent' => $parent));
    $query->join('taxonomy_term_field_data', 'tfd', 'td.tid = tfd.tid');
    $query->orderBy('tfd.weight');
    $query->orderBy('tfd.name');

    if ($pager_size > 0) {
      $query->limit($pager_size);
    }

    $result = $query->execute();

    $tids = array();
    foreach ($result as $record) {
      $tids[] = $record->tid;
    }

    return \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadMultiple($tids);
  }

  /**
   * Helper function that transforms a flat taxonomy tree in a nested array.
   */
  public static function getNestedList($tree = array(), $max_depth = NULL, $parent = 0, $parents_index = array(), $depth = 0) {
    foreach ($tree as $term) {
      foreach ($term->parents as $term_parent) {
        if ($term_parent == $parent) {
          $return[$term->id()] = $term;
        }
        else {
          $parents_index[$term_parent][$term->id()] = $term;
        }
      }
    }

    foreach ($return as &$term) {
      if (isset($parents_index[$term->id()]) && (is_null($max_depth) || $depth < $max_depth)) {
        $term->children = TaxonomyManagerTree::getNestedList($parents_index[$term->id()], $max_depth, $term->id(), $parents_index, $depth + 1);
      }
    }

    return $return;
  }

  /**
   * Helper function that generates the nested list for the JSON array structure.
   */
  public static function getNestedListJSONArray($terms) {
    $items = array();
    if (!empty($terms)) {
      foreach ($terms as $term) {
        $item = array(
          'title' => Html::escape($term->getName()),
          'key' => $term->id(),
        );

        if (isset($term->children) || TaxonomyManagerTree::getChildCount($term->id()) >= 1) {
          // If the given terms array is nested, directly process the terms.
          if (isset($term->children)) {
            $item['children'] = TaxonomyManagerTree::getNestedListJSONArray($term->children);
          }
          // It the term has children, but they are not present in the array,
          // mark the item for lazy loading.
          else {
            $item['lazy'] = TRUE;
          }
        }
        $items[] = $item;
      }
    }
    return $items;
  }

  /**
   * Helper function that calculates the path to a child term and injects it
   * into the json list structure.
   */
  public static function getFirstPath($tid, &$list) {
    $path = array();
    $next_tid = $tid;

    $i = 0;
    while ($i < 100) { //prevent infinite loop if inconsistent hierarchy
      $parents = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadParents($next_tid);
      if (count($parents)) {
        // Takes first parent.
        $parent = array_shift($parents);
        $path[] = $parent;
        $next_tid = $parent->id();
        if (TaxonomyManagerTree::isRoot($parent->id())) {
          break;
        }
      }
      else {
        break;
      }
      $i++;
    }

    if (count($path)) {
      $path = array_reverse($path);
      $root_term = $path[0];
      foreach ($list as $current_index => $list_item) {
        if ($list_item['key'] == $root_term->id()) {
          $index = $current_index;
          break;
        }
      }
      if (isset($index)) {
        $path[] = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($tid);
        $list[$index]['children'] = TaxonomyManagerTree::getPartialTree($path);
        $list[$index]['lazy'] = FALSE;
        $list[$index]['expanded'] = TRUE;
      }
    }
  }

  /**
   * Returns partial tree for a given path
   */
  function getPartialTree($path, $depth = 0) {
    $tree = array();
    $parent = $path[$depth];
    $children = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadChildren($parent->id());
    if (isset($path[++$depth])) {
      $next_term = $path[$depth];
    }
    $index = 0;
    foreach ($children as $child) {
      $child->depth = $depth;
      $child->parents = array(0 => $parent->tid);
      $tree[] = array(
        'title' => $child->getName(),
        'key' => $child->id(),
        'expanded' => TRUE,
        'selected' => TRUE,
      );
      if (isset($next_term) && $child->id() == $next_term->id()) {
        $tree[$index]['children'] = TaxonomyManagerTree::getPartialTree($path, $depth);
      }
      $index++;
    }
    return $tree;
  }

  /**
   * Helper function to check whether a given term is a root term.
   */
  public static function isRoot($tid) {

    $database = \Drupal::database();
    $query = $database->select('taxonomy_term_hierarchy', 'h');
    $query->fields('h', 'tid');
    $query->condition('h.parent', 0);
    $query->condition('h.tid', $tid);
    $result = $query->countQuery()->execute()->fetchField();

    if ($result == $tid) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Helper function that returns the number of child terms.
   */
  public static function getChildCount($tid) {
    static $tids = array();

    if (!isset($tids[$tid])) {
      $database = \Drupal::database();
      $query = $database->select('taxonomy_term_hierarchy', 'h');
      $query->condition('h.parent', $tid);
      $tids[$tid] = $query->countQuery()->execute()->fetchField();
    }

    return $tids[$tid];
  }
}
