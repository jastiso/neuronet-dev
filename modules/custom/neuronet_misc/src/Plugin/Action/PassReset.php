<?php

namespace Drupal\neuronet_misc\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use \Drupal\user\Entity\User;


/**
 * Push term in front.
 *
 * @Action(
 *   id = "pass_reset",
 *   label = @Translation("Email password reset link"),
 *   type = "node"
 * )
 */
class PassReset extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
   /* if ($entity->hasField('field_push')) {
      $entity->field_push->value = 1;
      $entity->save();
    }*/
    $node = $entity;
     $connection = \Drupal::database();
    $query = $connection->query("SELECT entity_id FROM {user__field_profile} WHERE field_profile_target_id = :field_profile_target_id", [':field_profile_target_id' => $node->id()]);
    $result = $query->fetchAll();
      if (!empty($result)) {
        $uid = $result[0]->entity_id;
        $user = User::load($uid);
        if (is_object($user)){
          $mailManager = \Drupal::service('plugin.manager.mail');
          $langcode = $user->getPreferredLangcode();
          $params['context']['subject'] = "Password Reset Link for Neuronet";
          $link = user_pass_reset_url($user);
          $params['context']['message'] = _neuronet_pass_reset_body($link);
          $to = $user->get('mail')->value;
          $mailManager->mail('system', 'mail', $to, $langcode, $params);
          drupal_set_message($message = 'Password reset link sent to ' . $node->getTitle(), $type = 'status');
        }
      }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    //$result = $object->access('update', $account, TRUE)
      //->andIf($object->field_push->access('edit', $account, TRUE));

    return true;
  }

}

function _neuronet_pass_reset_body($link) {
  $body = "<p>Hi NGG student/alum!</p>
<p>Reset your password on NeuroNet here: <a href='" . $link . "'>LOG INTO NEURONET</a></p>

<p>Sincerely,</p>

<p>Alice Dallstream</p>
<p>The NeuroNet Committee (Matt Schaff, Alice Dallstream, Sydney Cason, Rebecca Somach, Jeni Stiso)</p>";
return $body;
}