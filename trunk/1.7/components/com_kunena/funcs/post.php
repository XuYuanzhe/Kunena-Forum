<?php
/**
 * @version $Id$
 * Kunena Component
 * @package Kunena
 *
 * @Copyright (C) 2008 - 2010 Kunena Team All rights reserved
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.com
 **/
// Dont allow direct linking
defined ( '_JEXEC' ) or die ();
kimport('kunena.forum.message.helper');
kimport('kunena.forum.topic.helper');

class CKunenaPost {
	public $allow = 0;

	function __construct() {
		$this->do = JRequest::getCmd ( 'do', '' );
		$this->action = JRequest::getCmd ( 'action', '' );

		$this->_app = & JFactory::getApplication ();
		$this->config = KunenaFactory::getConfig ();
		$this->_session = KunenaFactory::getSession ();
		$this->_db = &JFactory::getDBO ();
		$this->document = JFactory::getDocument ();
		require_once (KPATH_SITE . DS . 'lib' .DS. 'kunena.poll.class.php');
		$this->poll =& CKunenaPolls::getInstance();

		$this->my = &JFactory::getUser ();

		$this->id = JRequest::getInt ( 'id', 0 );
		if (! $this->id) {
			$this->id = JRequest::getInt ( 'parentid', 0 );
		}
		if (! $this->id) {
		// Support for old $replyto variable in post reply/quote
			$this->id = JRequest::getInt ( 'replyto', 0 );
		}
		$this->catid = JRequest::getInt ( 'catid', 0 );

		$this->msg_cat = null;

		$this->allow = 1;

		$this->cat_default_allow = null;

		$template = KunenaFactory::getTemplate();
		$this->params = $template->params;

		$this->numLink = null;
		$this->replycount= null;
	}

	// Temporary function to handle old style permission handling
	// TODO: Remove this when all functions are using new style
	protected function load() {
		if ($this->msg_cat)
			return true;

		if ($this->id) {
			// Check that message and category exists and fill some information for later use
			$query = "SELECT m.*, (mm.locked OR c.locked) AS locked, c.locked AS catlocked, t.message,
					c.name AS catname, c.parent_id AS catparent, c.pub_access,
					c.review, c.class_sfx, p.id AS poll_id, c.allow_anonymous,
					c.post_anonymous, c.allow_polls
				FROM #__kunena_messages AS m
				INNER JOIN #__kunena_messages AS mm ON mm.id=m.thread
				INNER JOIN #__kunena_messages_text AS t ON t.mesid=m.id
				INNER JOIN #__kunena_categories AS c ON c.id=m.catid
				LEFT JOIN #__kunena_polls AS p ON m.id=p.threadid
				WHERE m.id={$this->_db->Quote($this->id)}";

			$this->_db->setQuery ( $query );
			$this->msg_cat = $this->_db->loadObject ();
			if (! $this->msg_cat) {
				KunenaError::checkDatabaseError();
				echo JText::_ ( 'COM_KUNENA_POST_INVALID' );
				return false;
			}

			// Make sure that category id is from the message (post may have been moved)
			if ($this->do != 'domovepostnow' && $this->do != 'domergepostnow' && $this->do != 'dosplit') {
				$this->catid = $this->msg_cat->catid;
			}
			$this->cat_default_allow = $this->msg_cat->allow_anonymous;
		} else if ($this->catid) {
			// Check that category exists and fill some information for later use
			$this->_db->setQuery ( "SELECT 0 AS id, 0 AS thread, id AS catid, name AS catname, parent_id AS catparent, pub_access, locked, locked AS catlocked, review, class_sfx, allow_anonymous, post_anonymous, allow_polls
				FROM #__kunena_categories
				WHERE id={$this->_db->Quote($this->catid)}" );
			$this->msg_cat = $this->_db->loadObject ();
			if (! $this->msg_cat) {
				KunenaError::checkDatabaseError();
				echo JText::_ ( 'COM_KUNENA_NO_ACCESS' );
				return false;
			}
			$this->cat_default_allow = $this->msg_cat->allow_anonymous;
		} else {
			//get default category
			$this->_db->setQuery ( "SELECT c.allow_anonymous FROM `#__kunena_categories` AS c
				INNER JOIN `#__kunena_categories` AS p ON c.parent_id=p.id AND p.parent_id=0
				WHERE c.id IN ({$this->_session->allowed}) ORDER BY p.ordering, p.name, c.ordering, c.name LIMIT 1" );
			$this->cat_default_allow = $this->_db->loadResult ();
			KunenaError::checkDatabaseError();
		}

		// Check if anonymous user needs to log in
		if ($this->my->id == 0 && (! $this->config->pubwrite || ($this->catid && ! $this->_session->canRead ( $this->catid )))) {
			CKunenaTools::loadTemplate ( '/login.php' );
			return false;
		}
		// Check user access rights
		if (!empty ( $this->msg_cat->catparent ) && ! $this->_session->canRead ( $this->catid ) && ! CKunenaTools::isAdmin ()) {
			echo JText::_('COM_KUNENA_NO_ACCESS');
			return false;
		}

		return true;
	}

	protected function post() {
		$this->verifyCaptcha ();

		if ($this->tokenProtection ())
			return false;
		if ($this->floodProtection ())
			return false;
		if ($this->isUserBanned() )
			return false;
		if ($this->isIPBanned())
			return false;

		$fields ['name'] = JRequest::getString ( 'authorname', $this->getAuthorName () );
		$fields ['email'] = JRequest::getString ( 'email', null );
		$fields ['subject'] = JRequest::getVar ( 'subject', null, 'POST', 'string', JREQUEST_ALLOWRAW );
		$fields ['message'] = JRequest::getVar ( 'message', null, 'POST', 'string', JREQUEST_ALLOWRAW );
		$fields ['topic_emoticon'] = JRequest::getInt ( 'topic_emoticon', null );

		$options ['attachments'] = 1;
		$options ['anonymous'] = JRequest::getInt ( 'anonymous', 0 );
		$contentURL = JRequest::getVar ( 'contentURL', '' );

		require_once (KUNENA_PATH_LIB . DS . 'kunena.posting.class.php');
		$message = new CKunenaPosting ( );
		if (! $this->id) {
			$success = $message->post ( $this->catid, $fields, $options );
		} else {
			$success = $message->reply ( $this->id, $fields, $options );
		}

		if ($success) {
			$success = $message->save ();
		}

		// Handle errors
		if (! $success) {
			$errors = $message->getErrors ();
			foreach ( $errors as $field => $error ) {
				$this->_app->enqueueMessage ( $field . ': ' . $error, 'error' );
			}
			$this->redirectBack ();
		}

		$catinfo = $message->parent;
		$userid = $message->get ( 'userid' );
		$id = $message->get ( 'id' );
		$thread = $message->get('thread');
		$subject = $message->get('subject');
		$holdPost = $message->get ( 'hold' );

		$polltitle = JRequest::getString ( 'poll_title', 0 );
		$optionsnumbers = JRequest::getInt ( 'number_total_options', '' );
		$polltimetolive = JRequest::getString ( 'poll_time_to_live', 0 );

		//Insert in the database the informations for the poll and the options for the poll
		$poll_exist = null;
		if (! empty ( $optionsnumbers ) && ! empty ( $polltitle )) {
			$poll_exist = "1";
			//Begin Poll management options
			$poll_optionsID = JRequest::getVar('polloptionsID', array (), 'post', 'array');
			$optvalue = array();
			foreach($poll_optionsID as $opt) {
				if ( !empty($opt) ) $optvalue[] = $opt;
			}

			if ( !empty($optvalue) ) $this->poll->save_new_poll ( $polltimetolive, $polltitle, $id, $optvalue );
		}

		// TODO: replace this with better solution
		$this->_db->setQuery ( "SELECT COUNT(*) FROM #__kunena_messages WHERE thread={$this->_db->Quote($thread)}" );
		$limitstart = $this->_db->loadResult ();
		KunenaError::checkDatabaseError();
		//construct a useable URL (for plaintext - so no &amp; encoding!)
		jimport ( 'joomla.environment.uri' );
		$uri = & JURI::getInstance ( JURI::base () );
		$LastPostUrl = $uri->toString ( array ('scheme', 'host', 'port' ) ) . str_replace ( '&amp;', '&', CKunenaLink::GetThreadPageURL ( 'view', $this->catid, $thread, $limitstart, $this->config->messages_per_page, $id ) );

		$message->emailToSubscribers($LastPostUrl, $this->config->allowsubscriptions && ! $holdPost, $this->config->mailmod || $holdPost, $this->config->mailadmin || $holdPost);

		$redirectmsg = '';

		$subscribeMe = JRequest::getVar ( 'subscribeMe', '' );

		//now try adding any new subscriptions if asked for by the poster
		if ($subscribeMe == 1) {
			$query = "INSERT INTO #__kunena_user_topics (user_id,topic_id,category_id,subscribed) VALUES ({$this->_db->Quote($this->my->id)},{$this->_db->Quote($thread)},{$this->_db->Quote($this->catid)},1)
					ON DUPLICATE KEY UPDATE subscribed=1;";

			if (@$this->_db->query ()) {
				$redirectmsg .= JText::_ ( 'COM_KUNENA_POST_SUBSCRIBED_TOPIC' ) . '<br />';
			} else {
				$redirectmsg .= JText::_ ( 'COM_KUNENA_POST_NO_SUBSCRIBED_TOPIC' ) . '<br />';
			}
		}

		if ($holdPost == 1) {
			$redirectmsg .= JText::_ ( 'COM_KUNENA_POST_SUCCES_REVIEW' );
		} else {
			$redirectmsg .= JText::_ ( 'COM_KUNENA_POST_SUCCESS_POSTED' );
		}
		$this->_app->redirect ( CKunenaLink::GetMessageURL ( $id, $this->catid, 0, false ), $redirectmsg );
	}

	protected function editpostnow() {
		if ($this->tokenProtection ())
			return false;
		if (!$this->load())
			return false;
		if ($this->isUserBanned() )
			return false;
		if ($this->isIPBanned())
			return false;

		$fields ['name'] = JRequest::getString ( 'authorname', $this->msg_cat->name );
		$fields ['email'] = JRequest::getString ( 'email', null );
		$fields ['subject'] = JRequest::getVar ( 'subject', null, 'POST', 'string', JREQUEST_ALLOWRAW );
		$fields ['message'] = JRequest::getVar ( 'message', null, 'POST', 'string', JREQUEST_ALLOWRAW );
		$fields ['topic_emoticon'] = JRequest::getInt ( 'topic_emoticon', null );
		$fields ['modified_reason'] = JRequest::getString ( 'modified_reason', null );

		$options ['attachments'] = 1;
		$options ['anonymous'] = JRequest::getInt ( 'anonymous', 0 );

		require_once (KUNENA_PATH_LIB . DS . 'kunena.posting.class.php');
		$message = new CKunenaPosting ( );
		$success = $message->edit ( $this->id, $fields, $options );
		if ($success) {
			$success = $message->save ();
		}

		// Handle errors
		if (! $success) {
			$errors = $message->getErrors ();
			foreach ( $errors as $field => $error ) {
				$this->_app->enqueueMessage ( $field . ': ' . $error, 'error' );
			}
			$this->redirectBack ();
		}

		$mes = $message->parent;

		if ($this->config->pollenabled) {
			$polltitle = JRequest::getString ( 'poll_title', 0 );
			$optionsnumbers = JRequest::getInt ( 'number_total_options', '' );
			$polltimetolive = JRequest::getString ( 'poll_time_to_live', 0 );
			$poll_optionsID = JRequest::getVar('polloptionsID', array (), 'post', 'array');
			$optvalue = array();
			foreach($poll_optionsID as $opt) {
				if ( !empty($opt) ) $optvalue[] = $opt;
			}

			//need to check if the poll exist, if it's not the case the poll is insered like new poll
			if (! $mes->poll_id) {
				if ( !empty($optvalue) ) $this->poll->save_new_poll ( $polltimetolive, $polltitle, $this->id, $optvalue );
			} else {
				if (empty ( $polltitle ) && empty($poll_optionsID)) {
					//The poll is deleted because the polltitle and the options are empty
					$this->poll->delete_poll ( $this->id );
				} else {
					$this->poll->update_poll_edit ( $polltimetolive, $this->id, $polltitle, $optionsnumbers, $poll_optionsID );
				}
			}
		}

		$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_SUCCESS_EDIT' ) );
		if ($this->msg_cat->review && !CKunenaTools::isModerator($this->my->id,$this->catid)) {
			$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_GEN_MODERATED' ) );
		}
		$this->_app->redirect ( CKunenaLink::GetMessageURL ( $this->id, $this->catid, 0, false ) );
	}

	protected function newtopic($do) {
		$this->category = KunenaForumCategoryHelper::get($this->catid);
		if (!$this->category->authorise('topic.create')) {
			$this->_app->enqueueMessage ( $this->category->getError(), 'notice' );
			return false;
		}
		list ($this->topic, $this->message) = $this->category->newTopic();
		$this->title = JText::_ ( 'COM_KUNENA_POST_NEW_TOPIC' );
		$this->action = 'post';

		$options = array ();
		$this->selectcatlist = CKunenaTools::KSelectList ( 'catid', $options, '', false, 'postcatid', $this->category->id );

		CKunenaTools::loadTemplate ( '/editor/form.php' );
	}

	protected function reply($do) {
		$parent = KunenaForumMessageHelper::get($this->id);
		if (!$parent->authorise('reply')) {
			$this->_app->enqueueMessage ( $parent->getError(), 'notice' );
			return false;
		}
		list ($this->topic, $this->message) = $parent->newReply($do == 'quote');
		$this->category = $this->topic->getCategory();
		$this->title = JText::_ ( 'COM_KUNENA_POST_REPLY_TOPIC' ) . ' ' . $this->topic->subject;
		$this->action = 'post';

		CKunenaTools::loadTemplate ( '/editor/form.php' );
	}

	protected function edit() {
		$this->message = KunenaForumMessageHelper::get($this->id);
		if (!$this->message->authorise('edit')) {
			$this->_app->enqueueMessage ( $this->message->getError(), 'notice' );
			return false;
		}
		$this->topic = $this->message->getTopic();
		$this->category = $this->topic->getCategory();
		$this->title = JText::_ ( 'COM_KUNENA_POST_EDIT' ) . ' ' . $this->topic->subject;
		$this->action = 'edit';

		// Load attachments
		require_once(KUNENA_PATH_LIB.DS.'kunena.attachments.class.php');
		$attachments = CKunenaAttachments::getInstance ();
		$this->attachments = array_pop($attachments->get($this->message->id));

		//save the options for query after and load the text options, the number options is for create the fields in the form after
		if ($this->topic->poll_id) {
			$this->polldatasedit = $this->poll->get_poll_data ( $this->topic->id );
			$this->polloptionstotal = count ( $this->polldatasedit );
		}

		CKunenaTools::loadTemplate ( '/editor/form.php' );
	}

	protected function moderate($modtopic = false) {
		if ($modtopic) {
			$this->topic = KunenaForumTopicHelper::get($this->id);
			if (!$this->topic->authorise('move')) {
				$this->_app->enqueueMessage ( $this->topic->getError(), 'notice' );
			}
		} else {
			$this->message = KunenaForumMessageHelper::get($this->id);
			if (!$this->message->authorise('move')) {
				$this->_app->enqueueMessage ( $this->message->getError(), 'notice' );
			}
			$this->topic = $this->message->getTopic();
		}
		$this->category = $this->topic->getCategory();

		$options =array ();
		if ($modtopic) {
			$options [] = JHTML::_ ( 'select.option', 0, JText::_ ( 'COM_KUNENA_MODERATION_MOVE_TOPIC' ) );
		} else {
			$options [] = JHTML::_ ( 'select.option', 0, JText::_ ( 'COM_KUNENA_MODERATION_CREATE_TOPIC' ) );
		}
		$options [] = JHTML::_ ( 'select.option', -1, JText::_ ( 'COM_KUNENA_MODERATION_ENTER_TOPIC' ) );
		$params = array(
			'orderby'=>'tt.last_post_time DESC',
			'where'=>" AND tt.id != {$this->_db->Quote($this->topic->id)} ");
		list ($total, $topics) = KunenaForumTopicHelper::getLatestTopics($this->catid, 0, 30, $params);
		foreach ( $topics as $cur ) {
			$options [] = JHTML::_ ( 'select.option', $cur->id, kunena_htmlspecialchars ( $cur->subject ) );
		}
		$this->messagelist = JHTML::_ ( 'select.genericlist', $options, 'targettopic', 'class="inputbox"', 'value', 'text', 0, 'kmod_targettopic' );

		$options=array();
		$this->categorylist = CKunenaTools::KSelectList ( 'targetcat', $options, 'class="inputbox kmove_selectbox"', false, 'kmod_categories', $this->catid );
		if (isset($this->message)) $this->user = KunenaFactory::getUser($this->message->userid);

		// Get thread and reply count from current message:
		$query = "SELECT t.id,t.subject,COUNT(mm.id) AS replies FROM #__kunena_messages AS m
			INNER JOIN #__kunena_messages AS t ON m.thread=t.id
			LEFT JOIN #__kunena_messages AS mm ON mm.thread=m.thread AND mm.id > m.id
			WHERE m.id={$this->_db->Quote($this->id)}
			GROUP BY m.thread";
		$this->_db->setQuery ( $query, 0, 1 );
		$this->threadmsg = $this->_db->loadObject ();
		if (KunenaError::checkDatabaseError()) return;

		CKunenaTools::loadTemplate ( '/moderate/moderate.php' );
	}

	function canSubscribe() {
		if (!$this->my->id || !$this->config->allowsubscriptions)
			return false;
		$usertopic = $this->topic->getUserTopic();
		return !$usertopic->subscribed;
	}

	protected function delete() {
		if ($this->tokenProtection ('get'))
			return false;

		$message = KunenaForumMessageHelper::get($this->id);
		if ($message->authorise('delete') && $message->publish(KunenaForum::DELETED)) {
			$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_SUCCESS_DELETE' ) );
		} else {
			$this->_app->enqueueMessage ( $message->getError(), 'notice' );
		}
		$this->_app->redirect ( CKunenaLink::GetMessageURL ( $this->id, $this->catid, 0, false ) );
	}

	protected function undelete() {
		if ($this->tokenProtection ('get'))
			return false;

		$message = KunenaForumMessageHelper::get($this->id);
		if ($message->authorise('undelete') && $message->publish(KunenaForum::PUBLISHED)) {
			$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_SUCCESS_UNDELETE' ) );
		} else {
			$this->_app->enqueueMessage ( $message->getError(), 'notice' );
		}
		$this->_app->redirect ( CKunenaLink::GetMessageURL ( $this->id, $this->catid, 0, false ) );
	}

	protected function permdelete() {
		if ($this->tokenProtection ('get'))
			return false;

		$message = KunenaForumMessageHelper::get($this->id);
		if ($message->authorise('permdelete') && $message->delete()) {
			$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_SUCCESS_DELETE' ) );
		} else {
			$this->_app->enqueueMessage ( $message->getError(), 'notice' );
		}
		if ($this->parent)
			$this->redirectBack ();
		else
			$this->_app->redirect ( CKunenaLink::GetCategoryURL ( 'showcat', $this->catid, false ));
	}

	protected function deletethread() {
		if ($this->tokenProtection ('get'))
			return false;

		$topic = KunenaForumTopicHelper::get($this->id);
		if ($topic->authorise('delete') && $topic->publish(KunenaForum::DELETED)) {
			$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_TOPIC_SUCCESS_DELETE' ) );
		} else {
			$this->_app->enqueueMessage ( $topic->getError(), 'notice' );
		}
		$this->_app->redirect ( CKunenaLink::GetCategoryURL ( 'showcat', $this->catid, false ) );
	}

	protected function domoderate() {
		if (!$this->load())
			return false;
		if ($this->tokenProtection ())
			return false;
		if ($this->moderatorProtection ())
			return false;
		if ($this->isUserBanned() )
			return false;
		if ($this->isIPBanned())
			return false;

		require_once (KUNENA_PATH_LIB . '/kunena.moderation.class.php');

		$mode = JRequest::getVar ( 'mode', KN_MOVE_MESSAGE );
		$targetSubject = JRequest::getString ( 'subject', '' );
		$targetCat = JRequest::getInt ( 'targetcat', 0 );
		$targetId = JRequest::getInt ( 'targetid', 0 );
		if (!$targetId) $targetId = JRequest::getInt ( 'targettopic', 0 );
		$shadow = JRequest::getInt ( 'shadow', 0 );

		$moderation = CKunenaModeration::getInstance ();
		$success = $moderation->move($this->id, $targetCat, $targetSubject, $targetId, $mode, $shadow);
		if (! $success) {
			$this->_app->enqueueMessage( $moderation->getErrorMessage () );
		} else {
			$this->_app->enqueueMessage( JText::_ ( 'COM_KUNENA_POST_SUCCESS_MOVE' ));
		}
		$this->_app->redirect ( CKunenaLink::GetMessageURL ( $this->id, $this->catid, 0, false ) );
	}

	protected function subscribe() {
		if ($this->tokenProtection ('get'))
			return false;

		$topic = KunenaForumTopicHelper::get($this->id);
		if ($topic->authorise('read') && $topic->subscribe(1)) {
			$success_msg = JText::_ ( 'COM_KUNENA_POST_SUBSCRIBED_TOPIC' );
		} else {
			$success_msg = JText::_ ( 'COM_KUNENA_POST_NO_SUBSCRIBED_TOPIC' );
		}
		$this->_app->redirect ( CKunenaLink::GetLatestPageAutoRedirectURL ( $this->id, $this->config->messages_per_page ), $success_msg );
	}

	protected function unsubscribe() {
		if ($this->tokenProtection ('get'))
			return false;

		$topic = KunenaForumTopicHelper::get($this->id);
		if ($topic->authorise('read') && $topic->subscribe(0)) {
			$success_msg = JText::_ ( 'COM_KUNENA_POST_UNSUBSCRIBED_TOPIC' );
		} else {
			$success_msg = JText::_ ( 'COM_KUNENA_POST_NO_UNSUBSCRIBED_TOPIC' );
		}
		$this->_app->redirect ( CKunenaLink::GetLatestPageAutoRedirectURL ( $this->id, $this->config->messages_per_page ), $success_msg );
	}

	protected function favorite() {
		if ($this->tokenProtection ('get'))
			return false;

		$topic = KunenaForumTopicHelper::get($this->id);
		if ($topic->authorise('read') && $topic->favorite(1)) {
			$success_msg = JText::_ ( 'COM_KUNENA_POST_FAVORITED_TOPIC' );
		} else {
			$success_msg = JText::_ ( 'COM_KUNENA_POST_NO_FAVORITED_TOPIC' );
		}
		$this->_app->redirect ( CKunenaLink::GetLatestPageAutoRedirectURL ( $this->id, $this->config->messages_per_page ), $success_msg );
	}

	protected function unfavorite() {
		if ($this->tokenProtection ('get'))
			return false;

		$topic = KunenaForumTopicHelper::get($this->id);
		if ($topic->authorise('read') && $topic->favorite(0)) {
			$success_msg = JText::_ ( 'COM_KUNENA_POST_UNFAVORITED_TOPIC' );
		} else {
			$success_msg = JText::_ ( 'COM_KUNENA_POST_NO_UNFAVORITED_TOPIC' );
		}
		$this->_app->redirect ( CKunenaLink::GetLatestPageAutoRedirectURL ( $this->id, $this->config->messages_per_page ), $success_msg );
	}

	protected function sticky() {
		if ($this->tokenProtection ('get'))
			return false;

		$topic = KunenaForumTopicHelper::get($this->id);
		if (!$topic->authorise('sticky')) {
			$this->_app->enqueueMessage ( $topic->getError(), 'notice' );
		} elseif ($topic->sticky(1)) {
			$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_STICKY_SET' ) );
		} else {
			$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_STICKY_NOT_SET' ) );
		}
		$this->_app->redirect ( CKunenaLink::GetLatestPageAutoRedirectURL ( $this->id, $this->config->messages_per_page ) );
	}

	protected function unsticky() {
		if ($this->tokenProtection ('get'))
			return false;

		$topic = KunenaForumTopicHelper::get($this->id);
		if (!$topic->authorise('sticky')) {
			$this->_app->enqueueMessage ( $topic->getError(), 'notice' );
		} elseif ($topic->sticky(0)) {
			$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_STICKY_UNSET' ) );
		} else {
			$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_STICKY_NOT_UNSET' ) );
		}
		$this->_app->redirect ( CKunenaLink::GetLatestPageAutoRedirectURL ( $this->id, $this->config->messages_per_page ) );
	}

	protected function lock() {
		if ($this->tokenProtection ('get'))
			return false;

		$topic = KunenaForumTopicHelper::get($this->id);
		if (!$topic->authorise('lock')) {
			$this->_app->enqueueMessage ( $topic->getError(), 'notice' );
		} elseif ($topic->lock(1)) {
			$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_LOCK_SET' ) );
		} else {
			$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_LOCK_NOT_SET' ) );
		}
		$this->_app->redirect ( CKunenaLink::GetLatestPageAutoRedirectURL ( $this->id, $this->config->messages_per_page ) );
	}

	protected function unlock() {
		if ($this->tokenProtection ('get'))
			return false;

		$topic = KunenaForumTopicHelper::get($this->id);
		if (!$topic->authorise('lock')) {
			$this->_app->enqueueMessage ( $topic->getError(), 'notice' );
		} elseif ($topic->lock(0)) {
			$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_LOCK_UNSET' ) );
		} else {
			$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_LOCK_NOT_UNSET' ) );
		}
		$this->_app->redirect ( CKunenaLink::GetLatestPageAutoRedirectURL ( $this->id, $this->config->messages_per_page ) );
	}

	protected function approve() {
		if ($this->tokenProtection ('get'))
			return false;
		if (!$this->load())
			return false;
		if ($this->moderatorProtection ())
			return false;
		if ($this->isUserBanned() )
			return false;
		if ($this->isIPBanned())
			return false;

		$message = KunenaForumMessageHelper::get($this->id);
		if ($message->authorise('approve')) {
			$success_msg = JText::_ ( 'COM_KUNENA_MODERATE_1APPROVE_FAIL' );
			$this->_db->setQuery ( "UPDATE #__kunena_messages SET hold=0 WHERE id={$this->_db->Quote($this->id)}" );
			if ($this->id && $this->_db->query () && $this->_db->getAffectedRows () == 1) {
				$success_msg = JText::_ ( 'COM_KUNENA_MODERATE_APPROVE_SUCCESS' );
				$this->_db->setQuery ( "SELECT COUNT(*) FROM #__kunena_messages WHERE thread={$this->_db->Quote($this->msg_cat->thread)}" );
				$limitstart = $this->_db->loadResult ();
				KunenaError::checkDatabaseError();
				//construct a useable URL (for plaintext - so no &amp; encoding!)
				jimport ( 'joomla.environment.uri' );
				$uri = & JURI::getInstance ( JURI::base () );
				$LastPostUrl = $uri->toString ( array ('scheme', 'host', 'port' ) ) . str_replace ( '&amp;', '&', CKunenaLink::GetThreadPageURL ( 'view', $this->catid, $this->msg_cat->thread, $limitstart, $this->config->messages_per_page, $this->id ) );

				// Update category stats
				$category = KunenaForumCategoryHelper::get($this->msg_cat->catid);
				if (!$this->msg_cat->parent) $category->numTopics++;
				$category->numPosts++;
				$category->last_topic_id = $this->msg_cat->thread;
				$category->last_topic_subject = $this->msg_cat->subject;
				$category->last_post_id = $this->msg_cat->id;
				$category->last_post_time = $this->msg_cat->time;
				$category->last_post_userid = $this->msg_cat->userid;
				$category->last_post_message = $this->msg_cat->message;
				$category->last_post_guest_name = $this->msg_cat->name;
				$category->save();

				$message->emailToSubscribers($LastPostUrl, $this->config->allowsubscriptions, $this->config->mailmod, $this->config->mailadmin);
			}
		}
		$this->_app->redirect ( CKunenaLink::GetMessageURL ( $this->id, $this->catid, 0, false ), $success_msg );
	}

	function hasThreadHistory() {
		if (! $this->config->showhistory || $this->id == 0)
			return false;
		return true;
	}

	function displayThreadHistory() {
		if (! $this->config->showhistory || $this->id == 0)
			return;

		//get all the messages for this thread
		$query = "SELECT m.*, t.* FROM #__kunena_messages AS m
			LEFT JOIN #__kunena_messages_text AS t ON m.id=t.mesid
			WHERE thread='{$this->message->thread}' AND hold='0'
			ORDER BY time DESC";
		$this->_db->setQuery ( $query, 0, $this->config->historylimit );
		$this->messages = $this->_db->loadObjectList ();
		if (KunenaError::checkDatabaseError()) return;

		$this->replycount = count($this->messages);

		//get attachments
		$mesids = array();
		foreach ($this->messages as $mes) {
			$mesids[]=$mes->id;
		}
		$mesids = implode(',', $mesids);
		require_once(KUNENA_PATH_LIB.DS.'kunena.attachments.class.php');
		$attachments = CKunenaAttachments::getInstance ();
		$this->attachmentslist = $attachments->get($mesids);

		CKunenaTools::loadTemplate ( '/editor/history.php' );
	}

	public function getNumLink($mesid ,$replycnt) {
		if ($this->config->ordering_system == 'replyid') {
			$this->numLink = CKunenaLink::GetSamePageAnkerLink( $mesid, '#' .$replycnt );
		} else {
			$this->numLink = CKunenaLink::GetSamePageAnkerLink ( $mesid, '#' . $mesid );
		}

		return $this->numLink;
	}

	protected function getAuthorName() {
		if (! $this->my->id) {
			$name = '';
		} else {
			$name = $this->config->username ? $this->my->username : $this->my->name;
		}
		return $name;
	}

	protected function moderatorProtection() {
		if (! CKunenaTools::isModerator ( $this->my->id, $this->catid )) {
			$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_NOT_MODERATOR' ), 'notice' );
			return true;
		}
		return false;
	}

	protected function tokenProtection($method='post') {
		// get the token put in the message form to check that the form has been valided successfully
		if (JRequest::checkToken ($method) == false) {
			$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			return true;
		}
		return false;
	}

	protected function lockProtection() {
		if ($this->msg_cat && $this->msg_cat->locked && ! CKunenaTools::isModerator ( $this->my->id, $this->catid )) {
			if ($this->msg_cat->catlocked)
				$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_ERROR_CATEGORY_LOCKED' ), 'error' );
			else
				$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_ERROR_TOPIC_LOCKED' ), 'error' );
			return true;
		}
		return false;
	}

	protected function isUserBanned() {
		$profile = KunenaFactory::getUser();
		$banned = $profile->isBanned();
		if ($banned) {
			kimport('kunena.user.ban');
			$banned = KunenaUserBan::getInstanceByUserid($profile->userid, true);
			if (!$banned->isLifetime()) {
				require_once(KPATH_SITE.'/lib/kunena.timeformat.class.php');
				$this->_app->enqueueMessage ( JText::sprintf ( 'COM_KUNENA_POST_ERROR_USER_BANNED_NOACCESS_EXPIRY', CKunenaTimeformat::showDate($banned->expiration)), 'error' );
				$this->redirectBack();
				return true;
			} else {
				$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_POST_ERROR_USER_BANNED_NOACCESS' ), 'error' );
				$this->redirectBack();
				return true;
			}
		}
		return false;
	}

	protected function floodProtection() {
		// Flood protection
		$ip = $_SERVER ["REMOTE_ADDR"];

		if ($this->config->floodprotection && ! CKunenaTools::isModerator ( $this->my->id, $this->catid )) {
			$this->_db->setQuery ( "SELECT MAX(time) FROM #__kunena_messages WHERE ip={$this->_db->Quote($ip)}" );
			$lastPostTime = $this->_db->loadResult ();
			if (KunenaError::checkDatabaseError()) return false;

			if ($lastPostTime + $this->config->floodprotection > CKunenaTimeformat::internalTime ()) {
				echo JText::_ ( 'COM_KUNENA_POST_TOPIC_FLOOD1' ) . ' ' . $this->config->floodprotection . ' ' . JText::_ ( 'COM_KUNENA_POST_TOPIC_FLOOD2' ) . '<br />';
				echo JText::_ ( 'COM_KUNENA_POST_TOPIC_FLOOD3' );
				return true;
			}
		}
		return false;
	}

	function displayAttachments($attachments) {
		$this->attachments = $attachments;
		CKunenaTools::loadTemplate('/view/message.attachments.php');
	}

	function display() {
		if (! $this->allow)
			return;
		if ($this->action == "post") {
			$this->post ();
			return;
		} else if ($this->action == "cancel") {
			$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_SUBMIT_CANCEL' ) );
			return;
		}

		switch ($this->do) {
			case 'new' :
				$this->newtopic ( $this->do );
				break;

			case 'reply' :
			case 'quote' :
				$this->reply ( $this->do );
				break;

			case 'edit' :
				$this->edit ();
				break;

			case 'editpostnow' :
				$this->editpostnow ();
				break;

			case 'delete' :
				$this->delete ();
				break;

			case 'undelete' :
				$this->undelete ();
				break;

			case 'deletethread' :
				$this->deletethread ();
				break;

			case 'moderate' :
				$this->moderate (false);
				break;

			case 'moderatethread' :
				$this->moderate (true);
				break;

			case 'domoderate' :
				$this->domoderate ();
				break;

			case 'permdelete' :
				$this->permdelete();
				break;

			case 'subscribe' :
				$this->subscribe ();
				break;

			case 'unsubscribe' :
				$this->unsubscribe ();
				break;

			case 'favorite' :
				$this->favorite ();
				break;

			case 'unfavorite' :
				$this->unfavorite ();
				break;

			case 'sticky' :
				$this->sticky ();
				break;

			case 'unsticky' :
				$this->unsticky ();
				break;

			case 'lock' :
				$this->lock ();
				break;

			case 'unlock' :
				$this->unlock ();
				break;

			case 'approve' :
				$this->approve ();
				break;
		}
	}

	function setTitle($title) {
		$this->document->setTitle ( $title . ' - ' . $this->config->board_title );
	}

	function hasCaptcha() {
		if ($this->config->captcha == 1 && $this->my->id < 1)
			return true;
		return false;
	}

	function displayCaptcha() {
		if (! $this->hasCaptcha ())
			return;

		$dispatcher = &JDispatcher::getInstance();
        $results = $dispatcher->trigger( 'onCaptchaRequired', array( 'kunena.post' ) );

		if (! JPluginHelper::isEnabled ( 'system', 'captcha' ) || !$results[0] ) {
			echo JText::_ ( 'COM_KUNENA_CAPTCHA_NOT_CONFIGURED' );
			return;
		}

        if ($results[0]) {
        	$dispatcher->trigger( 'onCaptchaView', array( 'kunena.post', 0, '', '<br />' ) );
        }
	}

	/**
	* Escapes a value for output in a view script.
	*
	* If escaping mechanism is one of htmlspecialchars or htmlentities, uses
	* {@link $_encoding} setting.
	*
	* @param  mixed $var The output to escape.
	* @return mixed The escaped value.
	*/
	function escape($var)
	{
		return htmlspecialchars($var, ENT_COMPAT, 'UTF-8');
	}

	function verifyCaptcha() {
		if (! $this->hasCaptcha ())
			return;

		$dispatcher     = &JDispatcher::getInstance();
        $results = $dispatcher->trigger( 'onCaptchaRequired', array( 'kunena.post' ) );

		if (! JPluginHelper::isEnabled ( 'system', 'captcha' ) || !$results[0]) {
			$this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_CAPTCHA_CANNOT_CHECK_CODE' ), 'error' );
			$this->redirectBack ();
		}

        if ( $results[0] ) {
        	$captchaparams = array( JRequest::getVar( 'captchacode', '', 'post' )
                        , JRequest::getVar( 'captchasuffix', '', 'post' )
                        , JRequest::getVar( 'captchasessionid', '', 'post' ));
        	$results = $dispatcher->trigger( 'onCaptchaVerify', $captchaparams );
            if ( ! $results[0] ) {
                $this->_app->enqueueMessage ( JText::_ ( 'COM_KUNENA_CAPTCHACODE_DO_NOT_MATCH' ), 'error' );
				$this->redirectBack ();
                return false;
           }
      }
	}

	function redirectBack() {
		$httpReferer = JRequest::getVar ( 'HTTP_REFERER', JURI::base ( true ), 'server' );
		$this->_app->redirect ( $httpReferer );
	}
}
