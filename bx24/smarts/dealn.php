<?
define("NOT_CHECK_PERMISSIONS", true);
define("STOP_STATISTICS", true);
define("BX_SENDPULL_COUNTER_QUEUE_DISABLE", true);
define('BX_SECURITY_SESSION_VIRTUAL', true);
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/interface/admin_list.php');
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/interface/admin_lib.php');
//header("Access-Control-Allow-Origin: *");
use Awz\Admin\Grid\Option as GridOptions;
use Awz\Admin\IList;
use Awz\Admin\IParams;
use Bitrix\Main\Localization\Loc;
use Awz\BxApi\App;
use Bitrix\Main\Web\Json;
include_once(__DIR__.'/include/load_modules.php');
$eventManager = \Bitrix\Main\EventManager::getInstance();
$eventManager->addEventHandlerCompatible('main', 'OnEndBufferContent', array('DealList', 'OnEndBufferContent'), false, 999);

class DealList extends IList implements IParams {

    public static $smartId;

    public static function getTitle(): string
    {
        return Loc::getMessage('AWZ_BXAPI_CURRENCY_CODES_LIST_TITLE');
    }

    public static function OnEndBufferContent(&$content){
        $content = str_replace('parent.BX.ajax.','window.awz_ajax_proxy.', $content);
    }

    public function __construct($params, $publicMode=false){

        if(!empty($params['SMART_FIELDS'])){
            \Awz\Admin\DealTable::$fields = $params['SMART_FIELDS'];
        }
        $params['TABLEID'] = $params['GRID_ID'];
        $params = \Awz\Admin\Helper::addCustomPanelButton($params);
        parent::__construct($params, $publicMode);
    }

    public function trigerGetRowListAdmin($row){
        //print_r($row);
        //die();
        \Awz\Admin\Helper::defTrigerList($row, $this);

        $entity = $this->getParam('ENTITY');
        $fields = $entity::$fields;
    }

    public function trigerInitFilter(){
    }

    public function trigerGetRowListActions(array $actions): array
    {
        return $actions;
    }

    public function getUserData(int $id = 0){
        //print_r(self::$usersCache);
        //die();
        if(isset(self::$usersCache[$id])) {
            return self::$usersCache[$id];
        }
        return [];
    }

    public function getUser(int $id = 0)
    {

        static $users = array();

        if(!isset($users[$id])){
            $request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
            if($bx_result = $request->getPost('bx_result')){
                if(isset($bx_result['users'][$id])){
                    $users[$id] = $bx_result['users'][$id];
                }else{
                    $userData = $this->getUserData($id);
                    if(isset($userData['name'])){
                        $users[$id] = $userData['name'];
                    }
                }
            }
        }
        return $users[$id] ?? array();
    }

    public static function getParams(): array
    {
        $arParams = [
            "PRIMARY"=>"ID",
            "ENTITY" => "\\Awz\\Admin\\DealTable",
            "BUTTON_CONTEXTS"=>[
                [
                    'add'=> [
                        'TEXT' => 'Добавить сделку',
                        'ICON' => '',
                        'LINK' => '',
                        'ONCLICK' => 'window.awz_helper.menuNewEl();return false;',
                    ]
                ],
                [
                    'reload'=> [
                        'TEXT' => 'Обновить',
                        'ICON' => '',
                        'LINK' => '',
                        'ONCLICK' => 'window.awz_helper.reloadList();return false;',
                    ],
                    'rmcache'=> [
                        'TEXT' => 'Удалить кеш полей',
                        'ICON' => '',
                        'LINK' => '',
                        'ONCLICK' => 'window.awz_helper.rmCache();return false;',
                    ]
                ]
            ],
            "ADD_LIST_ACTIONS"=> [
                "delete",
            ],
            "FIND"=> []
        ];

        return $arParams;
    }

    public function initFilter(){
        if(!$this->getParam("FIND")) return;

        $this->filter = array();

        $this->getAdminList()->AddFilter($this->getParam("FIND"), $this->filter);

        $this->checkFilter();

        if(method_exists($this, 'trigerInitFilter'))
            $this->trigerInitFilter();
    }

    public function getAdminResult(){
        //$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
        //echo'<pre>';print_r($_REQUEST);echo'</pre>';
        //echo'<pre>';print_r($_POST);echo'</pre>';
        static $results;
        if(!$results){
            $request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
            if($bx_result = $request->getPost('bx_result')){
                $results = $bx_result;
                //$this->addUsersFromAdminResult($results['users'] ?? []);
            }
        }
        return $results;
    }

    public function getAdminRow(){
        $n = 0;
        $pageSize = $this->getAdminList()->getNavSize();
        $res = $this->getAdminResult();
        //print_r($res);
        //die();
        $ost = 0;
        if(isset($res['next'])){
            $ost = fmod($res['next'],50);
            if($ost == 50) $ost = 0;
        }
        if(isset($res['items'])){
            foreach ($res['items'] as $row){
                //echo'<pre>';print_r($row);echo'</pre>';
                if(empty($row)) continue;
                //$row['id'] = 'n: '.$n.', ost: '.$ost.', pageSize: '.$pageSize;
                //echo'<pre>';print_r($row);echo'</pre>';
                $n++;
                if($ost && ($ost>$n)) continue;
                if(($n-$ost) == 0) continue;
                if ((($n-$ost) > $pageSize) && !$this->excelMode)
                {
                    break;
                }
                $this->getRowListAdmin($row);
            }
            if(!$n){
                $this->getRowListAdmin(array());
            }
        }else{
            $this->getRowListAdmin(array());
        }
        $nav = $this->getAdminList()->getPageNavigation($this->getParam('TABLEID'));
        $nav->setRecordCount($nav->getOffset() + $n);
        $this->getAdminList()->setNavigation($nav, Loc::getMessage($this->getParam("LANG_CODE")."NAV_TEXT"), false);

    }

    public function defaultPublicInterface(){

        global $APPLICATION;
        //инициализация фильтра
        $this->initFilter();
        //проверка действий
        //$this->checkActions($this->getParam('RIGHT', 'D'));
        //доступные колонки, устанавливает только нужные поля в выборку
        $this->AddHeaders();

        //формирование списка
        $this->getAdminRow();

        $this->AddGroupActionTable();
        //$list_id = $this->getParam('TABLEID');

        $this->AddAdminContextMenu(false, false);

        $defPrm = ["SHOW_COUNT_HTML" => false];
        if($this->getParam('ADD_REQUEST_KEY')){
            $defPrm['ADD_REQUEST_KEY'] = $this->getParam('ADD_REQUEST_KEY');
        }
        if($this->getParam('ACTION_PANEL')){
            $defPrm['ACTION_PANEL'] = $this->getParam('ACTION_PANEL');
        }

        if($this->getParam('FIND')){
            $this->getAdminList()->DisplayFilter($this->getParam('FIND', array()));
        }

        $this->getAdminList()->DisplayList($defPrm);

        if($this->getParam('SMART_ID')){
            ?>
            <script type="text/javascript">
                $(document).ready(function(){
                    BX24.ready(function() {
                        BX24.init(function () {
                            <?if($prefilter = $this->getParam('GRID_OPTIONS_PREFILTER')){?>
                            window.awz_helper.addFilter = <?=\CUtil::PhpToJSObject($prefilter)?>;
                            <?}?>
                            window.awz_helper.gridUrl = window.location.pathname + window.location.search;
                            <?if(defined('CURRENT_CODE_PAGE')){?>
                            window.awz_helper.gridUrl = window.awz_helper.gridUrl.replace('/smarts/index.php?','/smarts/?');
                            window.awz_helper.gridUrl = window.awz_helper.gridUrl.replace('/smarts/?','/smarts/<?=CURRENT_CODE_PAGE?>.php?');
                            <?}?>
                            <?
                            $gridOptions = new GridOptions($this->getParam('TABLEID'));
                            $sort = $gridOptions->getSorting(['sort'=>[$this->getParam('PRIMARY') =>'desc']]);
                            $_EXT_PARAMS = $this->getParam('EXT_PARAMS');
                            ?>

                            <?if(isset($_EXT_PARAMS[1])){?>window.awz_helper.extUrl = '<?=$_EXT_PARAMS[1]?>';<?}?>
                            window.awz_helper.currentUserId = '<?=$this->getParam('CURRENT_USER')?>';
                            window.awz_helper.lastOrder = <?=\CUtil::PhpToJSObject($sort['sort'])?>;
                            window.awz_helper.fields = <?=\CUtil::PhpToJSObject($this->getParam('SMART_FIELDS'))?>;
                            window.awz_helper.fields_select = <?=\CUtil::PhpToJSObject($this->getParam('SMART_FIELDS_SELECT'))?>;
                            window.awz_helper.filter_dates = <?=\CUtil::PhpToJSObject(\Awz\Admin\Helper::getDates())?>;
                            window.awz_helper.init(
                                '<?=$this->getParam('ADD_REQUEST_KEY')?>',
                                '<?=$this->getParam('SMART_ID')?>',
                                '<?=$this->getParam('TABLEID')?>',
                                <?=$this->getAdminList()->getNavSize()?>,
                                <?=\CUtil::PhpToJSObject($this->getParam('GRID_OPTIONS'))?>
                            );
                        });
                    });
                });
            </script>
            <?php
            //die();
        }

    }
}

use DealList as PageList;

$arParams = PageList::getParams();

global $APPLICATION;
include_once(__DIR__.'/include/app_auth.php');
/* @var App $app */
/* @var $checkAuth */
/* @var $checkAuthKey */
/* @var $checkAuthDomain */
/* @var $checkAuthMember */
/* @var $checkAuthAppId */
/* @var bool $customPrint */

$placement = $app->getRequest()->get('PLACEMENT_OPTIONS');
if($placement) {
    $placement = Json::decode($placement);
}
$checkAuthGroupId = $placement['GROUP_ID'] ?? "";
?>
<?php
include_once(__DIR__.'/include/header.php');
if(!$checkAuth){
    include_once(__DIR__.'/include/no_auth.php');
}else{
    include_once(__DIR__.'/include/grid_params.php');
    /* @var \Bitrix\Main\Result $loadParamsEntity */
    if($loadParamsEntity->isSuccess()){
        $loadParamsEntityData = $loadParamsEntity->getData();
        $gridOptions = $loadParamsEntityData['options'];

        $arParams['GRID_OPTIONS'] = $gridOptions;
        $arParams['GRID_OPTIONS']['method_list'] = 'crm.deal.list';
        $arParams['GRID_OPTIONS']['method_delete'] = 'crm.deal.delete';
        $arParams['GRID_OPTIONS']['method_update'] = 'crm.deal.update';
        $arParams['GRID_OPTIONS']['method_add'] = 'crm.deal.add';
        $arParams['GRID_OPTIONS']['result_key'] = '-';
        $arParams['SMART_ID'] = $gridOptions['PARAM_1'] ?? "";
        //Для всех документов типа сущности
        //$arParams['GRID_OPTIONS']['cache_key'] = time();
        //вшешние задачи
        if($extWebHook = $app->getRequest()->get('ext')){
            $arParams['EXT_PARAMS'] = [
                'task',
                'https://'.$extWebHook
            ];
        }
    }

    //TASK_GROUP_
    if($arParams['SMART_ID'] && $loadParamsEntity->isSuccess()){
        /* @var string $cacheId */
        /* @var int $cacheKey */
        // $loadParamsEntity - могут добавиться ошибки
        include_once(__DIR__.'/include/gen_keys.php');

        if($loadParamsEntity->isSuccess()){

            $app->setCacheParams($cacheId);

            if(!empty($arParams['EXT_PARAMS'])){
                $bxRowsResFields = $app->postMethod($arParams['EXT_PARAMS'][1].'crm.deal.fields');
            }else{
                $bxRowsResFields = $app->postMethod('crm.deal.fields');
            }

            //echo'<pre>';print_r($bxRowsResFields);echo'</pre>';
            //die();
            //$entCodes = Helper::entityCodes();

            if($bxRowsResFields->isSuccess()){

                $bxFields = $bxRowsResFields->getData();
                $allFields = $bxFields['result']['result'];

                $batchAr = [];
                include_once(__DIR__.'/include/batch_fields_params.php');
                foreach($allFields as &$field){

                }
                unset($field);

                //echo'<pre>';print_r($allFields);echo'</pre>';
                //die();

                $deActiveFields = [
                    /*'ADDRESS','ADDRESS_2','ADDRESS_CITY','ADDRESS_POSTAL_CODE','ADDRESS_REGION',
                    'ADDRESS_PROVINCE','ADDRESS_COUNTRY','ADDRESS_COUNTRY_CODE','ADDRESS_LOC_ADDR_ID',
                    'ADDRESS_LEGAL','REG_ADDRESS','REG_ADDRESS_2','REG_ADDRESS_CITY','REG_ADDRESS_POSTAL_CODE',
                    'REG_ADDRESS_REGION','REG_ADDRESS_PROVINCE','REG_ADDRESS_COUNTRY','REG_ADDRESS_COUNTRY_CODE',
                    'REG_ADDRESS_LOC_ADDR_ID','BANKING_DETAILS'*/
                ];
                $activeFields = [];
                $finFields = [];
                foreach($allFields as $key=>&$field){
                    $field['sort'] = $key;
                    $field = \Awz\Admin\Helper::preformatField($field);
                    if(!in_array($key, $deActiveFields)){
                        $finFields[$key] = $field;
                        $selectFormatFields[] = $key;
                    }
                }
                $allFields = $finFields;
                unset($field);
                //echo'<pre>';print_r($allFields);echo'</pre>';

                $arParams['SMART_FIELDS'] = $finFields;
                $arParams['SMART_FIELDS_SELECT'] = $selectFormatFields;

                include(__DIR__.'/include/clever_smart.php');

            }else{
                $app->cleanCache($cacheId);
                $loadParamsEntity->addErrors($bxRowsResFields->getErrors());
            }

        }
        //echo'<pre>';print_r($bxRowsResFields);echo'</pre>';
    }
    if($arParams['SMART_ID'] && !$customPrint && $loadParamsEntity->isSuccess()){
        PageList::$smartId = $arParams['SMART_ID'];
        $adminCustom = new PageList($arParams, true);

        $fields = \Awz\Admin\DealTable::getMap();
        //echo'<pre>';print_r($allFields);echo'</pre>';
        //$fields = $arParams['SMART_FIELDS'];
        $addFilters = [];
        foreach($fields as $obField){
            if(\Awz\Admin\Helper::checkDissabledFilter($arParams, $obField)) continue;
            \Awz\Admin\Helper::addFilter($arParams, $obField);
            if(!($obField instanceof \Bitrix\Main\ORM\Fields\StringField)){
                $addFilters[] = [
                    'id'=>$obField->getColumnName().'_str',
                    'realId'=>$obField->getColumnName(),
                    'name'=>$obField->getTitle().' [строка]',
                    'type'=>'string'
                ];
            }
        }

        foreach($arParams['FIND'] as &$field){

        }
        unset($field);
        foreach($addFilters as $f){
            $arParams['FIND'][] = $f;
        }

        include(__DIR__.'/include/standart_actions.php');
    }
    include(__DIR__.'/include/entity_error.php');
}
include(__DIR__.'/include/footer.php');