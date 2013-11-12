<?php
IncludeModuleLangFile(__FILE__);

class CBitrixCloudMobile
{
	/**
	 * Builds menu
	 *
	 * @return void
	 *
	 * RegisterModuleDependences(
	 * 	"mobileapp",
	 * 	"OnBeforeAdminMobileMenuBuild",
	 * 	"bitrixcloud",
	 * 	"CBitrixCloudMobile",
	 * 	"OnBeforeAdminMobileMenuBuild"
	 * );
	 */
	static public function OnBeforeAdminMobileMenuBuild()
	{
		$arMenu = array(
			array(
				"text" => GetMessage("BCL_MON_MOB_INSPECTOR"),
				"type" => "section",
				"items" => array(
					array(
						"text" => GetMessage("BCL_MON_MOB_MENU_IPAGE"),
						"data-url" => "/bitrix/admin/mobile/bitrixcloud_monitoring_ipage.php",
						"data-pageid" => "bitrix_cloud_monitoring_info",
						"push-param" => "bc"
					),
					array(
						"text" => GetMessage("BCL_MON_MOB_MENU_PUSH"),
						"data-url" => "/bitrix/admin/mobile/bitrixcloud_monitoring_push.php",
						"data-pageid" => "bitrix_cloud_monitoring_push",
					),

				),
			),
		);

		$startSortMenuPosition = 300;

		foreach ($arMenu as $key => $item)
		{
			$item["sort"] = $key+$startSortMenuPosition;
			CAdminMobileMenu::addItem($item);
		}

		return true;
	}

	static public function getUserDevices($userId)
	{
		$arResult = array();

		if(CModule::IncludeModule("pull"))
		{
			$dbres = CPullPush::GetList(Array(), Array("USER_ID" => $userId));
			while($arDb = $dbres->Fetch())
			{
				if($arDb["DEVICE_TYPE"] == "APPLE")
				{
					CModule::IncludeModule("mobileapp");
					CMobile::Init();

/*					if(CMobile::$isDev)
						$protocol = 1;
					else */
						$protocol = 2;
				}
				else
					$protocol = 3;

				$arResult[] = $arDb["DEVICE_TOKEN"].":".$protocol.":BitrixAdmin";
			}
		}

		return $arResult;
	}
}