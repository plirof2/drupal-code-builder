<?php

/**
 * @file
 * Contains generator classes for module code files.
 */

namespace ModuleBuider\Generator;

/**
 * Generator class for module code files.
 *
 * TODO: various cleanups. This was the base class of the mk 1 OO generator
 * system, and is currently (hello!) being forced into a new, bigger mk
 * hierarchy!
 */
class ModuleCodeFile extends File {

  // TODO: declare properties that are special!

  /**
   * An array of functions for this file.
   *
   * @see assembleContainedComponentsHelper()
   * @see code_body()
   */
  protected $functions = array();

  /**
   * Return this component's parent in the component tree.
   */
  function containingComponent() {
    // A code file's parent is always the base component.
    // TODO: should we want files to be grouped into folders, or have submodules,
    // we'd need components to have more data other than their name
    // that survives a component getting requested multiple times!
    return $this->getBaseComponent()->name;
  }

  /**
   * Build the code files.
   */
  function collectFiles(&$files) {
    // Our component name is our future filename, with the token '%module' to
    // be replaced.
    $this->filename = str_replace('%module', $this->base_component->component_data['module_root_name'], $this->name);

    $files[$this->name] = array(
      'path' => '', // Means base folder.
      'filename' => $this->filename,
      'body' => $this->file_contents(),
      // We join code files up on a single newline. This means that each
      // component is responsible for ending its own lines.
      'join_string' => "\n",
    );
  }

  /**
   * Helper for assembleContainedComponents().
   *
   * Module code files assemble their contained components, which are functions.
   *
   * This collects data from our contained components. The functions are
   * assembled in full in code_body().
   */
  function assembleContainedComponentsHelper($children) {
    $component_list = $this->getComponentList();

    foreach ($children as $child_name) {
      // Get the child component.
      $child_component = $component_list[$child_name];

      $child_functions = $child_component->componentFunctions();
      // Why didn't array_merge() work here? Cookie for the answer!
      $this->functions += $child_functions;
    }
  }

  /**
   * Make the doxygen header for a given hook.
   *
   * This does not return with an initial newline so the doc block may be
   * inserted into existing code.
   *
   * @param
   *   The long hook name, eg 'hook_menu'.
   */
  function hook_doxygen($hook_name) {
    return <<<EOT
/**
 * Implements $hook_name().
 */

EOT;
  }

  /**
   * Return the main body of the file code.
   */
  function code_body() {
    $code = array();

    // Get replacements.
    $variables = $this->getReplacements();

    foreach ($this->functions as $function_name => $function_data) {
      $function_code = '';

      $function_code .= $this->function_doxygen($function_data['doxygen_first']);

      $function_code .= $function_data['declaration'];
      $function_code .= ' {';

      // See if function bodies exist; if so, use function bodies from template
      if (isset($function_data['code'])) {
        $function_code .= $function_data['code'];
      }
      else {
        $function_code .= "\n\n";
      }
      $function_code .= "}\n";

      // Replace variables in all of the function code.
      $function_code = strtr($function_code, $variables);

      $code[$function_name] = $function_code;
    }

    return $code;

    // =================================== OLD CODE HERE
    // TODO: strip out parts of this we need, then remove.

    // Get old style variable names.
    $module_data = $this->base_component->component_data;
    // Get the hook data for our file.
    $hook_data = $this->base_component->component_data['hook_file_data'][$this->name];

    // Build up an array of functions' code.
    $functions = array();
    foreach ($hook_data as $hook_name => $hook) {

      // Display PHP doc, using the original case of the hook name.
      $hook_code = '';
      $hook_code .= $this->hook_doxygen($hook['name']);

      // function declaration: put in the module name, add closing brace, decode html entities
      $declaration = preg_replace('/(?<=function )hook/', $module_data['module_root_name'], $hook['definition']);
      $declaration .= ' {';
      // WTF is this for??????
      $hook_code .= htmlspecialchars_decode($declaration);

      // See if function bodies exist; if so, use function bodies from template
      if (isset($hook['template'])) {
        // Strip out INFO: comments for advanced users
        if (!variable_get('module_builder_detail', 0)) {
          // Used to strip INFO messages out of generated file for advanced users.
          $pattern = '#\s+/\* INFO:(.*?)\*/#ms';
          $hook['template'] = preg_replace($pattern, '', $hook['template']);
        }
        //dsm($hook);

        $hook_code .= $hook['template'];
      }
      else {
        $hook_code .= "\n\n";
      }
      $hook_code .= "}\n";

      // Replace variables
      $variables = $this->getReplacements();
      $hook_code = strtr($hook_code, $variables);

      $functions[$hook_name] = $hook_code;
    } // foreach hook

    // DEAD CODE
    // return $functions;
  }

  /**
   * Return a file footer.
   */
  function code_footer() {
    $footer = variable_get('module_builder_footer', '');
    return $footer;
  }

  /**
   * Create a doxygen block for a function.
   *
   * @param $text
   *  The first line of text for the doxygen block.
   */
  function function_doxygen($text) {
    return <<<EOT
/**
 * $text
 */

EOT;
  }

  /**
   * Helper to get replacement strings for tokens in code body.
   *
   * @return
   *  An array of tokens to replacements, suitable for use by strtr().
   */
  function getReplacements() {
    // Get old style variable names.
    $module_data = $this->base_component->component_data;

    return array(
      '%module'       => $module_data['module_root_name'],
      '%description'  => str_replace("'", "\'", $module_data['module_short_description']),
      '%name'         => !empty($module_data['module_readable_name']) ? str_replace("'", "\'", $module_data['module_readable_name']) : $module_data['module_root_name'],
      '%help'         => !empty($module_data['module_help_text']) ? str_replace('"', '\"', $module_data['module_help_text']) : t('TODO: Create admin help text.'),
      '%readable'     => str_replace("'", "\'", $module_data['module_readable_name']),
    );
  }

}