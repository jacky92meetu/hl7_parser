<?php

require_once (dirname(__FILE__)."/../multitenant/Classes/hl7Class.php");
$class = new hl7Class();

if(isset($_REQUEST['tenant_id']) && strlen($_REQUEST['tenant_id'])>0){
    /*
    $date = date("Y-m-d",strtotime("-2 days"));
    $ip_address = strtolower($_SERVER['REMOTE_ADDR']);
    if(array_search($ip_address,array('localhost','127.0.0.1','::1'))===FALSE && !$class->_db_query('SELECT * FROM tbase_login_log WHERE tenant_id=? AND date>=? AND ip_address=? LIMIT 1', array($_REQUEST['tenant_id'],$date,$ip_address))){
        exit;
    }
    */

    $data = file_get_contents('php://input');
    if(isset($_REQUEST['type']) && $_REQUEST['type']=="get_enc"){
        try{
            $temp = json_decode($data,true);
            if(is_array($temp) && isset($temp['enc_id']) && strlen($temp['enc_id'])>0){
                if(($result = $class->_db_query('SELECT b.pubpid as patient_id, a.encounter, b.lname, b.fname, b.title as patient_title, if(ifnull(b.dob,"")<>"",b.dob,"") as patient_dob, b.sex as patient_sex, concat(c.fname," ",c.lname) as clinician, a.reason
                    FROM tbase_form_encounter a
                    join tbase_patient_data b on a.tenant_id=b.tenant_id and a.pid=b.pid
                    left join tbase_users c on a.tenant_id=c.tenant_id and a.provider_id=c.id
                    where a.encounter=? and a.tenant_id=? limit 1',array($temp['enc_id'],$_REQUEST['tenant_id'])))){
                    $temp_data = array();
                    foreach($result as $row){
                        $temp_data['patient_encounter'] = $row['encounter'];
                        $temp_data['patient_id'] = $row['patient_id'];
                        $temp_data['patient_title'] = strtoupper(trim($row['patient_title']));
                        $temp_data['patient_lname'] = strtoupper(trim($row['lname']));
                        $temp_data['patient_fname'] = strtoupper(trim($row['fname']));
                        $temp_data['patient_dob'] = (strlen($row['patient_dob'])>0?date("YmdHis",strtotime($row['patient_dob'])):"");
                        $temp_data['patient_sex'] = (strtoupper(substr($row['patient_sex'],0,1))=="M"?"Male":"Female");
                        $temp_data['patient_clinician'] = strtoupper(trim($row['clinician']));
                        $temp_data['patient_comments'] = trim($row['reason']);
                    }
                    echo json_encode($temp_data);
                }
            }
        } catch (Exception $ex) {

        }
        exit;
    }

    /*save hl7 message*/
    $message = $class->Parser_HL7v2($data);
    if(!empty($message['PID']) && !empty($message['PID']['pid'])){
        $t = explode("-",$message['PID']['pid']);
        $pid = array_pop($t);
        if($class->_db_query('SELECT * FROM tbase_form_encounter WHERE tenant_id=? AND pid=? AND encounter=? LIMIT 1', array($_REQUEST['tenant_id'],$pid,$message['OBR']['encounter']))){
            if(!$class->_db_query('SELECT * FROM tbase_patient_hl7_data WHERE tenant_id=? AND pid=? AND observe_date=? LIMIT 1', array($_REQUEST['tenant_id'],$pid,$message['OBR']['observation_date']))){
                $class->insert_record($_REQUEST['tenant_id'], $pid, $message['OBR']['encounter'], $data, ((!empty($message['OBR']['observation_date']))?$message['OBR']['observation_date']:""));
            }
            echo "success";
            exit;
        }
    }

}
