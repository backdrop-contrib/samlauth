<?php

declare(strict_types=1);

namespace Drupal\samlauth;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Provides a listing of identity providers.
 */
final class IdentityProviderListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    $header['entity_id'] = $this->t('Entity ID');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\samlauth\IdentityProviderInterface $entity */
    $row['label'] = $entity->label();
    $row['entity_id'] = $entity->getEntityID();
    return $row + parent::buildRow($entity);
  }

}
