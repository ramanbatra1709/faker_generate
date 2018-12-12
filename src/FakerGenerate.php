<?php

namespace Drupal\faker_generate;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Faker;

/**
 * Provides a various helper functions for content generation.
 */
class FakerGenerate {

  /**
   * @param $number
   * @return array
   */
  public static function getUsers($number) {
    $users = array();
    $result = db_query_range("SELECT uid FROM {users}", 0, $number);
    foreach ($result as $record) {
      $users[] = $record->uid;
    }
    return $users;
  }

  /**
   * @param $values
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public static function deleteContent($values) {
    $nids = \Drupal::entityQuery('node')
      ->condition('type', $values, 'IN')
      ->execute();

    if (!empty($nids)) {
      $storage_handler = \Drupal::entityTypeManager()->getStorage("node");
      $nodes = $storage_handler->loadMultiple($nids);
      $storage_handler->delete($nodes);
      drupal_set_message(t('Deleted %count nodes.', array('%count' => count($nids))));
    }
  }

  /**
   * @param $values
   * @param $context
   * @throws EntityStorageException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public static function generateContent($values, &$context )  {

    $faker = Faker\Factory::create();

    if (!isset($values['settings']['time_range'])) {
      $values['settings']['time_range'] = 0;
    }
    $content_types = $values['settings']['node_types'];
    $num = $values['settings']['num'];

    $users = FakerGenerate::getUsers($num);

    if (!empty($values['settings']['delete']) && array_filter($content_types)) {
      FakerGenerate::deleteContent(array_filter($content_types));
    }

    $results = array();

    if (empty($context['sandbox'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = $num;
    }

    for ($i = 0; $i < $num; $i++) {

      $context['message'] = 'Creating node ' . ($i + 1);
      $content_type = array_rand(array_filter($content_types));
      $uid = $users[array_rand($users)];

      $node = Node::create([
        'nid' => NULL,
        'type' => $content_type,
        'title' => $faker->realText(50),
        'uid' => $uid,
        'revision' => $faker->boolean,
        'status' => TRUE,
        'promote' => $faker->boolean,
        'created' => REQUEST_TIME - mt_rand(0, $values['settings']['time_range']),
        'langcode' => 'en'
      ]);

      $entityManager = \Drupal::service('entity_field.manager');
      $fields = $entityManager->getFieldDefinitions('node', $content_type);

      foreach ($fields as $field_name => $field_definition) {

        if (!empty($field_definition->getTargetBundle())) {
          $bundleFields[$field_name]['type'] = $field_definition->getType();
          $value = null;
          switch($bundleFields[$field_name]['type'])  {

            case 'boolean':
              $value = $faker->boolean;
              break;

            case 'datetime':
              $value = $faker->date();
              break;

            case 'decimal':
              $value = $faker->randomFloat();
              break;

            case 'email':
              $value = $faker->email;
              break;

            case 'float':
              $value = $faker->randomFloat();
              break;

            case 'image':
              $image = $faker->image('sites/default/files', $width = 640, $height = 480);
              $file = File::create([
                'uri' => $image,
              ]);
              $file->save();
              $value = [
                'target_id' => $file->id(),
                'alt' => $faker->realText(20),
                'title' => $faker->realText(20)
              ];
              break;

            case 'integer':
              $value = $faker->randomNumber();
              break;

            case 'link':
              $value = $faker->url;
              break;

            case 'string':
              $value = $faker->realText($maxNbChars = 100, $indexSize = 2);
              break;

            case 'string_long':
              $value = $faker->realText($maxNbChars = 300, $indexSize = 2);
              break;

            case 'timestamp':
              $value = $faker->unixTime;
              break;

            case 'text_with_summary':
              $value = $faker->realText($maxNbChars = 600, $indexSize = 2);
              break;

          }
          $node->set($field_definition->getName(), $value);
        }
      }
      try {
        $results[] = $node->save();
      } catch (EntityStorageException $e) {
        \Drupal::logger('fake_generator')->error('Could not store the node: ' . $e->getMessage());
      }
      $context['sandbox']['progress']++;
    }
    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
    $context['results'] = $results;
  }

  /**
   * @param $success
   * @param $results
   * @param $operations
   */
  function nodesGeneratedFinishedCallback($success, $results, $operations) {
    if ($success) {
      $message = \Drupal::translation()->formatPlural(
        count($results),
        'One node created.', '@count nodes created.'
      );
    }
    else {
      $message = t('Finished with an error.');
    }
    drupal_set_message($message);
  }

}
