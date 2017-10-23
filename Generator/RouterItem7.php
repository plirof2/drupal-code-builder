<?php

/**
 * @file
 * Contains DrupalCodeBuilder\Generator\RouterItem.
 */

namespace DrupalCodeBuilder\Generator;

/**
 * Generator for a router item.
 *
 * This class covers Drupal 6 and 7, where it is purely an intermediary which
 * adds a HookMenu component.
 *
 * @see RouterItem
 */
class RouterItem7 extends RouterItem {

  /**
   * The unique name of this generator.
   *
   * A generator's name is used as the key in the $components array.
   *
   * A RouterItem generator should use as its name its path.
   *
   * TODO: at what point do names start to clash and we need prefixes based on
   * type???!!
   */
  public $name;

  /**
   * Constructor method; sets the component data.
   *
   * @param $component_name
   *   The identifier for the component.
   * @param $component_data
   *   (optional) An array of data for the component. Any missing properties
   *   (or all if this is entirely omitted) are given default values.
   *   Valid properties are:
   *      - 'title': The title for the item.
   *      - TODO: further properties such as access!
   */
  function __construct($component_name, $component_data, $root_generator) {
    // Set some default properties.
    // This allows the user to leave off specifying details like title and
    // access, and get default strings in place that they can replace in
    // generated module code.
    $component_data += array(
      'page callback' => 'example_page',
      // These have to be a code string, not an actual array!
      'page arguments' => "array()",
      'access arguments' => "array('access content')",
    );

    parent::__construct($component_name, $component_data, $root_generator);
  }

  /**
   * {@inheritdoc}
   */
  public static function requestedComponentHandling() {
    return 'repeat';
  }

  /**
   * Declares the subcomponents for this component.
   *
   * @return
   *  An array of subcomponent names and types.
   */
  public function requiredComponents() {
    $return = array(
      'hooks' => array(
        'component_type' => 'Hooks',
        'hooks' => array(
          'hook_menu' => TRUE,
        ),
      ),
    );

    return $return;
  }

  /**
   * {@inheritdoc}
   */
  function containingComponent() {
    return 'HookMenu:hook_menu';
  }

  /**
   * {@inheritdoc}
   */
  public function buildComponentContents($children_contents) {
    // Return code for a single menu item. Our parent in the component tree,
    // HookMenu, will merge it in its own buildComponentContents().
    $code = array();
    $code[] = "£items['{$this->name}'] = array(";
    $code[] = "  'title' => '{$this->component_data['title']}',";
    if (isset($this->component_data['description'])) {
      $code[] = "  'description' => '{$this->component_data['description']}',";
    }
    $code[] = "  'page callback' => '{$this->component_data['page callback']}',";
    // This is an array, so not quoted.
    $code[] = "  'page arguments' => {$this->component_data['page arguments']},";
    // This is an array, so not quoted.
    $code[] = "  'access arguments' => {$this->component_data['access arguments']},";
    if (isset($this->component_data['file'])) {
      $code[] = "  'file' => '{$this->component_data['file']}',";
    }
    if (isset($this->component_data['type'])) {
      // The type is a constant, so is not quoted.
      $code[] = "  'type' => {$this->component_data['type']},";
    }
    $code[] = ");";

    return [
      'route' => [
        'role' => 'item',
        'content' => $code,
      ],
    ];
  }

}
