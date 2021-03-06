<?php

declare(strict_types=1);

namespace Glued\Integrations\Controllers;

use Carbon\Carbon;
use \Opis\JsonSchema\Loaders\File as JSL;
use Glued\Core\Classes\Json\JsonResponseBuilder;
use Glued\Core\Controllers\AbstractTwigController;
use Phpfastcache\CacheManager;
use Phpfastcache\Config\Config;
use Phpfastcache\Helper\Psr16Adapter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Respect\Validation\Validator as v;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpInternalServerErrorException;
use Symfony\Component\DomCrawler\Crawler;
use Glued\Integrations\Classes\Google;
use OndraKoupil\Csob\Config as CsobConfig;
use OndraKoupil\Csob\GatewayUrl;
use OndraKoupil\Csob\Client;
use Defr\QRPlatba\QRPlatba;

class IntegrationsController extends AbstractTwigController
{

    public function csob_get_app(Request $request, Response $response, array $args = []): Response {
        echo 'https://iplatebnibrana.csob.cz/keygen/';
        $config = new CsobConfig(
                $this->settings['csob']['merchant_id'],
                __ROOT__ . "/private/api/csob.key",
                __ROOT__ . "/private/api/csob.brana.pub",
                $this->settings['csob']['shop_name'],
                $this->settings['csob']['return_uri'],
                GatewayUrl::TEST_LATEST
            );

        $client = new Client($config);    
        try {
            $client->testGetConnection();
            $client->testPostConnection();
            echo 'ok';

        } catch (Exception $e) {
            echo "Something went wrong: " . $e->getMessage();
        }
        return $response;
    }

    public function qrpay_get_api(Request $request, Response $response, array $args = []): Response {


    // https://10.146.149.150/integrations/qrplatba?amount=133.3&name=pavel stratil&street=kainarova 26&locacity=brno&bday=22.11.1980&regid=34323423&zip=61600&email=a@b.c

    $volume_def = 1000.0;
    $volume = (float) ($request->getQueryParam('volume') ?? $volume_def);
    $email  = (string) ($request->getQueryParam('email') ?? '');
    $eid    = $this->crypto->genkey_base64();

    $json['eid'] = $eid;
    $json['volume'] = $volume;
    $json['email'] = $email ?? '';
    $json['name'] = $request->getQueryParam('name') ?? '';
    $json['addr']['street'] = $request->getQueryParam('street') ?? '';
    $json['addr']['locacity'] = $request->getQueryParam('locacity') ?? '';
    $json['addr']['zip'] = $request->getQueryParam('zip') ?? '';
    $json['bday'] = $request->getQueryParam('bday') ?? '';
    $json['regid'] = $request->getQueryParam('regid') ?? '';

    $row = array (
        'c_eid' => $eid,
        'c_json' => json_encode($json),
    );
            try { $new = $this->utils->sql_insert_with_json('t_store_payments', $row); } catch (Exception $e) { 
                //throw new HttpInternalServerErrorException($request, $e->getMessage());  
            }

    $qrPlatba = new QRPlatba();

    

    $qrPlatba->setAccount('2500781658/2010')
        ->setAmount($volume)
        ->setMessage($eid. ' ' . $email)
        //->setCurrency('CZK') // Výchozí je CZK, lze zadat jakýkoli ISO kód měny
        //->setDueDate(new \DateTime());
        ;

        echo $qrPlatba->getQRCodeImage(); // Zobrazí <img> tag s kódem, viz níže         
        return $response; 
    }

    /**
     * @param Request  $request
     * @param Response $response
     * @param array    $args
     *
     * @return Response
     */
    public function google_list_ui(Request $request, Response $response, array $args = []): Response {
        // Since we don't have RBAC implemented yet, we're passing all domains
        // to the view. The view uses them in the form which adds/modifies a view.
        // 
        // TODO - write a core function that will get only the domains for a given user
        // so that we dont copy paste tons of code around and we don't present sources out of RBAC
        // scope of a user.
        //
        
        // nacteme moje sheety
        $this->db->where('c_provider', 'google');
        $this->db->where('c_service', 'spreadsheets');
        $this->db->where('c_user_id', $GLOBALS['_GLUED']['authn']['user_id']);
        $spreadsheets = $this->db->get('t_int_objects', null, ['c_uid as id', 'c_progress as progress', 'c_json->>"$.uri" as uri', 'c_json->>"$.attributes.spreadsheetId" as spreadsheetId', 'c_json->>"$.attributes.sheetId" as sheetId', 'c_json->>"$.attributes.sheetTitle" as sheetTitle']);
        return $this->render($response, 'Integrations/Views/google_docs.twig', [
            'spreadsheets' => $spreadsheets
        ]);
    }
    
    // stranka s detailem zadaneho zaznamu, kde budou ruzne veci podle progresu
    /*
        progress ma tyto stavy
        0 - nic nebylo zatim udelano
        1 - adresa nejde rozeznat, zmente adresu
        5 - mame k dispozici spreadsheet
        6 - nejde se k nemu pripojit
        7 - muzeme se pripojit ale neni vybrany sheet
        10 - mame spreadsheet, i sheet a jde se pripojit, muzou se pridavat skupiny funkci
        15 - ukonceno zadavani skupin funkci. je to ready ke spusteni
    */
    public function google_detail_ui(Request $request, Response $response, array $args = []): Response {
        $object_id = (int) $args['uid'];
        
        // pripravime si taky spojeni s goglem
        $client = new \Google_Client();
        $client->setApplicationName('Google Sheets and PHP');
        $client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);
        $client->setAccessType('offline');
        $client->setAuthConfig(__ROOT__ . '/private/api/glued-dev-91338368ae7d.json');
        $service = new \Google_Service_Sheets($client);
        
        // nacteme moje sheety
        $this->db->where('c_uid', $object_id);
        $data_sheet = $this->db->getOne('t_int_objects', ['c_uid as id', 'c_progress as progress', 'c_json as json', 'c_json->>"$.uri" as uri', 'c_json->>"$.attributes.spreadsheetId" as spreadsheetId', 'c_json->>"$.attributes.sheetId" as sheetId', 'c_json->>"$.attributes.sheetTitle" as sheetTitle']);
        $json_data = json_decode($data_sheet['json'], true);
        $progress = $data_sheet['progress'];
        
        $next = array();
        $next['description'] = '';
        $next['inputs'] = '';
        $next['submit'] = '';
        
        if ($progress == 0) {
            $next['description'] = 'jeste neprobehlo zadne overeni adresy<br />'.$data_sheet['uri'];
            $next['submit'] = 'over adresu';
        }
        else if ($progress == 1) {
            $next['description'] = 'v zadane adrese vubec neni id spreadsheetu<br />upravte ji prosim na platnou';
            $next['inputs'] = '<input type="text" name="uri" value="'.$data_sheet['uri'].'" />';
            $next['submit'] = 'upravit adresu';
        }
        else if ($progress == 5) {
            $next['description'] = 'mame id spreadsheetu, nyni je treba overit, ze k nemu mame pristup';
            $next['submit'] = 'over pristup';
        }
        else if ($progress == 6) {
            $next['description'] = 'vas spreadsheet neni pristupny. povolte u nej zpracovani a zkuste ho znova overit.';
            $next['submit'] = 'over znovu pristup';
        }
        else if ($progress == 7) {
            $next['description'] = 'v adrese neni zadane id sheetu. vyberte z nabizenych moznosti.';
            $spreadSheet = $service->spreadsheets->get($data_sheet['spreadsheetId']);
            $sheets = $spreadSheet->getSheets();
            $sheets_options = array();
            foreach($sheets as $sheet) {
                $sheets_options[] = '<option value="'.$sheet->properties->sheetId.'">'.$sheet->properties->title.': '.$sheet->properties->sheetId.'</option>';
            }
            $next['inputs'] = '<div><select name="gid">'.implode('', $sheets_options).'</select></div>';
            $next['submit'] = 'vyber sheet';
        }
        else if ($progress == 10) {
            $next['description'] = 'mate spreadsheet i sheet. vyberte funkce a jejich rozsahy dat a vytvorte tak pojmenovanou skupinu funkci. nebo vice skupin funkci. pokud vse mate, ukoncete tuto fazi';
            
        // "attributes": {
        //   "spreadsheetId": "14y4sJZ1cCUlIvTmq021hGwSl4em6Iv-6Cr-DHOrY5fs", // povinne
        //   "sheetId": "607165653", // nepovinne, jen pokud je v url
        //   "actions": [
        //        "nazev skupiny": [
        //            
        //        ],
        //      "sheets.checkmeta": {       // php funkce, ktera kontroluje, zda existuji predepsane zahlavi sloupcu (v radku definovanem pomoci "meta")
        //         "meta": "Orig!A1:G1",
        //         "reqs": [ "DÚZP", "VS", "VS2" ]
        //      }
        //      "sheets.rowcache": {       // php funkce, ktera cachene data do nasi tabulky - nejdriv udela ze vseho ve sloupecku A md5 a testne, ze jsou hashe fakt unikatni
        //         "meta": "Orig!A1:G1",
        //         "data": "Orig!A2:G5",
        //         "fuid": "A",
        //       },
        //       "sheets.costimport": {    // php funkce, ktera importne zatim nenaimportovane radky do jsonu v t_fin_costs tabulce
        //         "DÚZP": "dt-supply",
        //         "Vystaveno": "dt-issued",
        //       }
        //   ]
        // }
            
            $sheet_functions = array();
            $sheet_functions[] = 'checkmeta';
            $sheet_functions[] = 'rowcache';
            $sheet_functions[] = 'costimport';
            
            $sheet_optiony = '';
            foreach ($sheet_functions as $sf) {
                $sheet_optiony .= '<option>'.$sf.'</option>';
            }
            
            $next['inputs'] = '
                <div><input type="text" name="group_key" value="" placeholder="key of group" /> - key of the group</div>
                <div class="" style="margin: 20px 0; padding: 10px; border: 1px solid black;">
                    <div><select name="funkce1"><option value="0" selected> - not selected - </option>'.$sheet_optiony.'</select> - function 1</div>
                    <div><input type="text" name="meta1" value="" placeholder="meta data" /> - meta 1</div>
                    <div><input type="text" name="data1" value="" placeholder="basic data" /> - data 1</div>
                    <div><input type="text" name="fuid1" value="" placeholder="fuid" /> - fuid 1</div>
                </div>
                <div class="" style="margin: 20px 0; padding: 10px; border: 1px solid black;">
                    <div><select name="funkce2"><option value="0" selected> - not selected - </option>'.$sheet_optiony.'</select> - function 2</div>
                    <div><input type="text" name="meta2" value="" placeholder="meta data" /> - meta 2</div>
                    <div><input type="text" name="data2" value="" placeholder="basic data" /> - data 2</div>
                    <div><input type="text" name="fuid2" value="" placeholder="fuid" /> - fuid 2</div>
                </div>
                <div class="" style="margin: 20px 0; padding: 10px; border: 1px solid black;">
                    <div><select name="funkce3"><option value="0" selected> - not selected - </option>'.$sheet_optiony.'</select> - function 3</div>
                    <div><input type="text" name="meta3" value="" placeholder="meta data" /> - meta 3</div>
                    <div><input type="text" name="data3" value="" placeholder="basic data" /> - data 3</div>
                    <div><input type="text" name="fuid3" value="" placeholder="fuid" /> - fuid 3</div>
                </div>
            ';
            
            $next['submit'] = 'nastavit skupinu funkci';
        }
        else if ($progress == 15) {
            $next['description'] = 'vse mate vybrane. nyni muzete spustit jednotlive skupiny funkci';
            $groups = array();
            
            // dekodujem mozne submity
            foreach ($json_data['attributes']['actions'] as $kk => $vv) {
                $groups[] = $kk;
            }
            
        }
        
        return $this->render($response, 'Integrations/Views/google.detail.twig', [
            'row' => $data_sheet,
            'actions' => $json_data['attributes']['actions'] ?? [],
            'next' => $next,
            'groups' => $groups ?? []
        ]);
    }
    
    
    
    // neni to api, normalne to zpracuje poslany formular postem, vlozi novy zaznam a presmeruje se na stranku detailu
    public function google_post(Request $request, Response $response, array $args = []): Response {
        $builder = new JsonResponseBuilder('integrations.google', 1);
        $post_data = $request->getParsedBody();
        
        // "id": "",
        // "_n": "int.objects",
        // "_v": 1,
        // "provider": "google",
        // "service": "spreadsheets",
        // "uri": "https://docs.google.com/spreadsheets/d/14y4sJZ1cCUlIvTmq021hGwSl4em6Iv-6Cr-DHOrY5fs/edit#gid=607165653",
        
        $user_id = (int) $GLOBALS['_GLUED']['authn']['user_id'];
        
        $req = array();
        $req['user'] = $user_id;
        $req['id'] = 0;
        $req['_v'] = (int) 1;
        $req['_s'] = 'integrations.google';
        $req['provider'] = 'google';
        $req['service'] = 'spreadsheets';
        $req['uri'] = $post_data['uri'];
        $req['name'] = $post_data['uri']; // Temp. name for c_stor_name
        
        // convert body to object
        $req = json_decode(json_encode((object)$req));
        
        // TODO replace manual coercion above with a function to recursively cast types of object values according to the json schema object (see below)       
        
        // load the json schema and validate data against it
        //$loader = new JSL("schema://enterprise/", [ __ROOT__ . "/glued/Enterprise/Controllers/Schemas/" ]);
        //$schema = $loader->loadSchema("schema://enterprise/projects.v1.schema");
        //$result = $this->jsonvalidator->schemaValidation($req, $schema);

        //if ($result->isValid()) {
            $row = array (
                //'c_provider' => 'google',
                //'c_service' => 'spreadsheets',
                'c_domain_id' => 1, // TODO put scope into _GLUED
                'c_user_id' => $user_id,
                'c_progress' => '0',
                'c_json' => json_encode($req),
                'c_attr' => '{}'
            );
            try { $nove_id = $this->utils->sql_insert_with_json('t_int_objects', $row); } catch (Exception $e) { 
                throw new HttpInternalServerErrorException($request, $e->getMessage());
            }
            
            // presmerujeme na adresu integrations.google.detail kde budeme pokracovat
            $redirect_url = $this->routerParser->urlFor('integrations.google.detail').'/'.$nove_id;
            return $response->withRedirect($redirect_url);
        //}
    }
    
    // taky to neni api. jen vyhodnoti jestli je mozne vykonat dalsi krok. prepne progress a vrati se na detail
    // dokumentace https://developers.google.com/sheets/api/reference/rest/v4/spreadsheets
    // demo https://www.srijan.net/blog/integrating-google-sheets-with-php-is-this-easy-know-how
    public function google_progress_next(Request $request, Response $response, array $args = []): Response {
        $builder = new JsonResponseBuilder('integrations.google', 1);
        $object_id = (int) $args['uid'];
        $post_data = $request->getParsedBody();
        
        // pripravime si taky spojeni s goglem
        $client = new \Google_Client();
        $client->setApplicationName('Google Sheets and PHP');
        $client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);
        $client->setAccessType('offline');
        $client->setAuthConfig(__ROOT__ . '/private/api/glued-dev-91338368ae7d.json');
        $service = new \Google_Service_Sheets($client);
        
        // funkce co zjisti title pro zadane gid
        function getSheetTitle($service, $spreadsheetID, $gid) {
            $spreadSheet = $service->spreadsheets->get($spreadsheetID);
            $sheets = $spreadSheet->getSheets();
            $title = '';
            foreach($sheets as $sheet) {
                if ($sheet->properties->sheetId == $gid) {
                    $title = $sheet->properties->title;
                }
            }
            return $title;
        }
        
        // nacteme si jaky mame progress a podle toho provedeme akci
        $this->db->where('c_uid', $object_id);
        $data_sheet = $this->db->getOne('t_int_objects', ['c_progress', 'c_json']);
        $progress = $data_sheet['c_progress'];
        $json_data = json_decode($data_sheet['c_json'], true);
        
        // pokud je progress 0, zjistime, jestli je v adrese vubec spreadsheet
        if ($progress == 0) {
            $regex = '{/spreadsheets/d/([a-zA-Z0-9-_]+)}';
            $result = preg_match($regex, $json_data['uri'], $matches);
            if (!empty($matches[1])) {
                // je tam id spreadsheetu, prepiname na progress 5
                // rozsirime si data o nalezene id
                $json_data['attributes']['spreadsheetId'] = $matches[1];
                // pripravime update data objektu
                $row = [ 'c_progress' => '5', 'c_json' => json_encode($json_data) ];
                $this->db->where('c_uid', $object_id);
                $id = $this->db->update('t_int_objects', $row);
                if (!$id) { throw new HttpInternalServerErrorException( $request, __('Updating failed.')); }
            }
            else {
                // neni tam id spreadsheetu, prepneme na progress 1
                $row = [ 'c_progress' => '1' ];
                $this->db->where('c_uid', $object_id);
                $id = $this->db->update('t_int_objects', $row);
                if (!$id) { throw new HttpInternalServerErrorException( $request, __('Updating failed.')); }
            }
        }
        // tady budeme vkladat novou adresu a prepinat zase na porgress 0
        else if ($progress == 1) {
            
        }
        // overime ze mame pristup k dokumentu danemu $json_data['attributes']['spreadsheetId']
        else if ($progress == 5 or $progress == 6) {
            $spreadsheetID = $json_data['attributes']['spreadsheetId'];
            try {
                $spreadSheet = $service->spreadsheets->get($spreadsheetID);
                $vystup = print_r($spreadSheet, true);
                // spojeni se povedlo, muzeme to prepnout
                // nejdriv musime zjistit, jestli je v adrese i id sheetu gid=607165653
                $regex = '{[#&]gid=([0-9]+)}';
                $result = preg_match($regex, $json_data['uri'], $matches);
                if (isset($matches[1]) and $matches[1] != '') {
                    // neco tam je, dame 10 a ulozime ten sheet
                    $json_data['attributes']['sheetId'] = $matches[1];
                    $json_data['attributes']['sheetTitle'] = getSheetTitle($service, $spreadsheetID, $matches[1]);
                    // pripravime update data objektu
                    $row = [ 'c_progress' => '10', 'c_json' => json_encode($json_data) ];
                    $this->db->where('c_uid', $object_id);
                    $id = $this->db->update('t_int_objects', $row);
                    if (!$id) { throw new HttpInternalServerErrorException( $request, __('Updating failed.')); }
                }
                else {
                    // nic tam neni, dame 7
                    $row = [ 'c_progress' => '7' ];
                    $this->db->where('c_uid', $object_id);
                    $id = $this->db->update('t_int_objects', $row);
                    if (!$id) { throw new HttpInternalServerErrorException( $request, __('Updating failed.')); }
                }
            } catch (\Exception $e) {
                // spojeni se nepovedlo, asi nejsou prava. prepneme to na progres 6
                //$vystup = print_r($e, true);
                $row = [ 'c_progress' => '6' ];
                $this->db->where('c_uid', $object_id);
                $id = $this->db->update('t_int_objects', $row);
                if (!$id) { throw new HttpInternalServerErrorException( $request, __('Updating failed.')); }
            }
            
            /*
            // vystup hodime do test sablony
            return $this->render($response, 'Integrations/Views/google.test.twig', [
                'vystup' => $vystup
            ]);
            */
        }
        else if ($progress == 7) {
            $spreadsheetID = $json_data['attributes']['spreadsheetId'];
            $json_data['attributes']['sheetId'] = $post_data['gid'];
            $json_data['attributes']['sheetTitle'] = getSheetTitle($service, $spreadsheetID, $post_data['gid']);
            // pripravime update data objektu
            $row = [ 'c_progress' => '10', 'c_json' => json_encode($json_data) ];
            $this->db->where('c_uid', $object_id);
            $id = $this->db->update('t_int_objects', $row);
            if (!$id) { throw new HttpInternalServerErrorException( $request, __('Updating failed.')); }
        }
        else if ($progress == 10) {
            // pokud byl stisknuty finalise, ukoncime to
            if (isset($post_data['finalise'])) {
                $row = [ 'c_progress' => '15' ];
                $this->db->where('c_uid', $object_id);
                $id = $this->db->update('t_int_objects', $row);
                if (!$id) { throw new HttpInternalServerErrorException( $request, __('Updating failed.')); }
            }
            else {
                // pridavame zadanou funkci a meta data
                // pokud tam jeste nic neni, zalozime urcite ten prvek actions
                if (!isset($json_data['attributes']['actions'])) { $json_data['attributes']['actions'] = array(); }
                
                $group_key = $post_data['group_key'];
                $json_data['attributes']['actions'][$group_key] = array();
                
                // muzou prijit naraz i 3 funkce
                for ($i = 1; $i <= 3; $i++) {
                    if ($post_data['funkce'.$i] == '0') { continue; }
                    else {
                        // priprava objektu funkce (jako asociativni pole
                        $objekt_funkce = array(
                            "function" => "sheets.".$post_data['funkce'.$i],
                            "meta" => $post_data['meta'.$i],
                            "data" => $post_data['data'.$i],
                            "fuid" => $post_data['fuid'.$i]
                        );
                        $json_data['attributes']['actions'][$group_key][] = $objekt_funkce;
                    }
                }
                
                // pripravime update data objektu
                $row = [ 'c_json' => json_encode($json_data) ];
                $this->db->where('c_uid', $object_id);
                $id = $this->db->update('t_int_objects', $row);
                if (!$id) { throw new HttpInternalServerErrorException( $request, __('Updating failed.')); }
                
            }
        }
        
        // presmerujeme na adresu integrations.google.detail kde budeme pokracovat
        $redirect_url = $this->routerParser->urlFor('integrations.google.detail').'/'.$object_id;
        return $response->withRedirect($redirect_url);
        
    }
    
    
    // zpracovani akci prirazenych k sheetu, klic ke skupine funkci je v hidden post poli group_key
    public function google_sheet_action(Request $request, Response $response, array $args = []): Response {
        $builder = new JsonResponseBuilder('integrations.google', 1);
        $object_id = (int) $args['uid'];
        $post_data = $request->getParsedBody();
        
        $group_key = $post_data['group_key'];
        
        // pripravime si taky spojeni s goglem
        $client = new \Google_Client();
        $client->setApplicationName('Google Sheets and PHP');
        $client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);
        $client->setAccessType('offline');
        $client->setAuthConfig(__ROOT__ . '/private/api/glued-dev-91338368ae7d.json');
        $service = new \Google_Service_Sheets($client);
        
        $this->db->where('c_uid', $object_id);
        $data_sheet = $this->db->getOne('t_int_objects', ['c_uid as id', 'c_progress as progress', 'c_json as json', 'c_json->>"$.uri" as uri', 'c_json->>"$.attributes.spreadsheetId" as spreadsheetId', 'c_json->>"$.attributes.sheetId" as sheetId', 'c_json->>"$.attributes.sheetTitle" as sheetTitle']);
        $json_data = json_decode($data_sheet['json'], true);
        
        $g = new Google($this->db);
        
        //$vystup = 'poslana post data: '.print_r($post_data, true);
        $vystup = '';
        
        // pro zdarne ukonceni skupiny finkci je dulezite, aby prvni funkce byla checkmeta
        // dalsi funkce by se mely vykonavat jen tehdy, kdyz z checkmeta prijde true, a kdyz bude k dispozici vysledek z ni, ktery se pouzije jako argument
        
        $mame_vysledek_checkmeta = false;
        $vysledek_checkmeta = array();
        
        foreach ($json_data['attributes']['actions'][$group_key] as $action) {
            if ($action['function'] == 'sheets.checkmeta') {
                $vystup .= '<div>zpracovavam funkci checkmeta</div>';
                $ret = array();
                // TODO tohle bych mel volat s try, protoze je mozne ze se to nespoji a pak to vrati exception
                $vraceno = $g->checkmeta($service, $data_sheet['spreadsheetId'], $action['meta'], $action['data'], $ret);
                if ($vraceno == false) {
                    $vystup .= '<div>nepovedlo se</div>';
                    $vystup .= '<div>vraceno: '.print_r($ret, true).'</div>';
                }
                else {
                    $vystup .= '<div>povedlo se</div>';
                    $vystup .= '<div>vraceno: '.print_r($ret, true).'</div>';
                    $mame_vysledek_checkmeta = true;
                    $vysledek_checkmeta = $ret;
                }
            }
            else if ($action['function'] == 'sheets.rowcache') {
                $vystup .= '<div>zpracovavam funkci rowcache</div>';
                // jen pokud mam vysledek z checkmeta
                if ($mame_vysledek_checkmeta) {
                    // TODO taky by to melo byt v try, protoze to muze vratit exception
                    $ret2 = array();
                    $vraceno = $g->rowcache($service, $data_sheet['spreadsheetId'], $object_id, $vysledek_checkmeta, $action['data'], $action['fuid'], $ret2);
                    if ($vraceno == true) {
                        $vystup .= '<div>povedlo se, ale nevim co se presne delo</div>';
                    }
                    else {
                        $vystup .= '<div>nepovedlo se, sory jako</div>';
                        $vystup .= '<div>vraceno: '.print_r($ret2, true).'</div>';
                    }
                }
                else {
                    $vystup .= '<div>nepovedlo se, protoze nemam predchozi vysledek z checkmeta</div>';
                }
            }
            else if ($action['function'] == 'sheets.costimport') {
                $vystup .= 'zpracovavam funkci costimport *';
                
                
                
            }
        }
        
        
        
        //$flash_vystup = '';
        //$flash_vystup .= print_r($post_data, true);
        //$this->flash->addMessage('info', $flash_vystup);
        
        // presmerujeme na adresu integrations.google.detail kde budeme pokracovat
        //$redirect_url = $this->routerParser->urlFor('integrations.google.detail').'/'.$object_id;
        //return $response->withRedirect($redirect_url);
        
        
        
        return $this->render($response, 'Integrations/Views/google.action.twig', [
            'row' => $data_sheet,
            'group' => $group_key,
            'vystup' => $vystup
        ]);
    }
    
    
    // ==========================================================
    // GOOGLE API
    // ==========================================================
    
    public function google_patch(Request $request, Response $response, array $args = []): Response {
        throw new HttpBadRequestException( $request, __('Editing transactions is not yet implemented. Ask your admin for a manual edit.'));
        $builder = new JsonResponseBuilder('fin.trx', 1);
        $payload = $builder->withData((array)$data)->withCode(200)->build();
        return $response->withJson($payload, 200);
    }

    public function google_delete(Request $request, Response $response, array $args = []): Response {
        try { 
          $this->db->where('c_uid', (int)$args['uid']);
          $this->db->delete('t_int_objects');
        } catch (Exception $e) { 
          throw new HttpInternalServerErrorException($request, $e->getMessage());  
        }
        
        $builder = new JsonResponseBuilder('integrations.google', 1);
        $req = $request->getParsedBody();
        $req['user'] = (int)$GLOBALS['_GLUED']['authn']['user_id'];
        $req['id'] = (int)$args['uid'];
        $payload = $builder->withData((array)$req)->withCode(200)->build();
        return $response->withJson($payload, 200);
    }
    
}

