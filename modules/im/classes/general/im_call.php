<?
IncludeModuleLangFile(__FILE__);

class CIMCall
{
	public static function Invite($arParams)
	{
		$arConfig['CHAT_ID'] = intval($arParams['CHAT_ID']);
		if ($arConfig['CHAT_ID'] <= 0)
			return false;

		global $DB, $USER;

		$arConfig['RECIPIENT_ID'] = intval($arParams['RECIPIENT_ID']);
		$arConfig['USER_ID'] = intval($arParams['USER_ID']) > 0? intval($arParams['USER_ID']): IntVal($USER->GetID());
		$arConfig['VIDEO'] = isset($arParams['VIDEO']) && $arParams['VIDEO'] == 'N'? 'N': 'Y';

		$arChat = CIMChat::GetChatData(Array('ID' => $arConfig['CHAT_ID']));
		if (empty($arChat['chat']))
			return false;

		$arConfig['CALL_TO_GROUP'] = $arChat['chat'][$arConfig['CHAT_ID']]['type'] == IM_MESSAGE_GROUP;
		$arConfig['STATUS_TYPE'] = intval($arChat['chat'][$arConfig['CHAT_ID']]['call']);

		if (!$arConfig['CALL_TO_GROUP'] && !IsModuleInstalled('intranet') && CIMSettings::GetPrivacy(CIMSettings::PRIVACY_CALL, $arConfig['RECIPIENT_ID']) == CIMSettings::PRIVACY_RESULT_CONTACT
			&& CModule::IncludeModule('socialnetwork') && CSocNetUser::IsFriendsAllowed() && !CSocNetUserRelations::IsFriends($arConfig['USER_ID'], $arConfig['RECIPIENT_ID']))
		{
			$GLOBALS["APPLICATION"]->ThrowException(GetMessage('IM_ERROR_CALL_PRIVACY'), "ERROR_FROM_PRIVACY");
			return false;
		}

		if ($arConfig['STATUS_TYPE'] != IM_CALL_NONE)
		{
			if ($arConfig['CALL_TO_GROUP'])
				self::MessageToChat($arConfig['CHAT_ID'], "IM_CALL_CHAT_START_", $arConfig['USER_ID'], true);

			$strSql = "UPDATE b_im_relation SET CALL_STATUS = ".IM_CALL_STATUS_ANSWER." WHERE CHAT_ID = ".$arConfig['CHAT_ID']." AND USER_ID = ".$arConfig['USER_ID'];
			$DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);

			$arUserData = CIMContactList::GetUserData(Array('ID' => $arChat['userInChat'][$arConfig['CHAT_ID']], 'DEPARTMENT' => 'N', 'HR_PHOTO' => 'Y'));
			$arConfig['USER_DATA']['USERS'] = $arUserData['users'];
			$arConfig['USER_DATA']['HR_PHOTO'] = $arUserData['hrphoto'];

			foreach ($arChat['userCallStatus'][$arConfig['CHAT_ID']] as $userId => $callStatus)
			{
				if ($userId != $arConfig['USER_ID'] && in_array($callStatus, Array(IM_CALL_STATUS_WAIT, IM_CALL_STATUS_ANSWER)))
					$arUserToConnect[$userId] = $callStatus;
			}
			$arConfig['USERS_CONNECT'] = $arUserToConnect;
			
			$arSend['users'] = $arUserData['users'];
			$arSend['hrphoto'] = $arUserData['hrphoto'];
			$arSend['video'] = $arConfig['VIDEO'] == 'Y'? true: false;
			$arSend['callToGroup'] = $arConfig['CALL_TO_GROUP'];
			$arSend['chat'] = $arChat['chat'];
			$arSend['userInChat'] = $arChat['userInChat'];
			foreach ($arChat['userCallStatus'][$arConfig['CHAT_ID']] as $userId => $callStatus)
				if ($userId != $arConfig['USER_ID'] && !in_array($callStatus, Array(IM_CALL_STATUS_DECLINE)))
					self::Command($arConfig['CHAT_ID'], $userId, 'invite_join', $arSend);
		}
		else
		{
			if ($arConfig['CALL_TO_GROUP'])
				self::MessageToChat($arConfig['CHAT_ID'], "IM_CALL_CHAT_INIT_", $arConfig['USER_ID'], true);

			$strSql = "UPDATE b_im_chat SET CALL_TYPE = ".($arConfig['VIDEO'] == 'Y'? IM_CALL_VIDEO: IM_CALL_AUDIO)." WHERE ID = ".$arConfig['CHAT_ID'];
			$DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);

			$strSql = "UPDATE b_im_relation SET CALL_STATUS = ".IM_CALL_STATUS_ANSWER." WHERE CHAT_ID = ".$arConfig['CHAT_ID']." AND USER_ID = ".$arConfig['USER_ID'];
			$DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);

			$arUserToConnect = Array();
			foreach ($arChat['userCallStatus'][$arConfig['CHAT_ID']] as $userId => $callStatus)
			{
				if ($userId != $arConfig['USER_ID'])
					$arUserToConnect[$userId] = $callStatus;
			}

			$arUserData = CIMContactList::GetUserData(Array('ID' => $arChat['userInChat'][$arConfig['CHAT_ID']], 'DEPARTMENT' => 'N', 'HR_PHOTO' => 'Y'));
			$arSend['users'] = $arUserData['users'];
			$arSend['hrphoto'] = $arUserData['hrphoto'];
			$arSend['video'] = $arConfig['VIDEO'] == 'Y'? true: false;
			$arSend['callToGroup'] = $arConfig['CALL_TO_GROUP'];
			$arSend['chat'] = $arChat['chat'];
			$arSend['userInChat'] = $arChat['userInChat'];
			foreach ($arUserToConnect as $userId => $callStatus)
				self::Command($arConfig['CHAT_ID'], $userId, 'invite', $arSend);

			$arConfig['USER_DATA']['USERS'] = $arUserData['users'];
			$arConfig['USER_DATA']['HR_PHOTO'] = $arUserData['hrphoto'];
		}
		foreach(GetModuleEvents("im", "OnCallStart", true) as $arEvent)
			ExecuteModuleEventEx($arEvent, array($arConfig));

		return $arConfig;
	}

	public static function AddUser($arParams)
	{
		$arConfig['CHAT_ID'] = intval($arParams['CHAT_ID']);
		if ($arConfig['CHAT_ID'] <= 0)
			return false;

		$arConfig['USERS'] = Array();
		if (is_array($arParams['USERS']))
		{
			foreach ($arParams['USERS'] as $value)
				$arConfig['USERS'][] = intval($value);
		}
		else
		{
			$arConfig['USERS'][] = intval($arParams['USERS']);
		}
		if (empty($arConfig['USERS']))
			return false;

		global $DB;

		$arChat = CIMChat::GetChatData(Array('ID' => $arConfig['CHAT_ID']));
		if (empty($arChat['chat']))
			return false;

		$arConfig['CALL_TYPE'] = intval($arChat['chat'][$arConfig['CHAT_ID']]['call']);
		$arConfig['LAST_CHAT_ID'] = $arConfig['CHAT_ID'];
		if ($arChat['chat'][$arConfig['CHAT_ID']]['type'] == IM_MESSAGE_PRIVATE)
		{
			$strSql = "UPDATE b_im_chat SET CALL_TYPE = ".IM_CALL_NONE." WHERE ID = ".$arConfig['CHAT_ID'];
			$DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);

			$strSql = "UPDATE b_im_relation SET CALL_STATUS = ".IM_CALL_STATUS_NONE." WHERE CHAT_ID = ".$arConfig['CHAT_ID'];
			$DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);

			$arUserToConnect = Array();
			$arUser = Array();

			foreach ($arChat['userCallStatus'][$arConfig['CHAT_ID']] as $userId => $callStatus)
			{
				$arUser[] = $userId;
				$arUserToConnect[$userId] = $callStatus;
			}

			$arUser = array_merge($arUser, $arConfig['USERS']);

			$CIMChat = new CIMChat();
			$chatId = $CIMChat->Add('', $arUser);
			if (!$chatId)
				return false;

			$arConfig['CHAT_ID'] = $chatId;

			$strSql = "UPDATE b_im_chat SET CALL_TYPE = ".$arConfig['CALL_TYPE']." WHERE ID = ".$arConfig['CHAT_ID'];
			$DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);

			$strSql = "UPDATE b_im_relation SET CALL_STATUS = ".IM_CALL_STATUS_WAIT." WHERE CHAT_ID = ".$arConfig['CHAT_ID'];
			$DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);

			foreach ($arUserToConnect as $userId => $callStatus)
			{
				$strSql = "UPDATE b_im_relation SET CALL_STATUS = ".$callStatus." WHERE CHAT_ID = ".$arConfig['CHAT_ID']." AND USER_ID = ".$userId;
				$DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);
			}
		}
		else if ($arChat['chat'][$arConfig['CHAT_ID']]['type'] == IM_MESSAGE_GROUP)
		{
			$CIMChat = new CIMChat();
			$result = $CIMChat->AddUser($arConfig['CHAT_ID'], $arConfig['USERS']);
			if (!$result)
				return false;

			$strSql = "UPDATE b_im_relation SET CALL_STATUS = ".IM_CALL_STATUS_WAIT." WHERE CHAT_ID = ".$arConfig['CHAT_ID']." AND USER_ID IN (".implode(',', $arConfig['USERS']).")";
			$DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);

			$arUserToConnect = Array();
			$arUser = Array();
			foreach ($arChat['userCallStatus'][$arConfig['CHAT_ID']] as $userId => $callStatus)
			{
				$arUser[] = $userId;
				$arUserToConnect[$userId] = $callStatus;
			}
			foreach ($arConfig['USERS'] as $userId)
			{
				$arUserToConnect[$userId] = IM_CALL_STATUS_WAIT;
			}
		}

		$arUserData = CIMContactList::GetUserData(Array('ID' => $arUser, 'DEPARTMENT' => 'N', 'HR_PHOTO' => 'Y'));
		$arConfig['USER_DATA']['USERS'] = $arUserData['users'];
		$arConfig['USER_DATA']['HR_PHOTO'] = $arUserData['hrphoto'];

		$arSend = Array();
		$arSend['users'] = $arUserData['users'];
		$arSend['hrphoto'] = $arUserData['hrphoto'];
		$arSend['lastChatId'] = $arConfig['LAST_CHAT_ID'];
		foreach ($arUserToConnect as $userId => $callStatus)
			self::Command($arConfig['CHAT_ID'], $userId, 'invite_user', $arSend);

		$arSend['video'] = $arConfig['CALL_TYPE'] == IM_CALL_VIDEO? true: false;
		$arSend['callToGroup'] = true;
		foreach ($arConfig['USERS'] as $userId)
			self::Command($arConfig['CHAT_ID'], $userId, 'join', $arSend);

		return $arConfig;
	}

	public static function Answer($arParams)
	{
		$arConfig['CHAT_ID'] = intval($arParams['CHAT_ID']);
		if ($arConfig['CHAT_ID'] <= 0)
			return false;

		global $DB, $USER;
		$arConfig['USER_ID'] = intval($arParams['USER_ID']) > 0? intval($arParams['USER_ID']): IntVal($USER->GetID());

		$strSql = "UPDATE b_im_relation SET CALL_STATUS = ".IM_CALL_STATUS_ANSWER." WHERE CHAT_ID = ".$arConfig['CHAT_ID']." AND USER_ID = ".$arConfig['USER_ID'];
		$DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);

		if ($arParams['CALL_TO_GROUP'])
			self::MessageToChat($arConfig['CHAT_ID'], "IM_CALL_CHAT_START_", $arConfig['USER_ID'], true);

		CIMCall::Signaling(Array(
			'CHAT_ID' => $arConfig['CHAT_ID'],
			'USER_ID' => $arConfig['USER_ID'],
			'COMMAND' => 'answer',
		));
		self::Command($arConfig['CHAT_ID'], $arConfig['USER_ID'], 'answer_self', Array());

		return true;
	}

	public static function Wait($arParams)
	{
		$arConfig['CHAT_ID'] = intval($arParams['CHAT_ID']);
		if ($arConfig['CHAT_ID'] <= 0)
			return false;

		global $DB, $USER;
		$arConfig['USER_ID'] = intval($arParams['USER_ID']) > 0? intval($arParams['USER_ID']): IntVal($USER->GetID());

		$strSql = "UPDATE b_im_relation SET CALL_STATUS = ".IM_CALL_STATUS_WAIT." WHERE CHAT_ID = ".$arConfig['CHAT_ID']." AND USER_ID = ".$arConfig['USER_ID'];
		$DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);

		CIMCall::Signaling(Array(
			'CHAT_ID' => $arConfig['CHAT_ID'],
			'USER_ID' => $arConfig['USER_ID'],
			'COMMAND' => 'wait',
		));

		return true;
	}

	public static function Start($arParams)
	{
		$arConfig['CHAT_ID'] = intval($arParams['CHAT_ID']);
		if ($arConfig['CHAT_ID'] <= 0)
			return false;

		global $USER;
		$arConfig['USER_ID'] = intval($arParams['USER_ID']) > 0? intval($arParams['USER_ID']): IntVal($USER->GetID());

		if (!$arParams['CALL_TO_GROUP'])
			self::MessageToPrivate($arConfig['USER_ID'], $arParams['RECIPIENT_ID'], "IM_CALL_CHAT_START");

		CIMCall::Signaling(Array(
			'CHAT_ID' => $arConfig['CHAT_ID'],
			'USER_ID' => $arConfig['USER_ID'],
			'COMMAND' => 'start',
		));

		return true;
	}

	public static function End($arParams)
	{
		$arConfig['CHAT_ID'] = intval($arParams['CHAT_ID']);
		if ($arConfig['CHAT_ID'] <= 0)
			return false;

		global $DB, $USER;
		$arConfig['USER_ID'] = intval($arParams['USER_ID']) > 0? intval($arParams['USER_ID']): IntVal($USER->GetID());
		$arConfig['RECIPIENT_ID'] = intval($arParams['RECIPIENT_ID']);

		$arChat = CIMChat::GetChatData(Array('ID' => $arConfig['CHAT_ID']));
		if (empty($arChat['chat']))
			return false;

		$arUserToConnect = Array();
		$acceptUserExists = false;
		foreach ($arChat['userCallStatus'][$arConfig['CHAT_ID']] as $userId => $callStatus)
		{
			if ($userId != $arConfig['USER_ID'] && in_array($callStatus, Array(IM_CALL_STATUS_WAIT, IM_CALL_STATUS_ANSWER)))
			{
				if ($callStatus == IM_CALL_STATUS_ANSWER)
					$acceptUserExists = true;

				$arUserToConnect[] = $userId;
			}
		}

		if (!$acceptUserExists || empty($arUserToConnect) || count($arUserToConnect) == 1)
		{
			$arConfig['CLOSE_CONNECT'] = true;

			$strSql = "UPDATE b_im_chat SET CALL_TYPE = ".IM_CALL_NONE." WHERE ID = ".$arConfig['CHAT_ID'];
			$DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);

			$strSql = "UPDATE b_im_relation SET CALL_STATUS = ".IM_CALL_STATUS_NONE." WHERE CHAT_ID = ".$arConfig['CHAT_ID'];
			$DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);
		}
		else
		{
			$arConfig['CLOSE_CONNECT'] = false;

			$strSql = "UPDATE b_im_relation SET CALL_STATUS = ".IM_CALL_STATUS_DECLINE." WHERE CHAT_ID = ".$arConfig['CHAT_ID']." AND USER_ID = ".$arConfig['USER_ID'];
			$DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);
		}

		$arConfig['CALL_TO_GROUP'] = $arChat['chat'][$arConfig['CHAT_ID']]['type'] == IM_MESSAGE_GROUP;
		if ($arParams['REASON'] == 'decline')
		{
			if ($arConfig['CALL_TO_GROUP'])
			{
				if ($arParams['ACTIVE'] == 'Y')
				{
					self::MessageToChat($arConfig['CHAT_ID'], $arConfig['CLOSE_CONNECT']? "IM_CALL_CHAT_CLOSE_": "IM_CALL_CHAT_END_", $arConfig['USER_ID'], true);
				}
				else
				{
					self::MessageToChat($arConfig['CHAT_ID'], $arConfig['CLOSE_CONNECT']? "IM_CALL_CHAT_CLOSE_": "IM_CALL_CHAT_G_DECLINE_", $arConfig['USER_ID'], true);
				}
			}
			else
			{
				if ($arParams['ACTIVE'] == 'Y')
				{
					self::MessageToPrivate($arConfig['USER_ID'], $arConfig['RECIPIENT_ID'], "IM_CALL_CHAT_END");
				}
				else
				{
					self::MessageToPrivate($arConfig['USER_ID'], $arConfig['RECIPIENT_ID'], "IM_CALL_CHAT_DECLINE_", true);
				}
			}
		}
		else if ($arParams['REASON'] == 'busy')
		{
			if ($arConfig['CALL_TO_GROUP'])
			{
				self::MessageToChat($arConfig['CHAT_ID'], "IM_CALL_CHAT_G_BUSY_", $arConfig['USER_ID'], true);
			}
			else
			{
				self::MessageToPrivate($arConfig['USER_ID'], $arConfig['RECIPIENT_ID'], "IM_CALL_CHAT_BUSY_", true);
			}
		}
		else if ($arParams['REASON'] == 'waitTimeout')
		{
			if ($arConfig['CALL_TO_GROUP'])
			{
				self::MessageToChat($arConfig['CHAT_ID'], "IM_CALL_CHAT_TIMEOUT");
			}
			else
			{
				self::MessageToPrivate($arConfig['USER_ID'], $arConfig['RECIPIENT_ID'], "IM_CALL_CHAT_WAIT", true, false);
			}
		}
		else if ($arParams['REASON'] == 'errorOffline')
		{
			if ($arConfig['CALL_TO_GROUP'])
			{
				self::MessageToChat($arConfig['CHAT_ID'], "IM_CALL_CHAT_TIMEOUT");
			}
			else
			{
				self::MessageToPrivate($arConfig['RECIPIENT_ID'], $arConfig['USER_ID'], "IM_CALL_CHAT_OFFLINE", true, false);
			}
		}
		else if ($arParams['REASON'] == 'errorAccess')
		{
			if ($arConfig['CALL_TO_GROUP'])
			{
				self::MessageToChat($arConfig['CHAT_ID'], "IM_CALL_CHAT_ERROR_", $arConfig['USER_ID'], true);
			}
			else
			{
				self::MessageToPrivate($arConfig['USER_ID'], $arConfig['RECIPIENT_ID'], "IM_CALL_CHAT_ERROR", true, false);
			}
		}

		$arSend = Array();
		$arSend['callToGroup'] = $arConfig['CALL_TO_GROUP'];
		$arSend['closeConnect'] = $arConfig['CLOSE_CONNECT'];

		if (isset($arParams['VIDEO']))
			$arSend['video'] = $arParams['VIDEO'] == 'Y'? true: false;

		foreach ($arUserToConnect as $userId)
			self::Command($arConfig['CHAT_ID'], $userId, $arParams['REASON'], $arSend);

		if ($arParams['REASON'] == 'decline')
			self::Command($arConfig['CHAT_ID'], $arConfig['USER_ID'], 'decline_self', $arSend);

		return true;
	}

	public static function Signaling($arParams)
	{
		$arConfig['CHAT_ID'] = intval($arParams['CHAT_ID']);
		if ($arConfig['CHAT_ID'] <= 0)
			return false;

		global $DB, $USER;
		$arConfig['USER_ID'] = intval($arParams['USER_ID']) > 0? intval($arParams['USER_ID']): IntVal($USER->GetID());

		$arConfig['COMMAND'] = isset($arParams['COMMAND'])? $arParams['COMMAND']: 'signaling';
		$arConfig['PARAMS'] = isset($arParams['PARAMS'])? $arParams['PARAMS']: Array();

		$arChat = CIMChat::GetChatData(Array('ID' => $arConfig['CHAT_ID']));
		if (empty($arChat['chat']))
			return false;

		foreach ($arChat['userCallStatus'][$arConfig['CHAT_ID']] as $userId => $callStatus)
		{
			if ($userId != $arConfig['USER_ID'])
				self::Command($arConfig['CHAT_ID'], $userId, $arConfig['COMMAND'], $arConfig['PARAMS']);
		}

		return true;
	}

	public static function Command($chatId, $recipientId, $command, $params = Array())
	{
		if (!CModule::IncludeModule("pull"))
			return false;

		$chatId = intval($chatId);
		$recipientId = intval($recipientId);
		if ($recipientId <= 0 || $chatId <= 0 || empty($command) || !is_array($params))
			return false;

		global $USER;
		$params['senderId'] = $USER->GetID();
		$params['chatId'] = $chatId;
		$params['command'] = $command;

		CPullStack::AddByUser($recipientId, Array(
			'module_id' => 'im',
			'command' => 'call',
			'params' => $params,
		));

		return true;
	}

	public static function MessageToChat($chatId, $messageId, $userId = 0, $getUserData = false, $addGenderToMessageId = true)
	{
		$chatId = intval($chatId);
		if ($chatId <= 0 || strlen($messageId) <= 0)
			return false;

		$userId = intval($userId);
		$message = '';

		if ($userId > 0 && $getUserData)
		{
			$arSelect = Array("ID", "LAST_NAME", "NAME", "LOGIN", "SECOND_NAME", "PERSONAL_GENDER");
			$dbUsers = CUser::GetList(($sort_by = false), ($dummy=''), array('ID' => $userId), array('FIELDS' => $arSelect));
			if ($arUser = $dbUsers->Fetch())
				$message = GetMessage($messageId.($addGenderToMessageId? ($arUser["PERSONAL_GENDER"] == 'F'? 'F': 'M'): ''), Array('#USER_NAME#' => CUser::FormatName(CSite::GetNameFormat(false), $arUser, true, false)));
		}
		else
		{
			$message = GetMessage($messageId);
		}

		CIMChat::AddMessage(Array(
			"FROM_USER_ID" => $userId,
			"TO_CHAT_ID" => $chatId,
			"MESSAGE" => $message,
			"SYSTEM" => 'Y',
		));

		return true;
	}

	public static function MessageToPrivate($fromUserId, $toUserId, $messageId, $getUserData = false, $addGenderToMessageId = true)
	{
		$fromUserId = intval($fromUserId);
		$toUserId = intval($toUserId);
		if ($fromUserId <= 0 || $toUserId <= 0)
			return false;

		$message = '';
		if ($fromUserId > 0 && $getUserData)
		{
			$arSelect = Array("ID", "LAST_NAME", "NAME", "LOGIN", "SECOND_NAME", "PERSONAL_GENDER");
			$dbUsers = CUser::GetList(($sort_by = false), ($dummy=''), array('ID' => $fromUserId), array('FIELDS' => $arSelect));
			if ($arUser = $dbUsers->Fetch())
				$message = GetMessage($messageId.($addGenderToMessageId? ($arUser["PERSONAL_GENDER"] == 'F'? 'F': 'M'): ''), Array('#USER_NAME#' => CUser::FormatName(CSite::GetNameFormat(false), $arUser, true, false)));
		}
		else
		{
			$message = GetMessage($messageId);
		}

		CIMMessage::Add(Array(
			"FROM_USER_ID" => $fromUserId,
			"TO_USER_ID" =>  $toUserId,
			"MESSAGE" => $message,
			"SYSTEM" => 'Y',
			"PUSH" => 'N',
		));

		return true;
	}
}
?>