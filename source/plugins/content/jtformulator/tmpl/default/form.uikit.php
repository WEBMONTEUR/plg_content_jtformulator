<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.jtformulator
 *
 * @author      Guido De Gobbis
 * @copyright   (c) 2017 JoomTools.de - All rights reserved.
 * @license     GNU General Public License version 3 or later
**/

defined('_JEXEC') or die;
JHtml::_('behavior.keepalive');
JHtml::_('behavior.formvalidation');

// Set Fieldname or own value
$this->mail['sender_name'] = 'name';

// If you have to concatenate more fields, use an array like this
//$this->mail['sender_name'] = array('anrede', 'vname', 'nname');

$this->mail['sender_email'] = 'email';

$invalidColor = '#ff0000';

JFactory::getDocument()->addStyleDeclaration("
	.invalid:not(label) { 
		border-color: " . $invalidColor . " !important;
		background-color: #f2dede !important;
	}
	.invalid { color: " . $invalidColor . " !important; }
	.inline { display: inline-block !important; }
");
?>

{emailcloak=off}
<div class="uk-grid contact-form">
	<form name="<?php echo $id . $index; ?>_form" id="<?php echo $id . $index; ?>_form"
		  action="<?php echo JRoute::_("index.php"); ?>" method="post" enctype="multipart/form-data"
		  class="form-validate uk-form uk-width-1-1">
		<p><strong><?php echo JText::_('JT_FORMULATOR_REQUIRED_FIELDS_FORM_LABEL'); ?></strong></p>
		<?php

		$fieldsets         = $form->getXML();
		$countFieldsets    = 1;
		$sumFieldsets      = count($fieldsets->fieldset);
		$submitSet         = false;

		foreach ($fieldsets->fieldset as $fieldset) :

			$fieldsetName = (string) $fieldset['name'];
			$fieldsetLabel = (string) $fieldset['label'];
			$fieldsetDesc  = (string) $fieldset['description'];
			$sumFields     = count($fieldset->field);
			$fieldsetClass = (string) $fieldset['class']
				? (string) $fieldset['class']
				: 'uk-width-1-1';

			if ($fieldsetName == 'submit' && $sumFields == 0)
			{
				$sumFields = 1;
			}

			if ($sumFields) :
				if ($countFieldsets % 2) : ?>
					<!--<div class="row">-->
				<?php endif; ?>

				<div class="<?php echo $fieldsetClass; ?> uk-panel uk-panel-box">
					<fieldset data-uk-margin>
						<?php if (isset($fieldsetLabel) && strlen($legend = trim(JText::_($fieldsetLabel)))) : ?>
							<legend><?php echo $legend; ?></legend>
						<?php endif; ?>
						<?php if (isset($fieldsetDesc) && strlen($desc = trim(JText::_($fieldsetDesc)))) : ?>
							<p><?php echo $desc; ?></p>
						<?php endif; ?>
						<?php foreach ($fieldset->field as $field)
						{

							$fieldName = (string) $field['name'];

							$renderOptions['gridgroup'] = (string) $field['gridgroup'];
							$renderOptions['gridlabel'] = (string) $field['gridlabel'];
							$renderOptions['gridfield'] = (string) $field['gridfield'];

							echo $form->renderField($fieldName, null, null, $renderOptions);
						}

						if ($fieldsetName == 'submit') :
							$submitSet = true; ?>
							<div class="uk-form-controls">
								<button class="uk-button uk-button-primary validate"
										type="submit"><?php echo JText::_('JSUBMIT'); ?></button>
							</div>
						<?php endif; ?>

					</fieldset>
				</div>
			<?php endif;

			$countFieldsets++;
			if ($countFieldsets % 2 || $countFieldsets > $sumFieldsets) : ?>
				<!--</div>-->
			<?php endif;

		endforeach;

		if ($submitSet === false) : ?>
			<div class="uk-form-row">
				<div class="uk-form-controls">
					<button class="uk-button uk-button-primary validate"
							type="submit"><?php echo JText::_('JSUBMIT'); ?></button>
				</div>
			</div>
		<?php endif; ?>

		<?php echo $this->honeypot; ?>
		<input type="hidden" name="option" value="<?php echo JFactory::getApplication()->input->get('option'); ?>" />
		<input type="hidden" name="task" value="<?php echo $id . $index; ?>_sendmail" />
		<input type="hidden" name="view" value="<?php echo JFactory::getApplication()->input->get('view'); ?>" />
		<input type="hidden" name="itemid" value="<?php echo JFactory::getApplication()->input->get('idemid'); ?>" />
		<input type="hidden" name="id" value="<?php echo JFactory::getApplication()->input->get('id'); ?>" />
		<?php echo JHtml::_('form.token'); ?>

	</form>
</div>
<script>
	jQuery(function($){
		$("body").on('DOMSubtreeModified', "#system-message-container", function() {
			var error = $(this).find('alert-error');
			if (undefined !== error) {
				$('html, body').animate({
					scrollTop: $(this).offset().top-100
				}, 0);
			}
		});
	});
</script>
