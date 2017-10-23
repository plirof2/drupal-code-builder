<?php

/**
 * @file
 * Contains DrupalCodeBuilder\Task\Collect8.
 */

namespace DrupalCodeBuilder\Task;

/**
 * Task handler for collecting and processing component definitions.
 *
 * This collects data on hooks and plugin types.
 */
class Collect8 extends Collect {

  /**
   * Collect data about Drupal components from the current site's codebase.
   */
  public function collectComponentData() {
    $this->collectHooks();
    $this->collectPlugins();
    $this->collectServices();
  }

  /**
   * Collect data about plugin types and process it.
   */
  protected function collectPlugins() {
    $plugin_manager_service_ids = $this->getPluginManagerServices();

    $plugin_type_data = $this->gatherPluginTypeInfo($plugin_manager_service_ids);

    // Save the data.
    $this->writeProcessedData($plugin_type_data, 'plugins');
  }

  /**
   * Detects services which are plugin managers.
   *
   * @return
   *  An array of service IDs of all the services which we detected to be plugin
   *  managers.
   */
  protected function getPluginManagerServices() {
    // Get the IDs of all services from the container.
    $service_ids = \Drupal::getContainer()->getServiceIds();
    //drush_print_r($service_ids);

    // Filter them down to the ones that are plugin managers.
    // TODO: this omits some that don't conform to this pattern! Deal with
    // these! See https://www.drupal.org/node/2086181
    $plugin_manager_service_ids = array_filter($service_ids, function($element) {
      if (strpos($element, 'plugin.manager.') === 0) {
        return TRUE;
      }
    });

    //drush_print_r($plugin_manager_service_ids);

    // Developer trapdoor: just process the block plugin type, to make terminal
    // debug output easier to read through.
    //$plugin_manager_service_ids = array('plugin.manager.block');

    return $plugin_manager_service_ids;
  }

  /**
   * Detects information about plugin types from the plugin manager services
   *
   * @param $plugin_manager_service_ids
   *  An array of service IDs.
   *
   * @return
   *  The assembled plugin type data. This is an array keyed by plugin type ID
   *  (where we take this to be the name of the plugin manager service for that
   *  type, with the 'plugin.manager.' prefix removed). Values are arrays with
   *  the following properties:
   *    - 'type_id': The plugin type ID.
   *    - 'type_label': A label for the plugin type. If Plugin module is present
   *      then this is the label from the definition there, if found. Otherwise,
   *      this duplicates the ID.
   *    - 'service_id': The ID of the service for the plugin type's manager.
   *    - 'subdir: The subdirectory of /src that plugin classes must go in.
   *      E.g., 'Plugin/Filter'.
   *    - 'plugin_interface': The interface that plugin classes must implement,
   *      as a qualified name (but without initial '\').
   *    - 'plugin_definition_annotation_name': The class that the plugin
   *      annotation uses, as a qualified name (but without initial '\').
   *      E.g, 'Drupal\filter\Annotation\Filter'.
   *    - 'plugin_interface_methods': An array of methods that the plugin's
   *      interface has. This is keyed by the method name, with each value an
   *      array with these properties:
   *      - 'name': The method name.
   *      - 'declaration': The method declaration line from the interface.
   *      - 'description': The text from the first line of the docblock.
   *    - 'plugin_properties: Properties that the plugin class may declare in
   *      its annotation. These are deduced from the class properties of the
   *      plugin type's annotation class. An array keyed by the property name,
   *      whose values are arrays with these properties:
   *      - 'name': The name of the property.
   *      - 'description': The description, taken from the docblock of the class
   *        property on the annotation class.
   *      - 'type': The data type.
   *
   *  Due to the difficult nature of analysing the code for plugin types, some
   *  of these properties may be empty if they could not be deduced.
   */
  protected function gatherPluginTypeInfo($plugin_manager_service_ids) {
    // Assemble a basic array of plugin type data, that we will successively add
    // data to.
    $plugin_type_data = array();
    foreach ($plugin_manager_service_ids as $plugin_manager_service_id) {
      // We identify plugin types by the part of the plugin manager service name
      // that comes after 'plugin.manager.'.
      $plugin_type_id = substr($plugin_manager_service_id, strlen('plugin.manager.'));

      $plugin_type_data[$plugin_type_id] = [
        'type_id' => $plugin_type_id,
        'service_id' => $plugin_manager_service_id,
        // Plugin module may replace this if present.
        'type_label' => $plugin_type_id,
      ];
    }

    // Get plugin type information if Plugin module is present.
    $this->addPluginModuleData($plugin_type_data);

    // Add data from the plugin type manager service.
    // This gets us the subdirectory, interface, and annotation name.
    $this->addPluginTypeServiceData($plugin_type_data);

    // Add data from the plugin interface (which the manager service gave us).
    $this->addPluginInterfaceData($plugin_type_data);

    // Add data from the plugin annotation class.
    $this->addPluginAnnotationData($plugin_type_data);

    // Try to detect a base class for plugins
    $this->addPluginBaseClass($plugin_type_data);

    // Sort by ID.
    ksort($plugin_type_data);

    //drush_print_r($plugin_type_data);

    return $plugin_type_data;
  }

  /**
   * Adds plugin type information from Plugin module if present.
   *
   * @param &$plugin_type_data
   *  The array of plugin data.
   */
  protected function addPluginModuleData(&$plugin_type_data) {
    // Bail if Plugin module isn't present.
    if (!\Drupal::hasService('plugin.plugin_type_manager')) {
      return;
    }

    // This gets us labels for the plugin types which are declared to Plugin
    // module.
    $plugin_types = \Drupal::service('plugin.plugin_type_manager')->getPluginTypes();

    // We need to re-key these by the service ID, as Plugin module uses IDs for
    // plugin types which don't always the ID we use for them based on the
    // plugin manager service ID, , e.g. views_access vs views.access.
    // Unfortunately, there's no accessor for this, so some reflection hackery
    // is required until https://www.drupal.org/node/2907862 is fixed.
    $reflection = new \ReflectionProperty(\Drupal\plugin\PluginType\PluginType::class, 'pluginManagerServiceId');
    $reflection->setAccessible(TRUE);

    foreach ($plugin_types as $plugin_type) {
      // Get the service ID from the reflection, and then our ID.
      $plugin_manager_service_id = $reflection->getValue($plugin_type);
      $plugin_type_id = substr($plugin_manager_service_id, strlen('plugin.manager.'));

      if (!isset($plugin_type_data[$plugin_type_id])) {
        return;
      }

      // Replace the default label with the one from Plugin module, casting it
      // to a string so we don't have to deal with TranslatableMarkup objects.
      $plugin_type_data[$plugin_type_id]['type_label'] = (string) $plugin_type->getLabel();
    }
  }

  /**
   * Adds plugin type information from each plugin type manager service.
   *
   * This adds:
   *  - subdir
   *  - pluginInterface
   *  - pluginDefinitionAnnotationName
   *
   * @param &$plugin_type_data
   *  The array of plugin data.
   */
  protected function addPluginTypeServiceData(&$plugin_type_data) {
    foreach ($plugin_type_data as $plugin_type_id => &$data) {
      // Get the service, and then get the properties that the plugin manager
      // constructor sets.
      // E.g., most plugin managers pass this to the parent:
      //   parent::__construct('Plugin/Block', $namespaces, $module_handler, 'Drupal\Core\Block\BlockPluginInterface', 'Drupal\Core\Block\Annotation\Block');
      // See Drupal\Core\Plugin\DefaultPluginManager
      $service = \Drupal::service($data['service_id']);
      $reflection = new \ReflectionClass($service);

      // The list of properties we want to grab out of the plugin manager
      //  => the key in the plugin type data array we want to set this into.
      $plugin_manager_properties = [
        'subdir' => 'subdir',
        'pluginInterface' => 'plugin_interface',
        'pluginDefinitionAnnotationName' => 'plugin_definition_annotation_name',
      ];
      foreach ($plugin_manager_properties as $property_name => $data_key) {
        if (!$reflection->hasProperty($property_name)) {
          // plugin.manager.menu.link is different.
          $data[$data_key] = '';
          continue;
        }

        $property = $reflection->getProperty($property_name);
        $property->setAccessible(TRUE);
        $data[$data_key] = $property->getValue($service);
      }
    }
  }

  /**
   * Adds plugin type information from the plugin interface.
   *
   * @param &$plugin_type_data
   *  The array of plugin data.
   */
  protected function addPluginInterfaceData(&$plugin_type_data) {
    foreach ($plugin_type_data as $plugin_type_id => &$data) {
      // Analyze the interface, if there is one.
      if (empty($data['plugin_interface'])) {
        $data['plugin_interface_methods'] = array();
      }
      else {
        $data['plugin_interface_methods'] = $this->collectPluginInterfaceMethods($data['plugin_interface']);
      }
    }
  }

  /**
   * Get data for the methods of a plugin interface.
   *
   * Helper for addPluginInterfaceData().
   *
   * @param $plugin_interface
   *  The fully-qualified name of the interface.
   *
   * @return
   *  An array keyed by method name, where each value is an array containing:
   *  - 'name: The name of the method.
   *  - 'declaration': The function declaration line.
   *  - 'description': The description from the method's docblock first line.
   */
  protected function collectPluginInterfaceMethods($plugin_interface) {
    // Get a reflection class for the interface.
    $plugin_interface_reflection = new \ReflectionClass($plugin_interface);
    $methods = $plugin_interface_reflection->getMethods();

    $data = [];

    foreach ($methods as $method) {
      if ($method->getName() != 'storageSettingsForm') {
        //continue;
      }

      $interface_method_data = [];

      $interface_method_data['name'] = $method->getName();

      // Methods may be in parent interfaces, so not all in the same file.
      $filename = $method->getFileName();
      $source = file($filename);
      $start_line = $method->getStartLine();

      // Trim whitespace from the front, as this will be indented.
      $interface_method_data['declaration'] = trim($source[$start_line - 1]);

      // Get the docblock for the method.
      $method_docblock_lines = explode("\n", $method->getDocComment());
      foreach ($method_docblock_lines as $line) {
        // Take the first actual docblock line to be the description.
        if (substr($line, 0, 5) == '   * ') {
          $interface_method_data['description'] = substr($line, 5);
          break;
        }
      }

      // Replace class typehints on method parameters with their full namespaced
      // versions, as typically these will be short class names. The PHPFile
      // generator will then take care of extracting namespaces and creating
      // import statements.
      // Get the typehint classes on parameters.
      $parameters = $method->getParameters();
      $parameter_hinted_class_short_names = [];
      $parameter_hinted_class_full_names = [];
      foreach ($parameters as $parameter) {
        $parameter_hinted_class = $parameter->getClass();

        // Skip a parameter that doesn't have a class hint.
        if (is_null($parameter_hinted_class)) {
          continue;
        }

        // Create arrays for str_replace() of short and long classnames.
        $parameter_hinted_class_short_names[] = $parameter_hinted_class->getShortName();
        // The PHPFile generator works with fully-qualified classnames, with
        // an initial '\', so we need to prepend that.
        $parameter_hinted_class_full_names[] = '\\' . $parameter_hinted_class->getName();
      }

      $interface_method_data['declaration'] = str_replace(
        $parameter_hinted_class_short_names,
        $parameter_hinted_class_full_names,
        $interface_method_data['declaration']
      );

      $data[$method->getName()] = $interface_method_data;
    }

    return $data;
  }

  /**
   * Adds plugin type information from the plugin annotation class.
   *
   * @param &$plugin_type_data
   *  The array of plugin data.
   */
  protected function addPluginAnnotationData(&$plugin_type_data) {
    foreach ($plugin_type_data as $plugin_type_id => &$data) {
      if (isset($data['plugin_definition_annotation_name']) && class_exists($data['plugin_definition_annotation_name'])) {
        $data['plugin_properties'] = $this->collectPluginAnnotationProperties($data['plugin_definition_annotation_name']);
      }
      else {
        $data['plugin_properties'] = [];
      }
    }
  }

  /**
   * Get the list of properties from an annotation class.
   *
   * Helper for addPluginAnnotationData().
   *
   * @param $plugin_annotation_class
   *  The fully-qualified name of the plugin annotation class.
   *
   * @return
   *  An array keyed by property name, where each value is an array containing:
   *  - 'name: The name of the property.
   *  - 'description': The description from the property's docblock first line.
   */
  protected function collectPluginAnnotationProperties($plugin_annotation_class) {
    // Get a reflection class for the annotation class.
    // Each property of the annotation class describes a property for the
    // plugin annotation.
    $annotation_reflection = new \ReflectionClass($plugin_annotation_class);
    $properties_reflection = $annotation_reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

    $plugin_properties = [];
    foreach ($properties_reflection as $property_reflection) {
      // Assemble data about this annotation property.
      $annotation_property_data = array();
      $annotation_property_data['name'] = $property_reflection->name;

      // Get the docblock for the property, so we can figure out whether the
      // annotation property requires translation, and also add detail to the
      // annotation code.
      $property_docblock = $property_reflection->getDocComment();
      $property_docblock_lines = explode("\n", $property_docblock);
      foreach ($property_docblock_lines as $line) {
        if (substr($line, 0, 3) == '/**') {
          continue;
        }

        // Take the first actual docblock line to be the description.
        if (!isset($annotation_property_data['description']) && substr($line, 0, 5) == '   * ') {
          $annotation_property_data['description'] = substr($line, 5);
        }

        // Look for a @var token, to tell us the type of the property.
        if (substr($line, 0, 10) == '   * @var ') {
          $annotation_property_data['type'] = substr($line, 10);
        }
      }

      $plugin_properties[$property_reflection->name] = $annotation_property_data;
    }

    return $plugin_properties;
  }

  /**
   * Adds plugin type information from the plugin annotation TODO!.
   *
   * @param &$plugin_type_data
   *  The array of plugin data.
   */
  protected function addPluginBaseClass(&$plugin_type_data) {
    foreach ($plugin_type_data as $plugin_type_id => &$data) {
      $service = \Drupal::service($data['service_id']);

      $service_class_name = get_class($service);
      // Get the module or component that the service class is in.
      $service_component_namespace = $this->getClassComponentNamespace($service_class_name);

      // Work over each plugin of this type, until we find one with a suitable-
      // looking ancestor class.
      $definitions = $service->getDefinitions();
      foreach ($definitions as $plugin_id => $definition) {
        // We can't work with plugins that don't define a class: skip the whole
        // plugin type.
        if (empty($definition['class'])) {
          goto done_plugin_type;
        }

        $plugin_component_namespace = $this->getClassComponentNamespace($definition['class']);

        // Get the full ancestry of the plugin's class.
        $plugin_class_reflection = new \ReflectionClass($definition['class']);

        $class_reflection = $plugin_class_reflection;
        $lineage = [];
        $parent_class_component_namespace = NULL;
        while ($class_reflection = $class_reflection->getParentClass()) {
          $lineage[] = $class_reflection->getName();
        }

        // We want the oldest ancestor which is in the same namespace as the
        // plugin manager. The lineage array has the oldest ancestors last.
        while ($ancestor_class = array_pop($lineage)) {
          $parent_class_component_namespace = $this->getClassComponentNamespace($ancestor_class);

          if ($parent_class_component_namespace == $service_component_namespace) {
            // We've found an ancestor class in the plugin's hierarchy which is
            // in the same namespace as the plugin manager service. Assume it's
            // a good base class, and move on to the next plugin type.
            $data['base_class'] = $ancestor_class;

            // TODO: should we check more than the first plugin we find?

            goto done_plugin_type;
          }
        }
      }

      // Done with this plugin definition; move on to the next one.
      done_plugin_type:
    }
  }

  /**
   * Gets the namespace for the component a class is in.
   *
   * This is either a module namespace, or a core component namespace, e.g.:
   *  - 'Drupal\foo'
   *  - 'Drupal\Core\Foo'
   *  - 'Drupal\Component\Foo'
   *
   * @param string $class_name
   *  The class name.
   *
   * @return string
   *  The namespace.
   */
  protected function getClassComponentNamespace($class_name) {
    $pieces = explode('\\', $class_name);

    if ($pieces[1] == 'Core' || $pieces[1] == 'Component') {
      return implode('\\', array_slice($pieces, 0, 3));
    }
    else {
      return implode('\\', array_slice($pieces, 0, 2));
    }
  }

  /**
   * Collect data about services.
   */
  protected function collectServices() {
    $service_definitions = $this->gatherServiceDefinitions();

    // Save the data.
    $this->writeProcessedData($service_definitions, 'services');
  }

  /**
   * Get definitions of services from the static container.
   *
   * We collect an incomplete list of services, namely, those which have special
   * methods in the \Drupal static container. This is because (AFAIK) these are
   * the only ones for which we can detect the interface and a description.
   */
  protected function gatherServiceDefinitions() {
    // We can get service IDs from the container,
    $static_container_reflection = new \ReflectionClass('\Drupal');
    $filename = $static_container_reflection->getFileName();
    $source = file($filename);

    $methods = $static_container_reflection->getMethods();
    $service_definitions = [];
    foreach ($methods as $method) {
      $name = $method->getName();

      // Skip any which have parameters: the service getter methods have no
      // parameters.
      if ($method->getNumberOfParameters() > 0) {
        continue;
      }

      $start_line = $method->getStartLine();
      $end_line = $method->getEndLine();

      // Skip any which have more than 2 lines: the service getter methods have
      // only 1 line of code.
      if ($end_line - $start_line > 2) {
        continue;
      }

      // Get the single code line.
      $code_line = $source[$start_line];

      // Extract the service ID from the call to getContainer().
      $matches = [];
      $code_line_regex = "@return static::getContainer\(\)->get\('([\w.]+)'\);@";
      if (!preg_match($code_line_regex, $code_line, $matches)) {
        continue;
      }
      $service_id = $matches[1];

      $docblock = $method->getDocComment();

      // Extract the interface for the service from the docblock @return.
      $matches = [];
      preg_match("[@return (.+)]", $docblock, $matches);
      $interface = $matches[1];

      // Extract a description from the docblock first line.
      $docblock_lines = explode("\n", $docblock);
      $doc_first_line = $docblock_lines[1];

      $matches = [];
      preg_match("@(the (.*))\.@", $doc_first_line, $matches);
      $description = ucfirst($matches[1]);
      $label = ucfirst($matches[2]);

      $service_definition = [
        'id' => $service_id,
        'label' => $label,
        'static_method' => $name,
        'interface' => $interface,
        'description' => $description,
      ];
      $service_definitions[$service_id] = $service_definition;
    }

    // Sort by ID.
    ksort($service_definitions);

    return $service_definitions;
  }

  /**
   * Gather hook documentation files.
   *
   * This retrieves a list of api hook documentation files from the current
   * Drupal install. On D8 these are files of the form MODULE.api.php and are
   * present in the codebase (rather than needing to be downloaded from an
   * online code repository viewer as is the case in previous versions of
   * Drupal).
   *
   * Because Drupal 8 puts api.php files in places other than module folders,
   * keys of the return array may be in one of these forms:
   *  - foo.api.php: The API file for foo module.
   *  - core:foo.api.php: The API file in a Drupal component.
   *  - core.api.php: The single core.api.php file.
   */
  protected function gatherHookDocumentationFiles() {
    // Get the hooks directory.
    $directory = \DrupalCodeBuilder\Factory::getEnvironment()->getHooksDirectory();

    // Get Drupal root folder as a file path.
    // DRUPAL_ROOT is defined both by Drupal and Drush.
    // @see _drush_bootstrap_drupal_root(), index.php.
    $drupal_root = DRUPAL_ROOT;

    $system_listing = \DrupalCodeBuilder\Factory::getEnvironment()->systemListing('/\.api\.php$/', 'modules', 'filename');
    // returns an array of objects, properties: uri, filename, name,
    // keyed by filename, eg 'comment.api.php'
    // What this does not give us is the originating module!

    // Add in api.php files in core/lib.
    $core_directory = new \RecursiveDirectoryIterator('core/lib/Drupal');
    $iterator = new \RecursiveIteratorIterator($core_directory);
    $regex = new \RegexIterator($iterator, '/^.+\.api.php$/i', \RecursiveRegexIterator::GET_MATCH);
    $core_api_files = [];
    foreach ($regex as $regex_files) {
      foreach ($regex_files as $file) {
        $filename = basename($file);

        $component_name = explode('.', $filename)[0];
        $system_listing['core:' . $filename] = (object) array(
          'uri' => $file,
          'filename' => $filename,
          'name' => basename($file, '.php'),
          'group' => 'core:' . $component_name,
          'module' => 'core',
        );
      }
    }

    // Add in core.api.php, which won't have been picked up because it's not
    // in a module!
    $system_listing['core.api.php'] = (object) array(
      'uri' => 'core/core.api.php',
      'filename' => 'core.api.php',
      'name' => 'core.api',
      'group' => 'core:core',
      'module' => 'core',
    );

    //print_r($system_listing);

    foreach ($system_listing as $key => $file) {
      // Extract the module name from the path.
      // WARNING: this is not always going to be correct: will fail in the
      // case of submodules. So Commerce is a big problem here.
      // We could instead assume we have MODULE.api.php, but some modules
      // have multiple API files with suffixed names, eg Services.
      // @todo: make this more robust, somehow!
      if (!isset($file->module)) {
        $matches = array();
        preg_match('@modules/(?:contrib/)?(\w+)@', $file->uri, $matches);
        //print_r($matches);
        $file->module = $matches[1];
        $file->group = $file->module;
      }
      //dsm($matches, $module);

      // Mark core files.
      $core = (substr($file->uri, 0, 4) == 'core');

      // Copy the file to the hooks directory.
      copy($drupal_root . '/' . $file->uri, $directory . '/' . $file->filename);

      $hook_files[$key] = array(
        'original' => $drupal_root . '/' . $file->uri, // no idea if useful
        'path' => $directory . '/' . $file->filename,
        'destination' => '%module.module', // Default. We override this below.
        'group'       => $file->group,
        'module'      => $file->module,
        'core'        => $core,
      );
    }

    // We now have the basics.
    // We should now see if some modules have extra information for us.
    $this->getHookDestinations($hook_files);

    return $hook_files;
  }

  /**
   * Add extra data about hook destinations to the hook file data.
   *
   * This allows entire files or individual hooks to have a file other than
   * the default %module.module as their destination.
   */
  private function getHookDestinations(&$hook_files) {
    // Get our data.
    $data = $this->getHookInfo();

    // Incoming data is destination key, array of hooks.
    // (Because it makes typing the data out easier! Computers can just adapt.)
    foreach ($data as $module => $module_data) {
      // The key in $hook_files we correspond to
      // @todo, possibly: this feels like slightly shaky ground.
      $filename = "$module.api.php";

      // Skip filenames we haven't already found, so we don't pollute our data
      // array with hook destination data for files that don't exist here.
      if (!isset($hook_files[$filename])) {
        continue;
      }

      // The module data can set a single destination for all its hooks.
      if (isset($module_data['destination'])) {
        $hook_files[$filename]['destination'] = $module_data['destination'];
      }
      // It can also (or instead) set a destination per hook.
      if (isset($module_data['hook_destinations'])) {
        $hook_files[$filename]['hook_destinations'] = array();
        foreach ($module_data['hook_destinations'] as $destination => $hooks) {
          $destinations[$module] = array_fill_keys($hooks, $destination);
          $hook_files[$filename]['hook_destinations'] += array_fill_keys($hooks, $destination);
        }
      }

      // Add the dependencies array as it comes; it will be processed per hook later.
      if (isset($module_data['hook_dependencies'])) {
        $hook_files[$filename]['hook_dependencies'] = $module_data['hook_dependencies'];
      }
    }

    //print_r($hook_files);
  }

  /**
   * Get info about hooks from Drupal.
   *
   * @return
   *  The data from hook_hook_info().
   */
  protected function getDrupalHookInfo() {
    $hook_info = \Drupal::service('module_handler')->getHookInfo();
    return $hook_info;
  }

  /**
   * {@inheritdoc}
   */
  protected function getAdditionalHookInfo() {
    // Keys should match the filename MODULE.api.php
    $info = array(
      // Hooks on behalf of Drupal core.
      // api.php files that are in core rather than in a module have a prefix of
      // 'core:'.
      'core:module' => array(
        'hook_destinations' => array(
          '%module.install' => array(
            'hook_requirements',
            'hook_schema',
            'hook_schema_alter',
            'hook_install',
            'hook_update_N',
            'hook_update_last_removed',
            'hook_uninstall',
          ),
        ),
      ),
    );
    return $info;
  }

}
