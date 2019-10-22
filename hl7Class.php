<?php
require_once(dirname(__FILE__).'/drugMainClass.php');

class hl7Class extends drugMainClass{

    public function Parser_HL7v2($message){
        if(substr($message,0,3)!="MSH"){
            return false;
        }

        $temp = preg_split("/(\r\n|\n|\r)/", $message);
        $temp2 = array();
        foreach($temp as $row){
            $col = explode("|",$row);
            if(!isset($temp2[$col[0]])){
                $temp2[$col[0]] = array();
            }
            $temp2[$col[0]][] = $col;
        }

        $temp = array();
        foreach($temp2 as $key => $v1){
            foreach($v1 as $value){
                if($key=="MSH"){
                    if(($date = DateTime::createFromFormat('YmdHis', $value[6]))){
                        $date = $date->format('Y-m-d H:i:s');
                    }else{
                        $date = date("Y-m-d H:i:s");
                    }
                    $temp['MSH'] = array(
                        'message_date'=>  $date,
                        'message_application'=> $value[2],
                        'message_facility'=> $value[3],
                        'message_type'=> $value[8],
                        'message_control_id'=> $value[9],
                        'message_charset'=> $value[17]
                    );
                }else if($key=="PID"){
                    $t = explode("^",$value[3]);
                    $temp['PID'] = array(
                        'pid'=>  $t[0],
                        'name'=> $value[5],
                    );
                }else if($key=="OBR"){
                    if(($date = DateTime::createFromFormat('YmdHis', $value[7]))){
                        $date = $date->format('Y-m-d H:i:s');
                    }else{
                        $date = date("Y-m-d H:i:s");
                    }
                    $temp['OBR'] = array(
                        'observation_date'=>  $date,
                        'display_date'=> date("d/m/Y H:i:s",strtotime($date)),
                        'encounter'=> $value[3],
                        'clinician'=> $value[32],
                    );
                }else if($key=="OBX"){
                    if(!isset($temp['OBX'])){
                        $temp['OBX'] = array('remarks'=>array(),'modules'=>array(),'images'=>array(),'messages'=>array());
                    }
                    $t = explode("^",$value[3]);
                    $code = trim($t[0]);
                    $title = trim($t[1]);
                    if($value[2]=="IS" && $value[5]=="T"){
                        $temp['OBX']['messages'][] = $title;
                    }else if($value[2]=="NM" && $value[5]!="" && $value[6]!="" && $value[7]!=""){
                        $t = array_intersect(explode("~",$value[8]), array('L','H'));
                        $t = ((!empty($t[0]))?$t[0]:"");
                        $temp['OBX']['modules'][$title] = array('name'=>$title,'result'=>$value[5],'unit'=>  str_replace("*", "^", $value[6]),'flag'=>$t,'range'=>$value[7]);
                    }else if($value[2]=="ST" && $title=="Remark"){
                        $temp['OBX']['remarks'][] = $value[5];
                    }else if($value[2]=="ED" && ($t = explode("^",$value[5])) && sizeof($t)==5 && $t[3]=="Base64"){
                        $temp['OBX']['images'][$title] = $t[4];
                    }
                }
            }
        }

        if(!empty($temp['OBX']['modules'])){
            $temp['OBX']['modules'] = hl7_config_mindray20::get_modules($temp['OBX']['modules']);
        }

        return $temp;
    }

    public function insert_record($tenant_id,$pid,$encounter,$message,$observe_date = ""){
        $filename = md5($message)."_".date("YmdHis").".hl7";
        if(!($patient_info = $this->_db_query("SELECT pubpid FROM tbase_patient_data WHERE tenant_id=? and pid=? ", array($tenant_id,$pid)))){
            return false;
        }
        $location = 'patient_documents/'.$tenant_id.'/'.$patient_info[0]['pubpid'].'/hl7/'.$filename;

        require_once(dirname(__FILE__).'/s3Class.php');
        $s3Class = new s3Class;
        $s3Class->bucket_put_data($message, $location);

        if($observe_date==""){
            $observe_date = date("Y-m-d H;i:s");
        }
        $this->_db_insert('INSERT INTO tbase_patient_hl7_data SET pid=?, encounter=?, filename=?, observe_date=?, tenant_id=?', array($pid,$encounter,$filename,$observe_date,$tenant_id));
        return true;
    }

    public function get_list($tenant_id,$pid,$include_inactive = false){
        $where = "";
        if(!$include_inactive){
            $where = " AND b.encounterstatus=1 ";
        }
        return $this->_db_query('SELECT a.id,observe_date,ifnull(b.encounter,"") encounter,ifnull(b.date,"") enc_date FROM tbase_patient_hl7_data a left join tbase_form_encounter b on b.tenant_id=a.tenant_id and b.pid=a.pid and b.encounter=a.encounter WHERE a.tenant_id=? AND a.pid=? '.$where.' ORDER BY a.created_at DESC', array($tenant_id,$pid));
    }

    public function get_record($id){
        if($result = $this->_db_query('SELECT filename,tenant_id,pid FROM tbase_patient_hl7_data WHERE id=? LIMIT 1', array($id))){
            if(!($patient_info = $this->_db_query("SELECT pubpid FROM tbase_patient_data WHERE tenant_id=? and pid=? ", array($result[0]['tenant_id'],$result[0]['pid'])))){
                return false;
            }
            $location = 'patient_documents/'.$_SESSION['tenant_data']['login'].'/'.$patient_info[0]['pubpid'].'/hl7/'.$result[0]['filename'];

            require_once(dirname(__FILE__).'/s3Class.php');
            $s3Class = new s3Class;
            if(($data = $s3Class->bucket_get($location))){
                return $this->Parser_HL7v2($data);
            }
        }
        return false;
    }

}

class hl7_config_mindray20{
    static public function get_modules($data){
        $default = array(
            'WBC' => array('WBC','LYM#','MID#','GRAN#','LYM%','MID%','GRAN%'),
            'RBC' => array('RBC','HGB','HCT','MCV','MCH','MCHC','RDW-CV','RDW-SD'),
            'PLT' => array('PLT','MPV','PDW','PCT')
        );
        $temp = array();
        foreach($default as $k => $v){
            if(empty($temp[$k])){
                $temp[$k] = array();
            }
            foreach($v as $b){
                if(!empty($data[$b])){
                    $temp[$k][$b] = $data[$b];
                    unset($data[$b]);
                }
            }
        }
        $temp['OTH'] = array();
        foreach($data as $k => $v){
            $temp['OTH'][$k] = $v;
        }
        return $temp;
    }
}
