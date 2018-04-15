<?php

namespace Drupal\amcgdig_devtools\Batch;

use Drupal\content_moderation\Entity\ContentModerationState;
use Drupal\amcgdig_devtools\Entity\ContentModerationState as ContentModerationStateHelper;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drush\Log\LogLevel;

/**
 * Provides a batch worker for creation of content moderation entities.
 */
abstract class EntityModerationStateUpdate {

  /**
   * Performs content moderation save ops for Nodes.
   *
   * @param int $batch_size
   *   The number of items to process in a batch.
   * @param int $start_at
   *   The ID of the entity to start at.
   * @param int $limit
   *   The number of items
   * @param $context
   *
   * @throws \Exception
   *   Thrown in the event of an error.
   */
  public static function contentSave($batch_size, $start_at, $limit, &$context) {
    $moderation_storage = \Drupal::entityTypeManager()->getStorage('content_moderation_state');

    /** @var \Drupal\node\NodeStorageInterface $storage */
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    /** @var \Drupal\content_moderation\ModerationInformationInterface $moderation_information */
    $moderation_information = \Drupal::service('content_moderation.moderation_information');

    if (!isset($context['sandbox']['ids'])) {
      $entity_query = $node_storage->getQuery()
        ->condition('nid', $start_at, '>=')
        ->sort('nid', 'ASC');
      if ($limit) {
        $entity_query->range(0, $limit);
      }

      $entity_query->accessCheck(FALSE);

      $entity_ids = $entity_query->execute();

      if (empty($entity_ids)) {
        return;
      }

      $context['sandbox']['ids'] = array_values($entity_ids);
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['total'] = count($entity_ids);
    }

    $batch_entity_ids = array_slice($context['sandbox']['ids'], $context['sandbox']['progress'], $batch_size);
    if (!empty($batch_entity_ids)) {
      $_start_time = microtime(TRUE);
      $entities = $node_storage->loadMultiple($batch_entity_ids);

      /** @var \Drupal\node\NodeInterface[] $entities */
      foreach ($entities as $entity_id => $entity) {
        try {
          if (!$moderation_information->isModeratedEntity($entity)) {
            drush_log(dt('Entity @id is not moderated.', [
              '@id' => $entity->id(),
            ]), LogLevel::INFO);
          }

          $workflow = $moderation_information->getWorkflowForEntity($entity);

          $entity_moderation_state = NULL;
          $moderation_results = $moderation_storage->getQuery()
            ->condition('content_entity_type_id', $entity->getEntityTypeId())
            ->condition('content_entity_id', $entity->id())
            ->condition('content_entity_revision_id', $entity->getRevisionId())
            ->condition('langcode', $entity->language()->getId())
            ->condition('workflow', $workflow->id())
            ->execute();
          if (!empty($moderation_results)) {
            $moderation_result_id = current($moderation_results);
            $entity_moderation_state = $moderation_storage->load($moderation_result_id);
          }

          if (empty($entity_moderation_state)) {
            drush_log(dt('Content moderation state entity does not exist for entity @id.', [
              '@id' => $entity->id(),
            ]), LogLevel::DEBUG);

            $entity_moderation_state = ContentModerationState::create([
              'content_entity_type_id' => $entity->getEntityTypeId(),
              'content_entity_id' => $entity->id(),
              'langcode' => $entity->language()->getId(),
              'workflow' => $workflow->id(),
            ]);
            $entity_moderation_state->setOwnerId($entity->getOwnerId());
          }
          else {
            drush_log(dt('Entity content moderation state has ID @id and value @value.', [
              '@id' => $entity_moderation_state->id(),
              '@value' => $entity_moderation_state->get('moderation_state')->value,
            ]), LogLevel::DEBUG);
          }

          $moderation_state_value = $workflow->getTypePlugin()
            ->getInitialState($entity)
            ->id();
          if ($entity->isPublished()) {
            $moderation_state_value = 'published';
          }

          $entity_moderation_state->set('content_entity_revision_id', $entity->getRevisionId());
          $entity_moderation_state->set('moderation_state', $moderation_state_value);

          $return = ContentModerationStateHelper::updateOrCreateFromEntity($entity_moderation_state);

          switch ($return) {
            case SAVED_UPDATED:
              drush_log(dt('Updated content moderation state entity for entity @id.', [
                '@id' => $entity->id(),
              ]), LogLevel::DEBUG);
              break;

            case SAVED_NEW:
              drush_log(dt('Created content moderation state entity for entity @id.', [
                '@id' => $entity->id(),
              ]), LogLevel::DEBUG);
              break;
          }

          drush_log(dt('Saved entity "@id" with moderation state value "@value". [Status: @status]', [
            '@id' => $entity_id,
            '@status' => $entity->get('status')->value ? 'Published' : 'Draft',
            '@value' => $moderation_state_value,
          ]), LogLevel::INFO);

          $context['sandbox']['progress']++;
          $context['results'][] = $entity_id;
        }
        catch (\Exception $exception) {
          drush_log(dt('Failed to process @id (@i/@total) in @seconds seconds. [Exception: @exception]', [
            '@id' => $entity_id,
            '@i' => $context['sandbox']['progress'] + 1,
            '@total' => $context['sandbox']['total'],
            '@seconds' => _amcgdig_devtools_elapsed_time_since($_start_time),
            '@exception' => $exception->getMessage(),
          ]), LogLevel::ERROR);
          $context['sandbox']['progress']++;
        }
      }
    }

    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['total'];
    $context['message'] = new TranslatableMarkup('Processed @progress of @total entities.', [
      '@progress' => $context['sandbox']['progress'],
      '@total' => $context['sandbox']['total'],
    ]);
  }

  /**
   * Batch finished callback.
   *
   * @param bool $success
   *   A boolean indicating success or failure.
   * @param array $results
   *   An array of entity ids processed.
   */
  public static function finished($success, array $results) {
    if ($success) {
      $message = new PluralTranslatableMarkup(count($results), 'One entity processed.', '@count entities processed.');
    }
    else {
      $message = new TranslatableMarkup('Finished with an error.');
    }

    drupal_set_message($message);
  }

}
