<?php
/**
 * @Copyright    (c) 2016 JoomTools.de - All rights reserved.
 * @package        JT - Formulator - Plugin for Joomla! 2.5.x and 3.x
 * @author         Guido De Gobbis
 * @link           http://www.joomtools.de
 *
 * @license        GPL v3
 **/

defined('_JEXEC') or die('Restricted access');

class plgContentJtformulator extends JPlugin
{

	protected $honeypot;
	protected $issetCaptcha;
	protected $validCaptcha = true;
	protected $validField = true;
	protected $fileFields = array();
	protected $submitFiles = array();

	// Formular
	protected $form = array();

	// Userparams
	protected $uParams = array();

	// Mail
	protected $mail = array();

	public function __construct(&$subject, $params)
	{
		if (JFactory::getApplication()->isAdmin())
		{
			return true;
		}

		parent::__construct($subject, $params);

		$version                   = new JVersion();
		$joomla_main_version       = substr($version->RELEASE, 0, strpos($version->RELEASE, '.'));
		$this->uParams['jversion'] = $joomla_main_version;

		$this->loadLanguage();
	}

	public function onContentPrepare($context, &$row, &$params, $page = 0)
	{
		if (JFactory::getApplication()->isAdmin()
			|| strpos($row->text, '{jtformulator') === false
		)
		{
			return;
		}

		$msg             = '';
		$error_msg       = '';
		$cIndex          = 0;
		$checkThemeFiles = array('fields'        => 'xml',
		                         'form'          => 'php',
		                         'message_html'  => 'php',
		                         'message_plain' => 'php'
		);

		$regex = "@(<(\w+)[^>]*>|){jtformulator(\s.*)?}(</\\2>|)@";

		// Get all matches or return
		if (!preg_match_all($regex, $row->text, $matches))
		{
			return;
		}

		JLoader::register('JFormField', dirname(__FILE__) . '/assets/jformfield.php');

		$this->uParams['captcha']   = count($matches[0]) ? $this->params->get('captcha') : false;

		foreach ($matches[0] as $matchKey => $matchValue)
		{
			$html    = '';
			$uParams = array();
			$lang    = JFactory::getLanguage();
			$tag     = $lang->getTag();
			$vars    = $matches[3][$matchKey] ? explode('|', $matches[3][$matchKey]) : array();

			if (!empty($vars))
			{
				foreach ($vars as $var)
				{
					list($key, $value) = explode('=', trim($var));
					$uParams[$key] = $value;
				}
			}

			$this->uParams['mailto'] = isset($uParams['mailto'])
				? str_replace('#', '@', $uParams['mailto'])
				: null;

			$uParams['theme'] = isset($uParams['theme'])
				? $uParams['theme']
				: 'default';

			$this->uParams['theme'] = $uParams['theme'];
			$this->uParams['index'] = $cIndex;

			if (isset($uParams['subject']))
			{
				$this->uParams['subject'] = $uParams['subject'];
			}

			foreach ($checkThemeFiles as $chkFile => $chkType)
			{
				$_checkTheme[$chkFile . '.' . $chkType] = $this->_getTmplPath($chkFile, $chkType) ? true : false;
			}

			$checkTheme = true;

			if (in_array(true, $_checkTheme) && in_array(false, $_checkTheme))
			{
				$checkTheme = false;

				JFactory::getApplication()
					->enqueueMessage(
						JText::sprintf('PLG_JT_FORMULATOR_FILES_ERROR', $this->uParams['theme'])
						, 'error'
					);
			}
			elseif (in_array(false, $_checkTheme))
			{
				$checkTheme = false;

				JFactory::getApplication()
					->enqueueMessage(
						JText::sprintf('PLG_JT_FORMULATOR_THEME_ERROR', $this->uParams['theme'])
						, 'error'
					);
			}

			if ($checkTheme)
			{
				$this->honeypot = '<input type="text"';
				$this->honeypot .= ' name="' . $uParams['theme'] . $cIndex . '[information_number]"';
				$this->honeypot .= ' style="position: absolute;top:-999em;left:-999em;height: 0;width: 0;"';
				$this->honeypot .= ' value="" />';

				$formLang = dirname(dirname(dirname(
					$this->_getTmplPath('language/' . $tag . '/' . $tag . '.' . $uParams['theme'] . '_form', 'ini')
				)));

				$this->loadLanguage($uParams['theme'] . '_form', $formLang);

				// Define Formfields
				$formXmlPath = $this->_getTmplPath('fields', 'xml');

				$field = new JForm($this->uParams['theme'] . $cIndex, array('control' => $uParams['theme'] . $cIndex));

				// Load Formfields
				$field->loadFile($formXmlPath);

				// Set Formfields
				$this->form[$uParams['theme'] . $cIndex] = $field;

				// Set Layouts override
				$template = JFactory::getApplication()->getTemplate();

				$this->form[$uParams['theme'] . $cIndex]->addLayoutsPath = array(
					JPATH_THEMES . '/' . $template . '/html/plg_content_jtformulator/layouts',
					JPATH_THEMES . '/' . $template . '/html/layouts',
					JPATH_PLUGINS . '/content/jtformulator/layouts'
				);

				// Get Framwork from FormXML if is set
				$framework = $field->getAttribute('framework') ? :'';
				$layoutSuffix = array();

				if (!empty($framework))
				{
					// Override global Framework
					$layoutSuffix = array($framework);
				}

				// Set Framework as Layout->Suffix
				$this->form[$uParams['theme'] . $cIndex]->framework = $layoutSuffix;

				$issetCaptcha = $this->_issetCaptcha();

				if (!$issetCaptcha)
				{
					$setCaptcha   = $this->_setCaptcha();
					$issetCaptcha = $setCaptcha ? 'captcha' : false;
				}

				//$this->issetCaptcha = $issetCaptcha;

				// Remove Captcha if disabled by plugin
				if (!$this->uParams['captcha'] && $issetCaptcha)
				{
					$this->form[$uParams['theme'] . $cIndex]->removeField($issetCaptcha);
				}

				// Get form submit task
				$task = JFactory::getApplication()->input->get('task', false, 'post');

				if ($task == $this->uParams['theme'] . $cIndex . "_sendmail")
				{
					// Get Form values
					$submitValues = JFactory::getApplication()->input->get($uParams['theme'] . $cIndex, array(), 'post', 'array');

					foreach ($submitValues as $subKey => $_subValue)
					{
						if (is_array($_subValue))
						{
							$subValue = array();
							foreach ($_subValue as $sValue)
							{
								$subValue[] = JText::_($sValue);
							}
						}
						else
						{
							$subValue = JText::_($_subValue);
						}
						$submitValues[$subKey] = $subValue;
					}

					switch (true)
					{
						case isset($submitValues['subject']):
							$this->mail['subject'] = 'subject';
							break;

						case !isset($submitValues['subject'])
							&& isset($uParams['subject']):
							$submitValues['subject'] = $this->mail['subject'] = $uParams['subject'];
							break;

						default:
							$submitValues['subject'] = $this->mail['subject'] = '';
							break;
					}

					$this->form[$uParams['theme'] . $cIndex]->bind($submitValues);

					if (!$submitValues['information_number'])
					{
						$valid = $this->_validate();
					}
					else
					{
						$valid = false;
					}

				}

				$formHtmlPath = $this->_getTmplPath('form');

				$html .= $this->_getTmpl($formHtmlPath);

				if ($task == $this->uParams['theme'] . $cIndex . "_sendmail")
				{

					if ($valid)
					{
						if ($this->_sendemail())
						{
							JFactory::getApplication()
								->enqueueMessage(JText::_('PLG_JT_FORMULATOR_EMAIL_THANKS'), 'message');

							JFactory::getApplication()
								->redirect(JRoute::_('index.php', false));
						}
					}
					if ($submitValues['information_number'])
					{
						JFactory::getApplication()
							->redirect(JRoute::_('index.php', false));
					}

				}

			}

			$pos = strpos($row->text, $matchValue);
			$end = strlen($matchValue);

			$row->text = substr_replace($row->text, $html, $pos, $end);
			$cIndex++;
		}
	}

	protected function _getTmplPath($filename, $type = 'php')
	{
		$template  = JFactory::getApplication()->getTemplate();
		$file      = $filename . '.' . $type;

		// Build template override path for theme
		$tAbsPath = JPATH_THEMES . '/' . $template
			. '/html/plg_content_jtformulator/'
			. $this->uParams['theme'] . '/' . $file;

		// Build plugin path for theme
		$bAbsPath = JPATH_PLUGINS . '/content/jtformulator/tmpl/'
			. $this->uParams['theme'] . '/' . $file;

		// Build fallback path with default theme
		$dAbsPath = JPATH_PLUGINS . '/content/jtformulator/tmpl/default/' . $file;

		// Set the right theme path
		if (file_exists($tAbsPath))
		{
			$return = $tAbsPath;
		}
		elseif (file_exists($bAbsPath))
		{
			$return = $bAbsPath;
		}
		else
		{
			$return = $dAbsPath;

			if ($this->uParams['theme'] != 'default' && $type != 'ini')
			{
				return false;
			}

		}

		return $return;
	}

	protected function _issetCaptcha()
	{
		$form   = $this->form[$this->uParams['theme'] . $this->uParams['index']];
		$fields = $form->getFieldset();

		foreach ($fields as $field)
		{

			$type = (string) $field->getAttribute('type');

			if ($type == 'captcha')
			{
				return (string) $field->getAttribute('name');
			}
		}

		return false;
	}

	protected function _setCaptcha()
	{
		$form = $this->form[$this->uParams['theme'] . $this->uParams['index']];
		$xml  = '<form><fieldset name="submit"><field name="captcha" type="captcha" validate="captcha" description="JTF_CAPTCHA_DESC" label="JTF_CAPTCHA_LABEL"></field></fieldset></form>';

		return $form->load($xml, false);
	}

	protected function _validate()
	{
		$token         = JSession::checkToken();
		$valid_captcha = true;
		$index         = $this->uParams['index'];
		$fieldXML      = $this->form[$this->uParams['theme'] . $index]->getXML();

		foreach ($fieldXML as $fieldset)
		{
			$count = count($fieldset->field);

			if ($count > 1)
			{
				foreach ($fieldset->field as $field)
				{
					$this->_validateField($field);
				}
			}
			else
			{
				$this->_validateField($fieldset->field);
			}
		}

		// for Debugging return false for validation
		//$this->validField = false;

		$valid = ($token && $this->validField) ? true : false;

		if (!empty($this->fileFields))
		{
			if ($valid)
			{
				$this->_clearOldFiles();
				$this->_saveFiles();
			}
			else
			{
				foreach ($this->fileFields as $fileField)
				{
					$this->_invalidField($fileField);
				}
			}
		}

		if ($this->validCaptcha !== true)
		{
			$this->_invalidField($this->issetCaptcha);
			$valid = false;
		}

		return $valid;
	}

	protected function _validateField($field)
	{
		$index         = $this->uParams['index'];
		$data          = $this->form[$this->uParams['theme'] . $index]->getData()->toArray();
		$rule          = false;
		$value         = '';
		$_showon_value = '';
		$showon        = (string) $field['showon'];
		$showField     = true;
		$validateField = true;
		$valid         = false;
		$type          = strtolower((string) $field['type']);
		$validate      = (string) $field['validate'];
		$required      = (string) $field['required'];
		$name          = (string) $field['name'];
		$class         = (string) $field['class'];
		$label         = (string) $field['label'];
		$label         = JText::_($label);

		if ($showon)
		{
			$_showon_value    = explode(':', $showon);
			$_showon_value[1] = JText::_($_showon_value[1]);
			$showon_value     = $this->form[$this->uParams['theme'] . $index]->getField($_showon_value[0])->value;

			if ($required && $_showon_value[1] != $showon_value)
			{
				$showField = false;
				$valid     = true;
				$this->form[$this->uParams['theme'] . $index]->setValue($name, null, '');
			}
		}

		if (isset($data[$name]))
		{
			$value = $data[$name];
		}

		if ($required && !$value)
		{
			if (!$showField)
			{
				$validateField = false;
			}
		}

		if ($validateField && $showField)
		{
			if ($type == 'file')
			{
				$jinput      = JFactory::getApplication()->input;
				$submitFiles = $jinput->files->get($this->uParams['theme'] . $index);

				if (count($submitFiles[$name]) >= 1 && !empty($submitFiles[$name][0]['name']))
				{
					$value              = $submitFiles['files'];
					$this->submitFiles  = array_merge_recursive($this->submitFiles, $submitFiles);
					$this->fileFields[] = $name;
				}
			}

			if ($field->option)
			{
				$oCount = count($field->option);

				for ($i = 0; $i < $oCount; $i++)
				{
					$_val = (string) $field->option[$i]->attributes()->value;
					if ($_val)
					{
						if (is_array($value))
						{
							$val = in_array(JText::_($_val), $value) ? JText::_($_val) : $_val;
						}
						else
						{
							$val = $value == JText::_($_val) ? $value : $_val;
						}

						$field->option[$i]->attributes()->value = $val;
					}
				}
			}

			if ($type == 'email')
			{
				$validate  = 'email';
				$emailName = $name;
				$field->addAttribute('tld', 'tld');
			}

			if ($validate)
			{
				$rule = JFormHelper::loadRuleType($validate);
			}

			if ($rule)
			{
				if ($type == 'captcha')
				{
					$valid = $rule->test($field, $value, null, null, $this->form[$this->uParams['theme'] . $index]);

					if ($valid !== true)
					{
						$this->validCaptcha = $valid;
						$this->issetCaptcha = $name;
						$valid              = false;
					}
				}
				else
				{
					$valid = $rule->test($field, $value);
				}
			}
			else
			{
				$valid = $validateField;
			}

		}

		if (!$valid && $type != 'captcha')
		{
			$this->_invalidField($name);

			if ($this->uParams['jversion'] <= '2')
			{
				$this->form[$this->uParams['theme'] . $index]->setValue($emailName, null, '');
				$this->_invalidField($name);
			}
		}
	}

	protected function _invalidField($fieldName)
	{
		$errorClass = $this->params->get('error_class', 'invalid');
		$formName   = $this->uParams['theme'] . $this->uParams['index'];
		$label      = $this->form[$formName]->getFieldAttribute($fieldName, 'label');
		$label      = JText::_($label);
		$class      = $this->form[$formName]->getFieldAttribute($fieldName, 'class');
		$labelClass = $this->form[$formName]->getFieldAttribute($fieldName, 'labelclass');

		if ($fieldName == $this->issetCaptcha)
		{
			$class = $class
				? trim(str_replace($errorClass, '', $class)) . ' '
				: '';

			$labelClass = $labelClass
				? trim(str_replace($errorClass, '', $labelClass)) . ' '
				: '';

			$this->form[$formName]->setFieldAttribute($fieldName, 'class', $class . $errorClass);
			$this->form[$formName]->setFieldAttribute($fieldName, 'labelclass', $labelClass . $errorClass);

			JFactory::getApplication()
				->enqueueMessage((string) $this->validCaptcha, 'error');
		}
		else
		{
			$class = $class
				? trim(str_replace($errorClass, '', $class)) . ' '
				: '';

			$labelClass = $labelClass
				? trim(str_replace($errorClass, '', $labelClass)) . ' '
				: '';

			$this->form[$formName]->setFieldAttribute($fieldName, 'class', $class . $errorClass);
			$this->form[$formName]->setFieldAttribute($fieldName, 'labelclass', $labelClass . $errorClass);

			JFactory::getApplication()
				->enqueueMessage(
					JText::sprintf('PLG_JT_FORMULATOR_FIELD_ERROR', $label), 'error'
				);
		}

		$this->validField = false;
	}

	protected function _clearOldFiles()
	{
		jimport('joomla.filesystem.folder');

		if (!$fileClear = (int) $this->params->get('file_clear'))
		{
			return;
		}

		$filePath   = !$this->params->get('file_path', 'uploads')
			? 'images/uploads'
			: 'images/' . $this->params->get('file_path');
		$uploadBase = JPATH_BASE . '/' . $filePath;

		if (!is_dir($uploadBase))
		{
			return;
		}

		$folders = JFolder::folders($uploadBase);
		$nowPath = date('Ymd');
		$now     = new DateTime($nowPath);

		foreach ($folders as $folder)
		{
			$date   = new DateTime($folder);
			$clrear = date_diff($now, $date)->days;

			if ($clrear >= $fileClear)
			{
				JFolder::delete($uploadBase . '/' . $folder);
			}
		}
	}

	protected function _saveFiles()
	{
		jimport('joomla.filesystem.folder');
		jimport('joomla.filesystem.file');

		$submitFiles = $this->submitFiles;
		$nowPath     = date('Ymd');

		$filePath = !$this->params->get('file_path', 'uploads')
			? 'images/uploads'
			: 'images/' . $this->params->get('file_path');

		$uploadBase = JPATH_BASE . '/' . $filePath . '/' . $nowPath;
		$uploadURL  = rtrim(JUri::base(), '/') . '/' . $filePath . '/' . $nowPath;
		$formName   = $this->uParams['theme'] . $this->uParams['index'];

		if (!is_dir($uploadBase))
		{
			JFolder::create($uploadBase);
		}

		if (!file_exists(JPATH_BASE . '/' . $filePath . '/.htaccess'))
		{
			JFile::write(JPATH_BASE . '/' . $filePath . '/.htaccess', JText::_('PLG_JT_FORMULATOR_SET_ATTACHMENT'));
		}

		foreach ($submitFiles as $fieldName => $files)
		{
			$value = array();

			foreach ($files as $file)
			{
				$save     = null;
				$fileName = JFile::stripExt($file['name']);
				$fileExt  = JFile::getExt($file['name']);
				$name     = JFilterOutput::stringURLSafe($fileName) . '.' . $fileExt;

				$save = JFile::copy($file['tmp_name'], $uploadBase . '/' . $name);

				if ($save)
				{
					$value[$name] = $uploadURL . '/' . $name;
				}
			}

			$this->form[$formName]->setValue($fieldName, null, $value);
		}
	}

	protected function _getTmpl($path)
	{

		$index = $this->uParams['index'];
		$id    = $this->uParams['theme'];
		$form  = $this->form[$this->uParams['theme'] . $index];

		// Start capturing output into a buffer
		ob_start();

		// Include the requested template filename in the local scope
		// (this will execute the view logic).
		include($path);

		// Done with the requested template; get the buffer and
		// clear it.
		$return = ob_get_contents();
		ob_end_clean();

		return $return;
	}

	protected function _sendemail()
	{
		$jConfig = JFactory::getConfig();
		$index   = $this->uParams['index'];
		$data    = $this->form[$this->uParams['theme'] . $index]->getData()->toArray();

		if ($this->mail)
		{

			foreach ($this->mail as $key => $field)
			{

				if (is_array($field))
				{

					foreach ($field as $value)
					{
						$_field[] = isset($data[$value]) ? $data[$value] : $value;
					}

					$field = implode(' ', $_field);

					unset($_field);
				}
				else
				{
					$field = isset($data[$field]) ? $data[$field] : $field;
				}

				$mail[$key] = $field;
			}

		}

		$sender_email = isset($mail['sender_email'])
			? $mail['sender_email']
			: $jConfig->get('mailfrom');

		$sender_name = isset($mail['sender_name'])
			? $mail['sender_name']
			: $jConfig->get('fromname');

		$recipient = $this->uParams['mailto']
			? $this->uParams['mailto']
			: $jConfig->get('mailfrom');

		$subject = (isset($mail['subject']) && !empty($mail['subject']))
			? $mail['subject']
			: JText::sprintf('PLG_JT_FORMULATOR_EMAIL_SUBJECT', $jConfig->get('sitename'));

		$mailer = JFactory::getMailer();
		$hBody  = $this->_getTmpl($this->_getTmplPath('message_html'));
		$pBody  = $this->_getTmpl($this->_getTmplPath('message_plain'));

		$mailer->setSender(array($sender_email, $sender_name));
		$mailer->addRecipient($recipient);
		$mailer->setSubject($subject);
		$mailer->IsHTML(true);
		$mailer->setBody($hBody);
		$mailer->AltBody = $pBody;

		$send = $mailer->Send();

		return $send;
	}
}
