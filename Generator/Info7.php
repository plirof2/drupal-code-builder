<?php

/**
 * @file
 * Contains DrupalCodeBuilder\Generator\Info7.
 */

namespace DrupalCodeBuilder\Generator;

/**
 * Generator class for module info file for Drupal 7.
 */
class Info7 extends InfoIni {

  /**
   * Create lines of file body for Drupal 7.
   */
  function file_body() {
    $module_data = $this->root_component->component_data;
    //print_r($module_data);

    $lines = array();
    $lines['name'] = $module_data['readable_name'];
    $lines['description'] = $module_data['short_description'];
    if (!empty($module_data['module_dependencies'])) {
      // For lines which form a set with the same key and array markers,
      // simply make an array.
      foreach ($module_data['module_dependencies'] as $dependency) {
        $lines['dependencies'][] = $dependency;
      }
    }

    if (!empty($module_data['module_package'])) {
      $lines['package'] = $module_data['module_package'];
    }

    $lines['core'] = "7.x";

    if (!empty($this->extraLines)) {
      // Add a blank line before the extra lines.
      $lines[] = '';
      $lines = array_merge($lines, $this->extraLines);
    }

    $info = $this->process_info_lines($lines);
    return $info;
  }

}
