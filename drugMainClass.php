<?php
include_once(dirname(__FILE__).'/libraries/PHPExcel/IOFactory.php');

/**
 * This class used for PHPExcel to filter the row data.
 */
class chunkReadFilter implements PHPExcel_Reader_IReadFilter{
    private $_startRow = 0;
    private $_endRow = 0;

    /**
     *
     * @param Int $startRow
     * @param Int $chunkSize
     */
    public function setRows($startRow, $chunkSize) {
        $this->_startRow    = $startRow;
        $this->_endRow        = $startRow + $chunkSize;
    }

    /**
     *
     * @param String $column
     * @param Int $row
     * @param String $worksheetName
     * @return boolean
     */
    public function readCell($column, $row, $worksheetName = '') {
        if(((int)$row >= (int)$this->_startRow && (int)$row < (int)$this->_endRow)){
            return true;
        }
        return false;
    }
}

class drugMainClass{
    //system variables
    var $tenant_id = '';
    var $tenant_name = '';
    var $appdir = '';
    var $srcdir = '';
    var $error_list = array();
    var $main_column_num = array();
    var $main_data = array();
    var $app_location = '';

    function __construct(){
        ini_set('memory_limit', '512M');
        if (!extension_loaded('xdebug')) set_time_limit(0);
        // ^-- skip set_time_limit(0) if xdebug enabled (will hang indefinitely if otherwise)
        $this->argv = array('--reset'=>'','--tenantid'=>'','--name'=>'','--file'=>'');
        $this->_load_default_ini();
        require_once($this->srcdir."/formdata.inc.php");
        require_once($this->srcdir."/htmlspecialchars.inc.php");
    }

    /**
     * Get the tenant_id from tenant_name
     *
     * @param String $name
     * @return tenant_id as string else false
     */
    function get_tenant_from_name($name){
        $return = false;
        if(($link_id = $this->_db_conn())){
            $query = "SELECT `tenant_id` FROM tenants WHERE name='".addslashes($name)."' limit 1";
            $result = mysqli_query($link_id, $query) or die(mysqli_error($link_id));
            while($r = mysqli_fetch_assoc($result)){
                $return = $r['tenant_id'];
                break;
            }
            mysqli_free_result($result);
        }
        return $return;
    }

    /**
     *
     * @param String $tenant_id
     * @return tenant_id if tenant's db connection success else false
     */
    function set_tenant($tenant_id){
        $this->tenant_id = '';
        $this->tenant_name = '';
        if(($link_id = $this->_db_conn())){
            $query = "SELECT `name` FROM tenants WHERE tenant_id='".addslashes($tenant_id)."' limit 1";
            $result = mysqli_query($link_id, $query) or die(mysqli_error($link_id));
            while($r = mysqli_fetch_assoc($result)){
                $this->tenant_id = $tenant_id;
                $this->tenant_name = $r['name'];
                $this->argv['--tenantid'] = $this->tenant_id;
                $this->argv['--name'] = $this->tenant_name;
                break;
            }
            mysqli_free_result($result);
        }
        if(strlen($this->tenant_id)>0){
            return $this->_db_connect();
        }
        return false;
    }

    /**
     * Read excel file into array
     *
     * @param String $input_file
     * @return data as array else false
     */
    public function _read_excel($input_file){
        include_once(dirname(__FILE__).'/libraries/PHPExcel/IOFactory.php');
        try{
            if(class_exists('PHPExcel_IOFactory') && ($document = PHPExcel_IOFactory::load($input_file))){
                return $document->getActiveSheet()->toArray('',true,true,false);
            }
        } catch (Exception $ex) {
            $this->error_handler("Invalid Excel File!", "excel_upload_error");
        }
        return false;
    }

    /**
     * Read excel into array with filter features.
     *
     * @param String $input_file
     * @param Int $from_row
     * @param Int $chunk_size
     * @return data as array else false
     */
    public function _read_excel_chunk($input_file, $from_row = 1, $chunk_size = 5000){
        try{
            $inputFileType = PHPExcel_IOFactory::identify($input_file);
            if(strtolower($inputFileType)=="csv"){
                if($from_row!=1){
                    return false;
                }
                $temp = array();
                if (($handle = fopen($input_file, "r")) !== FALSE) {
                    while (($data = fgetcsv($handle)) !== FALSE) {
                        $temp[] = $data;
                    }
                    fclose($handle);
                }
                if(sizeof($temp)==0){
                    return false;
                }
                return $temp;
            }
            $objReader = PHPExcel_IOFactory::createReader($inputFileType);
            $spreadsheetInfo = $objReader->listWorksheetInfo($input_file);
            $max_rows = $spreadsheetInfo[0]['totalRows'];
            if($from_row>$max_rows){
                return false;
            }
            $chunkFilter = new chunkReadFilter();
            $objReader->setReadFilter($chunkFilter);
            $objReader->setReadDataOnly(true);
            $chunkFilter->setRows($from_row,$chunk_size);
            $objPHPExcel = $objReader->load($input_file);
            $temp = $objPHPExcel->getActiveSheet()->toArray('',true,true,false);
            $objPHPExcel->disconnectWorksheets();
            unset($objPHPExcel);
            $temp = array_splice($temp, $from_row-1, $chunk_size);
            if(sizeof($temp)==0){
                return false;
            }
            return $temp;
        } catch (Exception $ex) {
            $this->error_handler($ex->getMessage(), "excel_upload_error");
        }
        return false;
    }

    public function _export_excel($array_data="",$template_file="",$export_filename="",$is_download=false){
        error_reporting(0);
        if(!is_array($array_data) || sizeof($array_data)==0){
            return false;
        }
        include_once(dirname(__FILE__).'/libraries/PHPExcel/IOFactory.php');
        try{
            if(!class_exists('PHPExcel_IOFactory')){
                return false;
            }
            $excel = false;
            if(strlen($template_file)>0 && file_exists($template_file)){
                $excel = PHPExcel_IOFactory::createReader('Excel2007');
                $excel = $excel->load($template_file);
                $row = $excel->getActiveSheet()->getHighestRow() + 1;
            }else{
                include_once(dirname(__FILE__).'/libraries/PHPExcel.php');
                if(!($excel = new PHPExcel())){
                    return false;
                }
                $row = 1;
            }

            foreach($array_data as $d){
                $col = 0;
                foreach($d as $v){
                    $excel->getActiveSheet()->setCellValueExplicitByColumnAndRow ($col,$row,$v);
                    $col++;
                }
                $row++;
            }

            if(is_object($excel) && method_exists($excel,'getActiveSheet')){
                if(strlen($export_filename)==0){
                    $export_filename = "export_".date("YmdHis").".xlsx";
                }
                if($is_download){
                    $objWriter = PHPExcel_IOFactory::createWriter($excel, 'Excel2007');
                    header('Content-type: application/vnd.ms-excel');
                    header('Content-Disposition: attachment; filename="'.$export_filename.'"');
                    $objWriter->save('php://output');
                }else if(file_exists($export_filename)){
                    $objWriter = PHPExcel_IOFactory::createWriter($excel, 'Excel2007');
                    $objWriter->save($export_filename);
                }
                return true;
            }
        } catch (Exception $ex) {
            $this->error_handler("Invalid Excel File!", "excel_upload_error");
        }
        return false;
    }

    /**
     * Load config from config.ini
     */
    public function _load_default_ini(){
        $file = dirname(__FILE__).'/config.ini';
        if(file_exists($file) && ($temp = parse_ini_file($file))){
            foreach($temp as $key => $value){
                if(isset($this->{$key})){
                    $this->{$key} = $value;
                }
            }
        }

        $temp = rtrim(str_replace('//', '/', dirname(__FILE__).'/'.$this->app_location),'/');
        if(file_exists($temp)){
            $this->appdir = $temp.'/';
        }else{
            exit;
        }
        $this->srcdir = $this->appdir.'library';
    }

    /**
     *
     * @staticvar boolean $link_id
     * @return Returns an object which represents the connection to a MySQL Server else false
     */
    public function _db_conn(){
        static $link_id = false;
        if(!$link_id){
            require $this->appdir.'../env.php';

            // check if env file exists
            $env_files = glob($this->appdir.'../env.*.php');
            array_walk($env_files, function (&$value, $key) {
                $value = basename($value);
            });
            $dir = explode('/', realpath($this->appdir.'../'));
            $current_env = array_pop($dir); // get the last dir name
            $env = 'env.' . $current_env . '.php';
            if (in_array($env, $env_files)) {
                if (file_exists($this->appdir.'../' . $env)) {
                    require $this->appdir.'../' . $env;
                }
            }else if(file_exists($this->appdir.'../env.local.php')) {
                // for local development create a file: env.local.php
                // override $db config above with your local setting there
                require $this->appdir.'../env.local.php';
            }
            if(isset($env_mysql_path)){
                $this->mysql_path = $env_mysql_path;
                $this->db = $db;
            }
            $link_id = mysqli_connect($db['host'], $db['user'], $db['pass'], $db['name']);
            // check connection
            if (mysqli_connect_errno()) {
                printf("Connect failed: %s\n", mysqli_connect_error());
                exit();
            }
            mysqli_query($link_id, "SET NAMES 'UTF8'") or die(mysqli_error($link_id));
        }
        return $link_id;
    }

    /**
     *
     * @param String $query
     * @return boolean
     */
    public function _db_execute($query,$bind_array = array()){
        $result = false;
        try{
            if(stristr($query, "?")!==FALSE && is_array($bind_array) && sizeof($bind_array)>0){
                $query = str_replace("?", "%s", $query);
                $temp = array();
                foreach($bind_array as $a){
                    $temp[] = '"'.$this->_db_escape($a).'"';
                }
                $query = vsprintf($query,$temp);
            }
            $result = mysqli_query($this->_db_conn(), $query);
            if(!$result){
                die(mysqli_error($this->_db_conn()));
            }
        } catch (Exception $ex) {
            $result = false;
        }
        return $result;
    }

    /**
     *
     * @param String $query
     * @return data as array else false
     */
    public function _db_query($query,$bind_array = array()){
        $return = false;
        $result = $this->_db_execute($query,$bind_array);
        if($result){
            $return = array();
            if(is_object($result) && $result->num_rows){
                $return = mysqli_fetch_all($result,MYSQLI_ASSOC);
                mysqli_free_result($result);
            }
        }
        return $return;
    }

    /**
     *
     * @param String $query
     * @return insert_id as Integer else false
     */
    public function _db_insert($query,$bind_array = array()){
        $result = $this->_db_execute($query,$bind_array);
        if($result){
            return mysqli_insert_id($this->_db_conn());
        }
        return false;
    }

    /**
     *
     * @param String $value
     * @return escape data as string
     */
    public function _db_escape($value){
        return mysqli_real_escape_string($this->_db_conn(),$value);
    }

    /**
     *
     * @param type $table
     * @param string $save_path
     * @param type $type 0=all, 1=table, 2=data
     */
    public function export_table($table,$save_path='',$type='0'){
        $this->_db_conn();
        if($save_path==''){
            $save_path = $table.'_'.date("YmdHis").'.sql';
        }
        $extra_options = array();
        if($type=='1'){
            $extra_options[] = "--no-data";
        }else if($type=='2'){
            $extra_options[] = "--no-create-info";
        }
        $cmd = $this->mysql_path.'dump --compact --skip-triggers --no-create-db '.implode(" ",$extra_options).' --default-character-set="utf8" --host="'.$this->db['host'].'" --user="'.$this->db['user'].'" --password="'.$this->db['pass'].'" '.$this->db['name'].' '.$table.' > '.$save_path;
        shell_exec($cmd);
    }

    public function delete_files($file,$recursive = false){
        try{
            $file = trim(trim($file,'/'),'\\');
            if(file_exists($file)){
                if(is_dir($file)){
                    if($recursive){
                        if($handle = opendir($file)){
                            while (false !== ($entry = readdir($handle))) {
                                if ($entry != "." && $entry != "..") {
                                    $this->delete_files($file.'/'.$entry,$recursive);
                                }
                            }
                            closedir($handle);
                            @rmdir($file);
                        }
                    }
                }else if(is_file($file)){
                    @unlink($file);
                }
            }
        } catch (Exception $ex) {
            var_dump($ex->getMessage());
        }
    }

    /**
     *
     * @global type $GLOBALS
     * @staticvar boolean $instance_id
     * @return tenant_id as String else false;
     */
    public function _db_connect(){
        static $instance_id = false;
        global $GLOBALS;
        if($instance_id && $instance_id==$this->tenant_id){
            return $this->tenant_id;
        }else{
            if(strlen($this->tenant_name)==0){
                exit;
            }
            include_once(dirname(__FILE__).'/../../multitenant/Classes/dynamoDBClass.php');
            $dc = new dynamoDBClass();
            if(($data = $dc->get_data($this->tenant_id, 'tenant_id'))){
                $_SESSION['tenant_data'] = json_decode($data['Item']['data']['S'],true);
                $_SESSION['tenant_data']['host'] = $dc->db['host'];
                $_SESSION['tenant_data']['port'] = $dc->db['port'];
                $_SESSION['tenant_data']['dbase'] = $dc->db['name'];
                extract($_SESSION['tenant_data']);
            }else{
                exit;
            }

            include_once($this->srcdir."/sql.inc");

            if(!defined('ADODB_NEVER_PERSIST')){
                define('ADODB_NEVER_PERSIST',1);
            }
            $database = NewADOConnection("mysqli_log");
            $database->clientFlags = 128;
            $database->port = $port;
            $database->PConnect($host, $login, $pass, $dbase);
            $GLOBALS['adodb']['db'] = $database;
            $GLOBALS['dbh'] = $database->_connectionID;
            if(!$database->_connectionID){
                return false;
            }
            $this->tenant_id = $login;
            $instance_id = $this->tenant_id;
            return $login;
        }
    }

    /**
     *
     * @staticvar boolean $option_list
     * @param string $type
     * @param string $title
     * @param boolean $return_null_value
     * @return option id as Integer
     */
    public function _get_list_options($type,$title = "",$return_null_value = false){
        $table_list = array('roa_name'=>'drug_route','dosage_form'=>'drug_form','uom'=>'drug_units');
        static $option_list = false;
        if(!is_array($option_list)){
            $option_list = array('roa_name'=>array(),'dosage_form'=>array(),'uom'=>array());
        }
        if($title==""){
            $title = "NULL";
        }
        if(!isset($option_list{$type}{strtolower($title)}) || strlen($temp = ($option_list{$type}{strtolower($title)})) == 0 || ($temp==0 && $return_null_value)){
            $option_id = 0;
            if(strlen($title)>0){
                $row = sqlQuery("select option_id from list_options where list_id = ? and title = ?",array($table_list{$type},$title));
                if($row){
                    $option_id = $row['option_id'];
                }else if($return_null_value){
                    $null_title = "NULL2";
                    if(isset($option_list{$type}{strtolower($null_title)}) && strlen($temp = ($option_list{$type}{strtolower($null_title)})) > 0){
                        $option_id = $temp;
                    }else{
                        $row = sqlQuery("select option_id from tbase_list_options where list_id=? and (title is null or title='NULL' or title='' or title='Route of administration not applicable') order by option_id desc",array($table_list{$type}));
                        if($row){
                            $option_id = $row['option_id'];
                            $option_list{$type}{strtolower($null_title)} = $option_id;
                        }
                    }
                }
            }
            $option_list{$type}{strtolower($title)} = $option_id;
        }

        return $option_list{$type}{strtolower($title)};
    }

    /**
     *
     * @param String $date
     * @return Returns converted date as String else false
     */
    public function get_date_format($date){
        $result = false;
        $temp_list = array('jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec');
        if(preg_match('#^([0-9]{4})-([0-9]{2})-([0-9]{2})$#iu', $date, $matches)){
            $result = $date;
        }else if(preg_match('#^([0-9]{1,2})?[\s-/]?([a-z]{3,20}|[0-9]{1,2})[\s-/]?([0-9]{2,4})$#iu', $date, $matches)){
            if(intval($matches[2])>0){
                if(intval($matches[1])>0 && intval($matches[2])>12 && $matches[1]<=12){
                    $temp = $matches[2];
                    $matches[2] = $matches[1];
                    $matches[1] = $temp;
                }
                $matches[2] = $temp_list{intval($matches[2])-1};
            }else if(strlen($matches[2])>3 && ($temp = array_search(strtolower(substr($matches[2],0,3)), $temp_list))!==FALSE){
                $matches[2] = $temp;
            }
            if(strlen($matches[1])>0){
                $result = $matches[1].' '.$matches[2].' '.$matches[3];
            }else{
                $result = '01 '.$matches[2].' '.$matches[3];
                $result = date("Y-m-d",strtotime(date("Y-m-d",strtotime($result.' + 1 month')).' - 1 day'));
            }

        }
        if($result){
            $result = date("Y-m-d",strtotime($result));
        }
        return ($result!='1970-01-01')?$result:false;
    }

    /**
     *
     * @param type $size
     * @return type
     */
    public function drug_size_check($size=""){
        //drug size check
        $drug_size = 0;
        $drug_type = "";
        if(strlen($size)>0){
            if(preg_match('#^([0-9/\.]+)\s*([a-z%].+)?$#iu', $size,$matches)){
                if(isset($matches[1])){
                    $drug_size = $matches[1];
                }
                if(isset($matches[2])){
                    $drug_type = $matches[2];
                }
            }
        }
        if(strlen($drug_size)>0){
            $temp = explode(".",$drug_size);
            if(sizeof($temp)==2 && (int)$temp[1]==0){
                $drug_size = floor((float)$drug_size);
            }
        }
        return array($drug_size,$drug_type);
    }

    /**
     *
     * @param string $name
     * @param type $drug_size
     * @return string
     */
    public function drug_name_normalize($name="",$drug_size="", $drug_type=""){
        if(strlen($drug_size)>0 && $drug_size!="0"){
            if(stristr((string)$name, (string)$drug_size)===FALSE){
                $name = $name." ".$drug_size." ".$drug_type;
            }
        }
        return trim($name);
    }

    /**
     *
     * @param type $drug_name
     * @param type $drug_size
     * @return type
     */
    public function drug_exists_check($tenant_id='',$drug_name="",$drug_size=0,$uom='',$roa_name='',$dosage_form='',$entity_id='',$entity_name='',$entity_type='',$expire_date='',$mims_id,$mims_drug,$drug_type,$facility_id,$return_md5 = false){
        static $column_status = false;
        if(!$column_status){
            $column_status = array();
            if(sqlQuery('SELECT * FROM information_schema.columns WHERE table_schema="'.$this->db['name'].'" AND table_name="tbase_drugs" AND column_name="facility_id" LIMIT 1')){
                $column_status['facility_id'] = true;
            }
        }
        $drug_size = max(0,$drug_size);
        $insert_data = $this->drug_option_check($dosage_form,$uom,$roa_name,$drug_type,$mims_id,$mims_drug);
        $dosage_form = $insert_data['dosage_form'];
        $uom = $insert_data['uom'];
        $roa_name = $insert_data['roa_name'];
        if($return_md5){
            return md5(strtolower($tenant_id."-".$drug_name."-".$drug_size."-".$uom."-".$roa_name."-".$dosage_form."-".$entity_id."-".$entity_name."-".$entity_type."-".$expire_date));
        }
        $return = 0;

        $temp = trim(preg_replace('#'.$drug_type.'$#iu','',trim($drug_name)));
        $extra_filter = array("d.tenant_id = ?","name LIKE ?");
        $extra_filter_bind_value = array($tenant_id,$temp.'%');

        foreach(array('dosage_form'=>'form','uom'=>'unit'/*,'roa_name'=>'route'*/) as $type => $v){
            if(strlen($temp = ${$type})>0){
                $extra_filter[] = sprintf("d.%s = ?",$v);
                $extra_filter_bind_value[] = $temp;
            }
        }
        /*
        if(strlen($entity_id)>0){
            list($mims_drug,$mims_id) = $this->drug_mapping_check_byId($entity_id);
            if($mims_id>0){
                $extra_filter[] = "tdmdm.entity_id = ?";
                $extra_filter_bind_value[] = $entity_id;
            }
        }
        if(strlen($expire_date)>0 && ($temp = $this->get_date_format($expire_date))!==FALSE){
            $extra_filter[] = "di.expiration = ?";
            $extra_filter_bind_value[] = $temp;
        }
        */
        if(strlen($facility_id)>0 && isset($column_status['facility_id'])){
            $extra_filter[] = "d.facility_id = ?";
            $extra_filter_bind_value[] = $facility_id;
        }
        $query = "SELECT d.drug_id FROM drugs d
            LEFT JOIN drugs_mims_drugs_map tdmdm ON d.tenant_id=tdmdm.tenant_id AND d.drug_id=tdmdm.drug_id
            LEFT JOIN drug_inventory di ON d.tenant_id=di.tenant_id AND d.drug_id=di.drug_id
            WHERE ".implode(' AND ',$extra_filter);
        $row = sqlQuery($query, $extra_filter_bind_value);
        if($row){
            $return = $row['drug_id'];
        }
        return $return;
    }

    public function drug_exists_check_byId($drug_id=""){
        $return = 0;
        $row = sqlQuery("SELECT * FROM drugs WHERE drug_id = ?", array($drug_id));
        if($row){
            $return = $row['drug_id'];
        }
        return $return;
    }

    /**
     *
     * @param type $expire_date
     * @return boolean
     */
    public function drug_expire_check($expire_date=""){
        $return = true;
        if(strlen($expire_date)>0 && ($temp = $this->get_date_format($expire_date))===FALSE){
            $return = false;
        }
        return $return;
    }

    /**
     *
     * @param type $mapping_name
     * @param type $mapping_type
     * @return type
     */
    public function drug_mapping_check($mapping_name="",$mapping_type=""){
        $mims_drug = false;
        $mims_id = 0;
        $temp = str_replace(' ', '', strtolower($mapping_type));
        $allow_type = array('acg'=>'("ACG")','product'=>'("Product")','vp'=>'("VP","VirtualProduct")','virtualproduct'=>'("VP","VirtualProduct")');
        if(strlen($mapping_name)>0 && isset($allow_type[$temp])){
            $row = sqlQuery('SELECT * FROM mims_drugs WHERE entity_name = ? and entity_type IN '.$allow_type[$temp], array($mapping_name));
            if($row){
                $mims_drug = $row;
                $mims_id = $row['id'];
            }
        }
        return array($mims_drug,$mims_id);
    }

    public function drug_mapping_check_byId($mapping_entity_id="",$roa_name=""){
        $mims_drug = false;
        $mims_id = 0;

        if(strlen($mapping_entity_id)>0){
            $roa_name_query = "";
            if(strlen($roa_name)>0){
                $roa_name_query = ' AND roa_name="'.addslashes($roa_name).'"';
            }
            $row = sqlQuery('SELECT * FROM mims_drugs WHERE entity_id = ?'.$roa_name_query, array($mapping_entity_id));
            if($row){
                $mims_drug = $row;
                $mims_id = $row['id'];
            }
        }
        return array($mims_drug,$mims_id);
    }

    public function drug_option_check($dosage_form="",$uom="",$roa_name="",$drug_type="",$mims_id=0,$mims_drug=false){
        $insert_data = array('dosage_form'=>$dosage_form,'uom'=>$uom,'roa_name'=>$roa_name);
        foreach($insert_data as $type => $v){
            $temp = 0;
            if(strlen($v)>0){
                if(strlen($temp = ($this->_get_list_options($type,$v))) == 0){
                    $temp = 0;
                }
            }
            if($temp==0 && $mims_id>0 && strlen($mims_drug{$type})>0){
                if(strlen($temp = ($this->_get_list_options($type,$mims_drug{$type}))) == 0){
                    $temp = 0;
                }
            }
            if($type=="uom" && $temp==0 && strlen($drug_type)>0){
                if(strlen($temp = ($this->_get_list_options($type,$drug_type))) == 0){
                    $temp = 0;
                }
            }
            if($temp==0){
                if(strlen($temp = ($this->_get_list_options($type,"",true))) == 0){
                    $temp = 0;
                }
            }
            $insert_data{$type} = $temp;
        }
        return $insert_data;
    }

    /**
     *
     * @param type $drug_id
     * @param type $drug_size
     * @param type $drug_type
     * @param type $mims_id
     * @param type $mims_drug
     * @param type $dosage_form
     * @param type $oum
     * @param type $roa_name
     * @param type $drug_code
     * @param type $drug_name
     * @return type
     */
    public function drug_insert($drug_id = 0,$drug_size = 0,$drug_type="",$mims_id=0,$mims_drug=false,$dosage_form="",$uom="",$roa_name="",$drug_code="",$drug_name="",$facility_id=''){
        $return = false;
        $insert_data = $this->drug_option_check($dosage_form,$uom,$roa_name,$drug_type,$mims_id,$mims_drug);
        static $column_status = false;
        if(!$column_status){
            $column_status = array();
            if(sqlQuery('SELECT * FROM information_schema.columns WHERE table_schema="'.$this->db['name'].'" AND table_name="tbase_drugs" AND column_name="facility_id" LIMIT 1')){
                $column_status['facility_id'] = true;
            }
        }

        if($drug_id>0){
            sqlStatement('UPDATE drugs set form = ?, size = ?, unit = ?, route = ?, active = ?, allow_multiple = ? WHERE drug_id = ? limit 1',array($insert_data['dosage_form'],$drug_size,$insert_data['uom'],$insert_data['roa_name'],'1','1',$drug_id));
            $return = $drug_id;
        }else{
            if(strlen($drug_code)>0){
                $drug_code = $drug_code;
            }else{
                $row = sqlQuery("SELECT max(ifnull(ndc_number,0)) last_code FROM drugs");
                if($row){
                    $temp = intval(preg_replace('#[^0-9]#iu', '', $row['last_code'])) + 1;
                    $drug_code = sprintf('D%05d',$temp);
                }
            }
            if(isset($column_status['facility_id'])){
                $drug_id = sqlInsert("INSERT INTO drugs (name,ndc_number,form,size,unit,route,active,allow_multiple,facility_id) VALUES (?,?,?,?,?,?,?,?,?)", array($drug_name,$drug_code,$insert_data['dosage_form'],$drug_size,$insert_data['uom'],$insert_data['roa_name'],'1','1',$facility_id));
            }else{
                $drug_id = sqlInsert("INSERT INTO drugs (name,ndc_number,form,size,unit,route,active,allow_multiple) VALUES (?,?,?,?,?,?,?,?)", array($drug_name,$drug_code,$insert_data['dosage_form'],$drug_size,$insert_data['uom'],$insert_data['roa_name'],'1','1'));
            }
            $return = $drug_id;
        }
        return $return;
    }

    /**
     *
     * @param type $mims_id
     * @param type $drug_id
     * @return type
     */
    public function drug_mapping_insert($mims_id=0,$drug_id=0,$entity_id=""){
        $return = false;
        static $column_status = false;
        if(!$column_status){
            $column_status = array();
            if(sqlQuery('SELECT * FROM information_schema.columns WHERE table_schema="'.$this->db['name'].'" AND table_name="tbase_drugs_mims_drugs_map" AND column_name="entity_id" LIMIT 1')){
                $column_status['entity_id'] = true;
            }
        }

        //for checking the column in map table
        if(!isset($this->table_map_include_entity_id)){
            if(isset($column_status['entity_id'])){
                $this->table_map_include_entity_id = true;
            }else{
                $this->table_map_include_entity_id = false;
            }
        }
        if($mims_id>0 && $drug_id>0 && strlen($entity_id)>0){
            if(($row2 = sqlQuery("SELECT d.roa_name FROM mims_drugs d WHERE d.id=? LIMIT 1", $mims_id))){
                if(strlen($temp = ($this->_get_list_options('roa_name',$row2['roa_name'])))>0 && $temp>0){
                    sqlStatement('UPDATE drugs set route = ? WHERE drug_id = ? limit 1',array($temp,$drug_id));
                }
            }
            sqlStatement("DELETE FROM drugs_mims_drugs_map WHERE drug_id = ?", array($drug_id));
            if($this->table_map_include_entity_id){
                $return = sqlInsert("INSERT INTO drugs_mims_drugs_map (drug_id,mims_drugs_id,entity_id,status) VALUES (?,?,?,?)", array($drug_id,$mims_id,$entity_id,'active'));
            }else{
                $return = sqlInsert("INSERT INTO drugs_mims_drugs_map (drug_id,mims_drugs_id,status) VALUES (?,?,?)", array($drug_id,$mims_id,'active'));
            }
        }
        return $return;
    }

    /**
     *
     * @param type $drug_id
     * @param type $drug_name
     * @param type $tax_rate
     * @return type
     */
    public function drug_template_update($drug_id=0,$drug_name="",$tax_rate="",$dosage="",$period=0,$quantity=0,$patient_instruction=""){
        $return = false;
        if($drug_id>0){
            if($period==""){
                $period = 0;
            }
            if(!is_numeric($period)){
                if(($row = sqlQuery("SELECT option_id FROM list_options WHERE list_id='drug_interval' AND title=? ", array($period)))){
                    $period = $row['option_id'];
                }
            }
            $row = sqlQuery("SELECT * FROM drug_templates WHERE drug_id = ? and selector = ?", array($drug_id,$drug_name));
            if(!$row){
                $return = sqlInsert("INSERT INTO drug_templates (drug_id,selector,dosage,period,quantity,refills,patient_instruction,taxrates) VALUES (?,?,?,?,?,?,?,?)", array($drug_id,$drug_name,$dosage,$period,$quantity,0,$patient_instruction,$tax_rate));
            }else{
                $return = sqlStatement('UPDATE drug_templates set dosage=?,period=?,quantity=?,patient_instruction=? WHERE drug_id = ? limit 1',array($dosage,$period,$quantity,$patient_instruction,$drug_id));
            }
        }
        return $return;
    }

    /**
     *
     * @param type $drug_id
     * @param type $unit_cost
     * @param type $insurance
     * @param type $selfpay
     * @param type $drug_name
     * @return type
     */
    public function drug_price_update($drug_id=0,$unit_cost=0,$insurance=0,$selfpay=0,$drug_name=""){
        $return = false;
        $price_list = array('Insurance/Corporate'=>floatval($insurance),'Selfpay'=>floatval($selfpay));
        if($drug_id>0){
            $pricelevel_list = array();
            $results = sqlStatement("SELECT option_id FROM list_options WHERE list_id=? group by option_id",array('pricelevel'));
            while($row = sqlFetchArray($results)){
                $pricelevel_list[$row['option_id']] = $row['option_id'];
            }
            foreach($pricelevel_list as $level){
                $selling_price = 0;
                if(floatval($unit_cost)>0){
                    $selling_price = $unit_cost;
                }
                if($price_list[$level]>0){
                    $selling_price = $price_list[$level];
                }
                $row = sqlQuery("SELECT pr_id FROM prices WHERE pr_id = ? and pr_selector = ? and pr_level = ? and is_drug=1", array($drug_id,$drug_name,$level));
                if(!$row){
                    $return = sqlInsert("INSERT INTO prices (pr_id,pr_selector,pr_level,pr_price,is_drug) VALUES (?,?,?,?,1)", array($drug_id,$drug_name,$level,floatval($selling_price)));
                }else{
                    $return = sqlStatement('UPDATE prices set pr_price = ? WHERE pr_id = ? and pr_selector = ? and pr_level = ? and is_drug=1 limit 1',array(floatval($selling_price),$drug_id,$drug_name,$level));
                }
            }
        }
        return $return;
    }

    /**
     *
     * @param type $vendor
     * @return type
     */
    public function drug_supplier_get($vendor=""){
        $vendor_id = 0;
        if(strlen($vendor)>0){
            $row = sqlQuery("SELECT * FROM users WHERE organization = ? and abook_type = ? limit 1", array($vendor,'vendor'));
            if($row){
                $vendor_id = $row['id'];
            }else{
                $vendor_id = sqlInsert("INSERT INTO users (username,password,authorized,facility_id,see_auth,active,organization,taxonomy,abook_type) VALUES (?,?,?,?,?,?,?,?,?)", array('','','0','0','0','1',$vendor,'','vendor'));
            }
        }
        return $vendor_id;
    }

    /**
     *
     * @param type $drug_id
     * @param type $vendor_id
     * @param type $warehouse
     * @param type $quantity
     * @param type $expire_date
     * @param type $lot_number
     * @param type $quantity_increment
     * @return type
     */
    public function drug_inventory_update($drug_id=0,$vendor_id=0,$warehouse="",$quantity=0,$expire_date="",$lot_number="",$quantity_increment=false,$facility_id=''){
        static $column_status = false;
        if(!$column_status){
            $column_status = array();
            if(sqlQuery('SELECT * FROM information_schema.columns WHERE table_schema="'.$this->db['name'].'" AND table_name="tbase_drug_inventory" AND column_name="facility_id" LIMIT 1')){
                $column_status['tbase_drug_inventory.facility_id'] = true;
            }
        }

        $drug_expire = date("Y-m-d",strtotime('+ 1 year'));
        if(strlen($expire_date)>0 && ($temp = $this->get_date_format($expire_date))!==FALSE){
            $drug_expire = $temp;
        }
        $lot_number = 'D'.$drug_id.'_'.date("Ymd",strtotime($drug_expire));

        $quantity_on_hand = 0;
        $inventory_id = 0;
        if($drug_id>0){
            $warehouse_id = 'Main Store';
            if(strlen($warehouse)>0){
                $warehouse_id = $warehouse;
            }
            $extra_filter = array("drug_id = ?","warehouse_id = ?","expiration = ?");
            $extra_filter_bind_value = array($drug_id,$warehouse_id,$drug_expire);
            if(strlen($facility_id)>0 && isset($column_status['tbase_drug_inventory.facility_id'])){
                $extra_filter[] = "facility_id = ?";
                $extra_filter_bind_value[] = $facility_id;
            }
            $row = sqlQuery("SELECT * FROM drug_inventory WHERE ".implode(" AND ",$extra_filter)." limit 1", $extra_filter_bind_value);
            if($row){
                $quantity_on_hand = $row['on_hand'];
                $inventory_id = $row['inventory_id'];
                if($row['vendor_id']!=$vendor_id){
                    sqlStatement('UPDATE drug_inventory set vendor_id = ? WHERE '.implode(" AND ",$extra_filter).' limit 1', array_merge(array($vendor_id),$extra_filter_bind_value));
                }
            }else{
                if(isset($column_status['tbase_drug_inventory.facility_id'])){
                    $inventory_id = sqlInsert("INSERT INTO drug_inventory (drug_id, lot_number, manufacturer, expiration, vendor_id, warehouse_id, on_hand, facility_id) VALUES (?,?,?,?,?,?,?,?)", array($drug_id,$lot_number,'','',$vendor_id,$warehouse_id,$quantity_on_hand,$facility_id));
                }else{
                    $inventory_id = sqlInsert("INSERT INTO drug_inventory (drug_id, lot_number, manufacturer, expiration, vendor_id, warehouse_id, on_hand) VALUES (?,?,?,?,?,?,?)", array($drug_id,$lot_number,'','',$vendor_id,$warehouse_id,$quantity_on_hand));
                }
            }

            if(floatval($quantity)>0){
                if($quantity_increment){
                    $quantity_on_hand = $quantity_on_hand + floatval($quantity);
                }else{
                    $quantity_on_hand = floatval($quantity);
                }
            }else{
                $this->_excel_data_set('quantity',0);
            }

            sqlStatement('UPDATE drug_inventory set on_hand = ?, expiration = ? WHERE inventory_id = ? limit 1',array($quantity_on_hand,$drug_expire,$inventory_id));
        }
        return $inventory_id;
    }

    /**
     *
     * @param type $drug_id
     * @param type $inventory_id
     * @param type $unit_cost
     * @param type $total_cost
     * @param type $quantity
     * @return type
     */
    public function drug_sales_update($drug_id=0,$inventory_id=0,$unit_cost=0,$total_cost=0,$quantity=0){
        $return = false;
        if($drug_id>0 && $inventory_id>0){
            if(strlen($unit_cost)>0 && floatval($quantity)>0){
                $total_cost = floatval($unit_cost) * floatval($quantity);
            }
            $return = sqlInsert("INSERT INTO drug_sales (drug_id, inventory_id, prescription_id, pid, encounter, user, sale_date, quantity, fee, xfer_inventory_id, distributor_id, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)", array($drug_id,$inventory_id,0,0,0,'',date('Y-m-d'),(0 - floatval($quantity)),(0 - $total_cost),0,0,''));
        }
        return $return;
    }

    /**
     *
     * @param type $file_path
     * @param type $type
     */
    public function insert_upload_version($file_path,$type){
        //insert version
        $update_by = "";
        if(!empty($_SESSION['authUser'])){
            $update_by = $_SESSION['authUser'];
        }else if(!empty($_SESSION['user']['user_name'])){
            $update_by = $_SESSION['user']['user_name'];
        }
        $query = 'INSERT INTO tbase_upload_version(version,update_type,update_by,update_at,tenant_id) VALUES("'.basename($file_path).'","'.$type.'","'.$update_by.'","'.date("Y-m-d H:i:s").'","'.$this->tenant_id.'")';
        $this->_db_execute($query);
        $id = mysqli_insert_id($this->_db_conn());
        if($id>0){
            $query = sprintf('UPDATE tbase_upload_version SET tenant_id="%s" WHERE id="%s"',$this->tenant_id,$id);
            $this->_db_execute($query);
        }
    }

    /**
     *
     * @param String $message
     * @param String $type
     * @return data as array
     */
    private function error_handler($message,$type=""){
        //$is_ajax = (isset($_GET['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'));
        $temp = array('type'=>$type,'message'=>$message);
        $this->add_error($temp);

        if(!$is_ajax && $type=="excel_upload_error"){
            echo'<script type="text/javascript">var '.$type.' = "'.$message.'";</script>';
        }

        return $temp;
    }

    /**
     * Add error array into error_list for page render used.
     *
     * @param type $array
     */
    private function add_error($array){
        if(!isset($this->error_list)){
            $this->error_list = array();
        }
        $temp = json_encode($array);
        $this->error_list[$temp] = $array;
    }

    /**
     *
     * @param type $column
     */
    public function _excel_column_set($column){
        $this->main_column_num = $column;
    }

    /**
     *
     * @param type $data
     * @param type $value
     */
    public function _excel_data_set($data,$value = ""){
        if(is_array($data)){
            $this->main_data = $data;
        }else if(is_string($data) && isset($this->main_column_num[$data]) && isset($this->main_data[$this->main_column_num[$data]])){
            $this->main_data[$this->main_column_num[$data]] = $value;
        }
    }

    /**
     *
     * @return type
     */
    public function _excel_column_get(){
        if(isset($this->main_column_num)){
            return $this->main_column_num;
        }
        return array();
    }

    /**
     *
     * @param type $data
     * @return type
     */
    public function _excel_data_get($data = ""){
        if(isset($this->main_data)){
            if(strlen($data)>0 && isset($this->main_column_num[$data]) && isset($this->main_data[$this->main_column_num[$data]])){
                return $this->main_data[$this->main_column_num[$data]];
            }
            return $this->main_data;
        }
        return array();
    }

    /**
     *
     * @param Array $data
     * @param String $header
     * @return String
     */
    public function _excel_get_column_data($header="", $default=""){
        $header = strtolower($header);
        $return = $default;
        if(isset($this->main_column_num) && isset($this->main_data) && strlen($header)>0 && isset($this->main_column_num[$header]) && isset($this->main_data[$this->main_column_num[$header]])){
            $return = $this->main_data[$this->main_column_num[$header]];
        }
        return $return;
    }

    /**
     *
     * @param type $header_list
     * @param type $data
     * @return type
     */
    public function _excel_set_header($header_list,$data=false){
        if(!$data){
            $data = $this->_excel_data_get();
        }
        $temp = array();
        foreach($header_list as $key => $value){
            foreach($data as $key2 => $value2){
                if(strtolower($value)==strtolower($value2)){
                    $temp{strtolower($key)} = $key2;
                    break;
                }
            }
        }
        $this->_excel_column_set($temp);
        return $temp;
    }
}
?>
