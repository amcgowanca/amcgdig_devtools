<?php
/**
 * @file
 */

use Drupal\amcgdig_devtools\Batch\EntityModerationStateUpdate;
use Drush\Log\LogLevel;

/**
 * Implements hook_drush_command().
 */
function amcgdig_devtools_drush_command() {
  $commands = [];
  $commands['amcgdig-devtools-content-moderation-state-update'] = [
    'description' => 'Provides a mechanism for updating content entities with default moderation states.',
    'options' => [
      'batch-size' => [
        'description' => 'An integer containing the number of items per batch to process.',
      ],
      'start-at' => [
        'description' => 'The entity id to start at for processing.',
      ],
      'limit' => [
        'description' => 'The number of entities to process.',
      ],
    ],
    'aliases' => ['amcgdig-dt-cm-update'],
  ];
  return $commands;
}

/**
 * Drush callback for `drush amcgdig-devtools-content-moderation-state-update`.
 */
function drush_amcgdig_devtools_content_moderation_state_update() {
  $_start_time = microtime(TRUE);

  $batch_size = (int) drush_get_option('batch-size', 5);
  $start_at = drush_get_option('start-at', 0);
  $limit = drush_get_option('limit', NULL);

  $batch = [
    'title' => 'Updating content moderation states.',
    'operations' => [
      [
        [EntityModerationStateUpdate::class, 'contentSave'],
        [$batch_size, $start_at, $limit],
      ],
    ],
    'finished' => [EntityModerationStateUpdate::class, 'finished'],
  ];

  batch_set($batch);
  $batch = &batch_get();
  $batch['progressive'] = FALSE;
  drush_backend_batch_process();

  drush_log(dt('Completed in @seconds seconds.', [
    '@seconds' => _amcgdig_devtools_elapsed_time_since($_start_time),
  ]), LogLevel::SUCCESS);

}
