<?php

/**
 * @file
 * Contains Module Builder Environment handlers.
 *
 * An environment handler provides an abstraction layer between Module Builder
 * and its current environment, e.g., whether we are running as a Drush plugin,
 * a Drupal module, or being loaded as a library. The environment handler takes
 * care of things such as:
 *  - how to output debug data
 *  - how to get the Drupal core version
 *  - how to load an include file with a version suffix
 *  - how to find the hooks data directory.
 * To use an environment, the class name should be passed as a parameter to
 * module_builder_get_factory().
 */

/**
 * Base class for environments.
 */
abstract class ModuleBuilderEnvironmentBase {

  /**
   * Whether to skip the sanity tests.
   *
   * This may be set on the environment after its class name is passed to
   * module_builder_get_factory. Example:
   * @code
   * $mb_factory = module_builder_get_factory('ModuleBuilderEnvironmentDrush');
   * $mb_factory->environment->skipSanity = TRUE;
   * @endcode
   */
  public $skipSanity = FALSE;

  /**
   * The path to the hooks directory.
   *
   * Depending on our environment this is either relative to Drupal root or
   * absolute, but in either case it is in a format that other environment
   * methods can use.
   *
   * Set by the constructor, and thus may be accessed within a Task handler,
   * though initially this only represents a user setting, and is not verified
   * as an existing, writable directory unless the Task's sanity level has
   * requested it.
   */
  public $hooks_directory;

  /**
   * The major Drupal core version.
   *
   * Set by the constructor, and thus may be accessed within a Task handler.
   */
  public $major_version;

  /**
   * Sanity check our basic environment to a specified level.
   *
   * This is called by the factory once this object has been set on it.
   *
   * If the property $skipSanity is set on this environment object, the tests
   * are skipped. This should only be used in rare circumstances (such as drush
   * autocomplete).
   *
   * @param $sanity_level
   *  The level up to which to verify sanity. The successive levels are:
   *    - 'hook_directory': The hooks directory exists (or can be created) and
   *      is writable.
   *    - 'hook_data': The hook data files are present in the hooks directory.
   *
   * @throws ModuleBuilderException
   *  Throws an exception if the environment is not ready at the specified
   *  level. It's up to the caller to provide meaningful feedback to the user.
   */
  public function verifyEnvironment($sanity_level) {
    // Allow the environment to request skipping the sanity checks.
    if ($this->skipSanity) {
      return;
    }

    // Sanity level 'hook_directory':
    $this->loadInclude('common_version');
    module_builder_prepare_directory($this->hooks_directory);

    // This is as far as we need to go for the hooks_directory level.
    if ($sanity_level == 'hook_directory') {
      return;
    }

    // Sanity level 'hook_data':
    $hooks_processed = $this->hooks_directory . "/hooks_processed.php";
    if (!file_exists($hooks_processed)) {
      $e = new ModuleBuilderException("No hook definitions found. You need to download hook definitions before using this module.");
      $e->needs_hooks_download = TRUE;
      throw $e;
    }

    // This is as far as we need to go for the hook_data level.
    if ($sanity_level == 'hook_data') {
      return;
    }

    // There are no further sanity levels!
  }

  /**
   * Output debug data.
   */
  abstract function debug($data, $message = '');

  /**
   * Get a path to a resource that is safe to use either on Drupal or Drush.
   *
   * (This is the OO version of module_builder_get_path().)
   *
   * @param $subpath
   *  The subpath inside the module_builder folder. Eg, 'templates'.
   */
  abstract function getPath($subpath);

  /**
   * Load an optionally versioned module builder include file.
   *
   * Include a version-specific file whether we're on drush or drupal.
   * That is, we first try to include a file called NAME_X.inc where X is a
   * Drupal major version number before falling back to NAME.inc.
   *
   * Files are included from the 'includes' folder inside module_builder.
   *
   * On Drush, this is a wrapper for drush_include().
   * On Drupal, this just goes straight for the current version.
   *
   * (This is the OO version of module_builder_include().)
   *
   * @param $name
   *  The filename, eg 'update'.
   * @param $extension
   *  The file extension.
   */
  abstract function loadInclude($name, $extension = 'inc');

  /**
   * Helper for __construct().
   */
  protected function setMajorVersion() {
    list($major_version) = explode('.', VERSION);
    $this->major_version = $major_version;
  }

}

/**
 * Environment class for Drupal UI.
 *
 * TODO: retire this; it's just for transition?
 */
class ModuleBuilderEnvironmentDrupalUI extends ModuleBuilderEnvironmentBase {

  /**
   * Constructor.
   */
  function __construct() {
    // Legacy: set the environement constant.
    // TODO: remove this.
    define('MODULE_BUILDER_ENV', 'drupal');

    // Set the module folder based on variable.
    $directory = variable_get('module_builder_hooks_directory', 'hooks');

    // Run it through version-specific stuff.
    // This basically prepends 'public://' or 'sites/default/files/'.
    $this->loadInclude('common_version');
    module_builder_directory_path($directory);

    $this->hooks_directory = $directory;

    // Set the major version.
    $this->setMajorVersion();
  }

  /**
   * Get a path to a module builder file or folder.
   */
  function getPath($subpath) {
    $path = drupal_get_path('module', 'module_builder');
    $path = $path . '/' . $subpath;
    return $path;
  }

  /**
   * Load an optionally versioned module builder include file.
   */
  function loadInclude($name, $extension = 'inc') {
    // Cheat for now and use the procedural code.
    module_builder_include($name, $extension);
  }

  /**
   * Output debug data.
   */
  function debug($data, $message = '') {
    if (module_exists('devel')) {
      dpm($data, $message);
    }
  }

}

/**
 * Environment class for use as a Drupal Library.
 *
 * This allows use of Module Builder in the following way:
 *  - place the Module Builder folder in sites/all/libraries
 *  - create a normal Drupal module, with a dependency on Libraries API.
 *
 * Sample code to include MB as a library:
 * @code
 *  function YOURMODULE_libraries_info() {
 *    $libraries['module_builder'] = array(
 *      // Only used in administrative UI of Libraries API.
 *      'name' => 'Module Builder Core',
 *      'vendor url' => 'http://example.com',
 *      'download url' => 'http://example.com/download',
 *      // We have to declare a version.
 *      'version' => 'none',
 *      'files' => array(
 *        'php' => array(
 *          'includes/common.inc',
 *          'ModuleBuilderFactory.php',
 *        ),
 *      ),
 *      // Auto-load the files.
 *      'integration files' => array(
 *        'module_builder_ui' => array(
 *          'php' => array(
 *            'includes/common.inc',
 *            'ModuleBuilderFactory.php',
 *          ),
 *        ),
 *      ),
 *    );
 *    return $libraries;
 *  }
 * @endcode
 */
class ModuleBuilderEnvironmentDrupalLibrary extends ModuleBuilderEnvironmentDrupalUI {

  /**
   * Constructor.
   */
  function __construct() {
    // Legacy: set the environement constant.
    // TODO: remove this.
    define('MODULE_BUILDER_ENV', 'library');

    // Set the module folder based on variable.
    $directory = variable_get('module_builder_hooks_directory', 'hooks');

    // Run it through version-specific stuff.
    // This basically prepends 'public://' or 'sites/default/files/'.
    $this->loadInclude('common_version');
    module_builder_directory_path($directory);

    $this->hooks_directory = $directory;

    // Set the major version.
    $this->setMajorVersion();
  }

  /**
   * Get a path to a module builder file or folder.
   */
  function getPath($subpath) {
    $path = libraries_get_path('module_builder');
    $path = $path . '/' . $subpath;
    return $path;
  }

  /**
   * Load an optionally versioned module builder include file.
   */
  function loadInclude($name, $extension = 'inc') {
    $path = $this->getPath('includes');

    // In Drupal GUI.
    // Try the versioned file first.
    $file = sprintf("%s/%s_%s.%s", $path, $name, _module_builder_drupal_major_version(), $extension);
    if (file_exists($file)) {
      require_once($file);
      return;
    }
    // Fall back to the regular file.
    $file = sprintf("%s/%s.%s", $path, $name, $extension);
    require_once($file);
  }

}

/**
 * Environment class for use as a Drush plugin.
 */
class ModuleBuilderEnvironmentDrush extends ModuleBuilderEnvironmentBase {

  /**
   * Constructor.
   */
  function __construct() {
    // Legacy: set the environement constant.
    // TODO: remove this.
    define('MODULE_BUILDER_ENV', 'drush');

    // Get the hooks directory.
    $directory = $this->getHooksDirectory();

    // Run it through version-specific stuff.
    // This basically prepends 'public://' or 'sites/default/files/'.
    module_builder_include('common_version');
    module_builder_directory_path($directory);

    $this->hooks_directory = $directory;

    // Set the major version.
    $this->setMajorVersion();
  }

  /**
   * Get the hooks directory.
   *
   * TODO: document this!
   * TODO: generalize this, to slim the constructor?
   */
  private function getHooksDirectory() {
    // Set the module folder based on variable.
    // First try the drush 'data' option.
    if (drush_get_option('data')) {
      $directory = drush_get_option('data');
      if ($directory) {
        // In pure Drush, the hooks folder contains subfolder for hooks for
        // each major version of Drupal.
        if (substr($directory, -1, 1) != '/') {
          $directory .= '/';
        }
        $directory .= _module_builder_drupal_major_version();
        return $directory;
      }
    }
    // Second, check if we're in mixed drush.
    if (function_exists('variable_get')) {
      // We're in a loaded Drupal, but MB might not be installed here.
      $directory = variable_get('module_builder_hooks_directory', 'hooks');
      return $directory;
    }
    // If we get here then argh. Set to the default and hope...
    $directory = 'hooks';
    return $directory;
  }

  /**
   * Output debug data.
   */
  function debug($data, $message = '') {
    drush_print_r($data);
  }

  /**
   * Get a path to a module builder file or folder.
   */
  function getPath($subpath) {
    // On Drush we just have to jump through hoops.
    $mb_path = dirname(__FILE__) . '/..';

    $path = $mb_path . '/' . $subpath;

    return $path;
  }

  /**
   * Load an optionally versioned module builder include file.
   */
  function loadInclude($name, $extension = 'inc') {
    $path = $this->getPath('includes');
    // The NULL means drush_include will try to find the version.
    drush_include($path, $name, NULL, $extension);
  }

}