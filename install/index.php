<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class claramente_webp extends CModule
{
    public $MODULE_ID = "claramente.webp";
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $MODULE_GROUP_RIGHTS = "N";

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . "/version.php";

        $this->MODULE_VERSION = $arModuleVersion["VERSION"];
        $this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];

        $this->MODULE_NAME = Loc::getMessage("CLARAMENTE_WEBP_MODULE_NAME");
        $this->MODULE_DESCRIPTION = Loc::getMessage("CLARAMENTE_WEBP_MODULE_DESCRIPTION");

        $this->PARTNER_NAME = "Claramente";
        $this->PARTNER_URI = 'https://claramente.ru';
    }

    public function DoInstall()
    {

        RegisterModule($this->MODULE_ID);
        $this->InstallFiles();
    }

    public function DoUninstall()
    {
        UnRegisterModule($this->MODULE_ID);
        $this->UnInstallFiles();
    }

    public function InstallFiles()
    {
        // При необходимости можно копировать файлы
        return true;
    }

    public function UnInstallFiles()
    {
        // При необходимости можно удалять файлы
        return true;
    }
}