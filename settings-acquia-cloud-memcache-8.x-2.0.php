<?php
/**
 * @file
 * Contains memcache configuration for Memcache 8.x-2.0 module.
 */
use Composer\Autoload\ClassLoader;

/**
 * Use memcache as cache backend.
 *
 * Autoload memcache classes and service container in case module is not
 * installed. Avoids the need to patch core and allows for overriding the
 * default backend when installing Drupal.
 *
 * @see https://www.drupal.org/node/2766509
 */

// MODIFY THIS NEXT LINE depending on the location of the memcache Drupal
//   module in your codebase.
$memcache_module_folder = 'modules/contrib/memcache';

if (getenv('AH_SITE_ENVIRONMENT') &&
  isset($settings['memcache']['servers'])
) {

  // Check for PHP Memcached libraries.
  $memcache_exists = class_exists('Memcache', FALSE);
  $memcached_exists = class_exists('Memcached', FALSE);

  $memcache_services_yml = DRUPAL_ROOT . '/' . $memcache_module_folder . '/memcache.services.yml';
  $memcache_module_is_present = file_exists($memcache_services_yml);
  if ($memcache_module_is_present && ($memcache_exists || $memcached_exists)) {
    // Use Memcached extension if available.
    if ($memcached_exists) {
      $settings['memcache']['extension'] = 'Memcached';
    }
    if (class_exists(ClassLoader::class)) {
      $class_loader = new ClassLoader();
      $class_loader->addPsr4('Drupal\\memcache\\', $memcache_module_folder . '/src');
      $class_loader->register();
      $settings['container_yamls'][] = $memcache_services_yml;
      // Bootstrap cache.container with memcache rather than database.
      $settings['bootstrap_container_definition'] = [
        'parameters' => [],
        'services' => [
          'database' => [
            'class' => 'Drupal\Core\Database\Connection',
            'factory' => 'Drupal\Core\Database\Database::getConnection',
            'arguments' => ['default'],
          ],
          'settings' => [
            'class' => 'Drupal\Core\Site\Settings',
            'factory' => 'Drupal\Core\Site\Settings::getInstance',
          ],
          'memcache.settings' => [
            'class' => 'Drupal\memcache\MemcacheSettings',
            'arguments' => ['@settings'],
          ],
          'memcache.factory' => [
            'class' => 'Drupal\memcache\Driver\MemcacheDriverFactory',
            'arguments' => ['@memcache.settings'],
          ],
          'memcache.timestamp.invalidator.bin' => [
            'class' => 'Drupal\memcache\Invalidator\MemcacheTimestampInvalidator',
            # Adjust tolerance factor as appropriate when not running memcache on localhost.
            'arguments' => ['@memcache.factory', 'memcache_bin_timestamps', 0.001],
          ],
          'memcache.backend.cache.container' => [
            'class' => 'Drupal\memcache\DrupalMemcacheInterface',
            'factory' => ['@memcache.factory', 'get'],
            'arguments' => ['container'],
          ],
          'cache_tags_provider.container' => [
            'class' => 'Drupal\Core\Cache\DatabaseCacheTagsChecksum',
            'arguments' => ['@database'],
          ],
          'cache.container' => [
            'class' => 'Drupal\memcache\MemcacheBackend',
            'arguments' => [
              'container',
              '@memcache.backend.cache.container',
              '@cache_tags_provider.container',
              '@memcache.timestamp.invalidator.bin',
            ],
          ],
        ],
      ];
      // Override default fastchained backend for static bins.
      // @see https://www.drupal.org/node/2754947
      $settings['cache']['bins']['bootstrap'] = 'cache.backend.memcache';
      $settings['cache']['bins']['discovery'] = 'cache.backend.memcache';
      $settings['cache']['bins']['config'] = 'cache.backend.memcache';
      // Use memcache as the default bin.
      $settings['cache']['default'] = 'cache.backend.memcache';
      // Enable stampede protection.
      $settings['memcache']['stampede_protection'] = TRUE;
      // Move locks to memcache.
      // MODIFY the following path (relative to DRUPAL_ROOT) depending on where
      //    you placed this file in your codebase.
      $settings['container_yamls'][] = 'sites/all/memcache-locks.yml';

      // OPTIONAL: Set compression; Research is inconclusive on the benefits of compression.
      // $settings['memcache']['options'][Memcached::OPT_COMPRESSION] = TRUE;
    }
  }
  else {
    // Log the fact that code wants to use memcache but
    //   it's not being able to find what it needs.
    $output = "/mnt/tmp/" . getenv("AH_SITE_NAME") . "/cloud-memcache-8.x-2.0-error.log";
    $datetime = gmdate("Y/m/j H:i:s T");
    file_put_contents($output, "[$datetime] Could not enable memcache module integration");
  }
}
