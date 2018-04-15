<?php

namespace Drupal\amcgdig_devtools\Entity;

use Drupal\content_moderation\Entity\ContentModerationState as ContentModerationStateBase;

/**
 * Provides a wrapper for Drupal core's ContentModerationState class.
 */
class ContentModerationState extends ContentModerationStateBase {

  /**
   * {@inheritdoc}
   */
  public static function updateOrCreateFromEntity(ContentModerationStateBase $content_moderation_state) {
    return $content_moderation_state->realSave();
  }

}
