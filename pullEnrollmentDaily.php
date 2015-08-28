<?php
//include ("../../../em/config/config.php");
/* 
 * file: pullEnrollmentDaily.php
 * class: processEnrollment
 * author: Greg London
 * version: 1.0
 * 
 * This class grabs data from the following day (Yesterday), then checks to make sure a uniquie recipient_id is found. 
 * If a unique id is found, we check to see if it's already in the database or not.
 * The two tables we are concerned with are 1994_cars & 1994_servicestats, After checks are complete & data inserted 
 * the class will generate a report to be emailed to authorized personnel. 
 * 
 */
set_time_limit(300);
ini_set('memory_limit', '256M');

define("ADURL", '****.com');
define("ADUSERNAME", "");
define("ADPASSWORD", "");
define("ADDATABASE", "");

// testing for now - data removed for security purposes..
$dbhost = '';
$dbuser = '';
$dbpass = '';
$dbname = '';
$conn = mysql_connect($dbhost, $dbuser, $dbpass) or die ('Error connecting to the database');
$db = mysql_select_db($dbname);

ob_start();

class processEnrollment {
    
    private $adLink = '';
    protected $shopDate = '';
    protected $userId = '';
    protected $groupId = '';
    public $existingPC = 0;
    public $existingPCNewCar = 0;
    public $newPC = 0;
    
    function __construct() {
        $this->adLink = $mysqli = new mysqli(ADURL, ADUSERNAME, ADPASSWORD, ADDATABASE);
        if ($mysqli->connect_errno) {
            echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
        }
        $this->shopDate = date('Y-m-d 00:00:00',strtotime("-1 days"));
        $this->userId = ****;
        $this->groupId = ****;
    }
    
    function findRecipientId($email){
        $toReturn = 0;
        $sql = "SELECT * FROM recipients WHERE user_id = 1994 AND email like '".mysql_real_escape_string($email)."'";
        #echo $sql;
        $result = mysql_query($sql);
        if(mysql_num_rows($result) >= 1){
            $data = mysql_fetch_array($result);
            $toReturn = $data['id'];
        }
        return $toReturn;
    }
    
    function findRecipientByVin($vin){
        $toReturn = 0;
        $sql = "SELECT recipient_id FROM 1994_cars WHERE vin = '".$vin."'";
        $result = mysql_query($sql);
        if(mysql_num_rows($result) >= 1){
            $data = mysql_fetch_array($result);
            $toReturn = $data['recipient_id'];
        }
        return $toReturn;
    }
    
    function findCarIdByVin($vin){
        $toReturn = 0;
        $sql = "SELECT id FROM 1994_cars WHERE vin = '".$vin."'";
        $result = mysql_query($sql);
        if(mysql_num_rows($result) >= 1){
            $data = mysql_fetch_array($result);
            $toReturn = $data['id'];
        }
        return $toReturn;
    }
    
    /**
     * 
     * @param type $em_recipient_id
     * @return type
     * 
     * Finds car_id based on recipiend_id, returns 0 if nothing found...
     * 
     */
    function findCarIdByRecipient($em_recipient_id){
        $toReturn = 0;
        $sql = "SELECT id FROM 1994_cars WHERE recipient_id = ".$em_recipient_id."";
        $result = mysql_query($sql);
        if(mysql_num_rows($result) >= 1){
            $data = mysql_fetch_array($result);
            $toReturn = $data['id'];
        }
        return $toReturn;
    }
    
    function pullServiceDetailsFromAuto($vin){
        $recipient_id = 0; // we want to return the recipient_id with success
        $car_id = 0;
        
        $sql = "SELECT cd.AcctNum, cd.VIN, cd.FirstName, cd.LastName, cd.Email, cd.Street, cd.Zip, cd.PhoneNum, cd.LastOil, cd.`Year`, cv.mileage, cv.`Date`, cv.StoreNum, cv.ReceiptNum, cv.Services, cv.receipt 
                FROM customerdetail cd 
                JOIN customervisits cv 
                ON cd.AcctNum = cv.AcctNum 
                WHERE cd.VIN = '".$vin."' 
                AND cv.Services LIKE '%F%'
                ORDER BY cv.`Date` DESC LIMIT 5";
        $result = mysqli_query($this->adLink, $sql);
       
        while($data = mysqli_fetch_array($result)){
            if($recipient_id == 0){
                $recipient_id = $this->createRecipient($data['Email'], $data['FirstName'], $data['LastName']);
            }
            if($car_id == 0){
                $details = $this->parseReceipt($data['receipt']);
                $car_id = $this->createCar($recipient_id, $vin, $details['make'], $details['model'], $data['Year']);
            }
            
            if($recipient_id == 0 || $car_id == 0){
                echo "Error adding recipient/car (".$data['Email'].")<br/>";
            }
            
            $this->addService($car_id, $data['mileage'], $data['LastOil'], $data['Date'], $data['StoreNum'], $data['ReceiptNum']);
        }
        
        if($recipient_id > 0){
            $updateFields = $this->updateFields($recipient_id);
            $this->updateDates($car_id);
            if($updateFields){
                return $recipient_id;
            } else {
                return false;
            }  
        }
        else{
            echo "No full service found for this customer<br/>";
            return false;
        }
    }
    // do not need...
    function parseReceipt($receipt){
        $toReturn = array("make"=>'',"model"=>'');
        $lines = explode("\n",$receipt);
        foreach($lines as $line){
            $pieces = explode("=",$line);
            if($pieces[0] == 'Make'){
                $toReturn['make'] = $pieces[1];
            }
            if($pieces[0] == 'Model'){
                $toReturn['model'] = $pieces[1];
            }
        }
        
        return $toReturn;
    }
    
    function createRecipient($email, $first_name, $last_name){
        $recipient_id = 0;
        
        $sql = "SELECT * FROM recipients WHERE email like '".mysql_real_escape_string($email)."' AND user_id = ".$this->userId;
        echo  $sql;
        $result = mysql_query($sql);
        if(mysql_num_rows($result) == 0){
            // insert
            $isql = "INSERT INTO recipients (user_id, group_id, email, first_name, last_name, created, signup_complete) VALUES "
                    . " (".$this->userId.",".$this->groupId.",'".mysql_real_escape_string($email)."','".mysql_real_escape_string($first_name)."',"
                    . " '".mysql_real_escape_string($last_name)."',NOW(),1)";
            echo $isql;
            $iresult = mysql_query($isql);
            $recipient_id = mysql_insert_id();
        }
        else {
            $data = mysql_fetch_array($result);
            $recipient_id = $data['id'];
        }
        
        return $recipient_id;
    }
    
    function createCar($recipient_id, $vin, $make, $model, $year){
        $car_id = 0;
        $sql = "SELECT * FROM 1994_cars WHERE recipient_id = ".$recipient_id." AND vin = '".$vin."'";
    
        $result = mysql_query($sql);
        if(mysql_num_rows($result) == 0){
            $isql = "INSERT INTO 1994_cars (recipient_id, vin, make, model, year) VALUES "
                . "(".$recipient_id.",'".mysql_real_escape_string($vin)."','".mysql_real_escape_string(trim($make))."','".mysql_real_escape_string(trim($model))."','".mysql_real_escape_string($year)."')";
            mysql_query($isql);
            $car_id = mysql_insert_id();
        }
        
        return $car_id;
    }
    
    function addService($car_id, $mileage, $oil, $last_service, $store_id, $invoice_number){
        $sql = "SELECT * FROM 1994_servicestats WHERE car_id = ".$car_id." AND invoice_number = '".mysql_real_escape_string($invoice_number)."'";
      
        $result = mysql_query($sql);
        if(mysql_num_rows($result) == 0){
            // may need to change $last_service to NOW() here...
            $isql = "INSERT INTO 1994_servicestats (car_id, mileage, oil, last_service, store_id, invoice_number) VALUES "
                    . "(".$car_id.",'".$mileage."','".$oil."','".$last_service."',".(int)$store_id.",'".$invoice_number."')";
            mysql_query($isql);
        } 
    }
    
    function updateFields($recipient_id){
        if((int)$recipient_id > 0){
            $dsql = "DELETE FROM `recipients_optin` WHERE recipient_id = ".(int)$recipient_id." AND optin_value_id in (4180,4182,4183,4184,4185)";
            mysql_query($dsql);

            $sql = "SELECT ss.last_service, ss.store_id, c.year, c.make, c.model FROM `1994_servicestats` ss JOIN `1994_cars` c ON ss.car_id = c.id 
    WHERE c.recipient_id = ".(int)$recipient_id." ORDER BY ss.last_service DESC LIMIT 1";
         
            $result = mysql_query($sql);
            $data = mysql_fetch_array($result);
            
            $isql = "INSERT INTO recipients_optin (recipient_id, optin_value_id, date_value) VALUES (".(int)$recipient_id.",4180,'".mysql_real_escape_string($data['last_service'])."')";
            mysql_query($isql);
            $isql = "INSERT INTO recipients_optin (recipient_id, optin_value_id, int_value) VALUES (".(int)$recipient_id.",4182,".$data['store_id'].")";
            mysql_query($isql);
            $isql = "INSERT INTO recipients_optin (recipient_id, optin_value_id, int_value) VALUES (".(int)$recipient_id.",4183,".$data['year'].")";
            mysql_query($isql);
            $isql = "INSERT INTO recipients_optin (recipient_id, optin_value_id, string_value) VALUES (".(int)$recipient_id.",4184,'".mysql_real_escape_string(trim($data['make']))."')";
            mysql_query($isql);
            $isql = "INSERT INTO recipients_optin (recipient_id, optin_value_id, string_value) VALUES (".(int)$recipient_id.",4185,'".mysql_real_escape_string(trim($data['model']))."')";
            mysql_query($isql);
            return true;
        } else {
            return false;
        }
    }
    
    function updateDates($car_id){
        if((int)$car_id > 0){
            #print_r($row);
            $averagePerDay = $this->calculateAverage($car_id);
            #echo "Avg per day: ".$averagePerDay;
            #echo "\n";

            $days3k = round(3000 / $averagePerDay);
            $days5k = round(5000 / $averagePerDay);

            $sql = "SELECT mileage,last_service FROM 1994_servicestats WHERE car_id = ".(int)$car_id." ORDER BY last_service DESC LIMIT 1";
            
            $result = mysql_query($sql);
            $row = mysql_fetch_array($result);

            $time3k = strtotime($row['last_service']) + (86400 * $days3k);
            $date3k = date("Y-m-d", $time3k);
            $time5k = strtotime($row['last_service']) + (86400 * $days5k);
            $date5k = date("Y-m-d", $time5k);
            $time5k3w = strtotime($row['last_service']) + (86400 * ($days5k + 21));
            $date5k3w = date("Y-m-d", $time5k3w);

            $usql = "UPDATE 1994_cars SET expected_date_3k = '$date3k', expected_date_5k = '$date5k', expected_date_5k3week = '$date5k3w' WHERE id =  ".(int)$car_id;
            #echo $usql."\n";
            mysql_query($usql);
            return true;
        } else {
            return false;
        }
    }
    
    function calculateAverage($car_id){
	$sql = "SELECT mileage,last_service FROM 1994_servicestats WHERE car_id = ".(int)$car_id." ORDER BY last_service DESC LIMIT 3";
	echo  $sql;
        $result = mysql_query($sql);
	$firstMiles = 0;
	$firstDate = '';
	$lastMiles = 0;
	$lastDate = '';
	$count = 0;
        while($row = mysql_fetch_array($result)){
            print_r($row);
            $count++;
            if($count == 1){
                    $firstMiles = $row['mileage'];
                    $firstDate = $row['last_service'];
            }
            if($count == mysql_num_rows($result)){
                    $lastMiles = $row['mileage'];
                    $lastDate = $row['last_service'];
            }
        }

	

	$averagePerDay = 0;
	if($firstMiles > 0 && $lastMiles > 0){
		$milesDiff = $firstMiles - $lastMiles;
		$dateDiff = (strtotime($firstDate) - strtotime($lastDate)) / 86400;

                if($dateDiff >= 1){
                    $averagePerDay = $milesDiff / $dateDiff;
                }
                else{
                    $averagePerDay = 0;
                }
	}

	if($averagePerDay > 90){
		$averagePerDay = 90;
	}
	if($averagePerDay < 30){
		$averagePerDay = 30;
	}


	return $averagePerDay;
    }
    
    function process(){
        
        $sql = "SELECT cd.VIN, cd.Email, rh.mileage, cd.LastOil, rh.ReceiptDate, rh.ReceiptNum, rh.StoreNum, rh.VehYear, rh.VehMake, rh.VehModel 
            FROM receiptheaders rh 
            JOIN customerdetail cd 
            ON rh.AcctNum = cd.AcctNum 
            WHERE ReceiptDate >= '".$this->shopDate."'
            AND cd.Email > '';";
        
        $result = mysqli_query($this->adLink, $sql);
        if(mysqli_num_rows($result) > 0){
            echo "Attempting to process ".mysqli_num_rows($result)." records.<br/>";
            while($person = mysqli_fetch_array($result)){
                
                if(isset($person['Email'])){
                    $recipient_id = $this->findRecipientId($person['Email']); 
                } else {
                    $recipient_id = 0;
                }
                
                if($recipient_id == 0){
                    $recipient_id = $this->findRecipientByVin($person['VIN']); 
                }
                
                if($recipient_id == 0){
                    
                    // Create new recipient and pull data
                    echo "Creating Person...<br/>";
                    $recipient_id = $this->pullServiceDetailsFromAuto($person['VIN']); 
                    if($recipient_id !== false){
                        
                        $this->newPC++;
                    }
                    else{
                        echo "Failed creating ".$person['Email']."<br/>";
                    }
                } else {
                    #echo "Updating person: ".$person['Email']." (".$person['VIN'].")<br/>";
                    //Just insert service details
                    $car_id = $this->findCarIdByVin($person['VIN']);
                    
                    if($car_id == 0){
                        echo "Creating vehicle: ";
                        print_r($person);
                        echo "<br/>";
                        $this->existingPCNewCar++;
                        $car_id = $this->createCar($recipient_id, $person['VIN'], $person['VehMake'], $person['VehModel'], $person['VehYear']);
                    }
                    
                    if($car_id !== 0){
                        $this->addService($car_id, $person['mileage'], $person['LastOil'], $person['ReceiptDate'], $person['StoreNum'], $person['ReceiptNum']);
                        $this->existingPC++;
                    } 
                    else{
                        echo "Error adding service/vehicle ".$person['VIN']."<br/>";
                    }
                } 
            }
        } else {
            $recip = 'Nothing updated.';
            return $recip;
        }
       
        return true;
        
    }
}

$enrollment = new processEnrollment();

$recip = $enrollment->process();
if($recip){ 
    echo "\n\n".$enrollment->newPC." customers added for yesterday's reciepts and ".$enrollment->existingPC." customers updated. (".$enrollment->existingPCNewCar." new cars for existing customers)\n"; 
} else {
    echo "There was an error running processing<br/>";
}

$emailContents = str_replace("\n","<br/>\n",ob_get_contents());

$from = "";
$to = "";
$subject = "**** daily process for ".date("Y-m-d");

$sql = "INSERT INTO emailtosend_trans (tofield, fromfield, htmlpart, thesubject, replyto) VALUES "
        . "('".mysql_real_escape_string($to)."','".mysql_real_escape_string($from)."',"
        . "'".mysql_real_escape_string($emailContents)."','".mysql_real_escape_string($subject)."','".mysql_real_escape_string($from)."')";
mysql_query($sql);