<?php

require 'credentials.php';

class CacheDb {

	private $dbconn;
	const TABLENAME = 'api_cache';
	const CREATE_TABLE = <<< EOT
CREATE TABLE %s (
id integer primary key auto_increment,
endpoint varchar(32),
query JSON,
result JSON,
last_updated timestamp,
part varchar(20) AS (JSON_UNQUOTE(query->"$.part")),
apikey varchar(45) AS (JSON_UNQUOTE(query->"$.key")),
etag varchar(64) AS (JSON_UNQUOTE(query->"$.etag")),
key ep_part_key_ndx (endpoint,apikey,part)
);
EOT;
	const API_URL = "https://www.googleapis.com/youtube/v3/";


	public function __destruct() {
		$this->dbconn->close();
	}

	public function query_row($query) {
		//echo("query: $query\n");
		$result = $this->dbconn->query($query);
		if ($result !== TRUE) { // success with no result set or failure
			if ($result !== FALSE) { // success with result set
				if ($this->dbconn->affected_rows > 0) {;
					$obj = $result->fetch_object();
					$obj->affected_rows = $this->dbconn->affected_rows;
					$result->free();
				} else {
					$obj=TRUE;
				}
				return $obj;
			} else { // success with no result set
				return TRUE;
			}
		}
		return FALSE;
	}

	public function init() {
		global $mysql_server,$mysql_user,$mysql_password,$mysql_database;

		$this->dbconn = new mysqli($mysql_server, $mysql_user, $mysql_password, $mysql_database);

		if ($this->dbconn->connect_errno) {
			error_exit("Sorry, this website is experiencing problems.");
		}

		$obj = $this->query_row("SHOW TABLES LIKE '".self::TABLENAME."'");
		if ($obj === TRUE) {
			$this->query_row(sprintf(self::CREATE_TABLE,self::TABLENAME));
		}
	}

	public function lookup($endpoint,$query) {
		$jsonx = array();

		foreach ($query as $key => $value) {
			array_push($jsonx,"query->'\$.$key' = '$value'");
		}

		$query_string = "SELECT id,result,etag,unix_timestamp()-unix_timestamp(last_updated) as age from ".self::TABLENAME." ". 
				"WHERE endpoint = '".$endpoint."' and ". 
				"part = '".
				$query['part'].
				"' and ".
				"apikey = '".
				$query['key'].
				"' and ".
				join(' and ',$jsonx);

		return $this->query_row($query_string);
	}

	public function sanitize(&$obj) {
		foreach ($obj as $key => $val) {
			$this->escape($obj[$key]);
		}
	}

	public function escape(&$string) {
		$string = $this->dbconn->escape_string($string);
	}

	public function query_api($endpoint,$query,$etag = FALSE) {
		global $api_referer;

		// URL encoding breaks the google API because it doesn't urldecode the query string
		$url = self::API_URL.$endpoint."?";
		foreach($query as $key => $value) {
			$parms[] = $key."=".$value;
		}
		$url.=join('&',$parms);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$headers = [
		'Referer: '.$api_referer
		];
		if ($etag !== FALSE) $headers[]='If-None-Match: '.$etag;

		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		$result = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		if ($http_code == 304) return TRUE;
		return $result;
	}

	public function update_cache($id,$newdata,$metadata_update = FALSE) {
		$this->escape($newdata);

		// timestamps only update when the data is different, kinda defeats the purpose of a cache
		if ($metadata_update !== TRUE) {
			$query_string = "UPDATE ".self::TABLENAME." set ".
					"last_updated = NOW(), ".
					"result = '".$newdata."' ".
					"WHERE id = ".$id;
		} else {	// only update cache metadata if the contents are the same
			$query_string = "UPDATE ".self::TABLENAME." set ".
					"last_updated = NOW() ".
					"WHERE id = ".$id;
		}

		return $this->query_row($query_string);
	}

	public function insert_cache($endpoint,$query,$newdata) {
		$this->escape($newdata);

		$query_string = "INSERT INTO ".self::TABLENAME." set ".
				"endpoint = '".$endpoint."',".
				"query = '".json_encode($query)."',".
				"result = '".$newdata."'";

		return $this->query_row($query_string);
	}

	public function get_cache($endpoint,$query,$age) {
		$this->escape($endpoint);
		$this->sanitize($query);
		$result = $this->lookup($endpoint,$query);

		if ($result === TRUE) {
			$newdata = $this->query_api($endpoint,$query);

			$this->insert_cache($endpoint,$query,$newdata);
			return $newdata;
		} else if ($result !== TRUE && $result->age > $age) {
			$newdata = $this->query_api($endpoint,$query,$result->etag);

			if ($newdata === TRUE) {	// only metadata update
				$this->update_cache($result->id,$newdata,TRUE);

				$json=json_decode($result->result,true);
				$json['age']=$result->age;
				return json_encode($json);
			} else {
				$this->update_cache($result->id,$newdata);
				return $newdata;
			}
		} else if ($result !== FALSE) {
			return $result->result;
		}
	}
}

$cache = new CacheDb;

$cache->init();

function error_exit($msg) {
	echo json_encode($msg)."\n";
	exit;
}

if (isset($_GET['endpoint'])) {
	$endpoint = filter_var($_GET['endpoint'],FILTER_SANITIZE_FULL_SPECIAL_CHARS);
	if ($age === false) {
		error_exit(array("Error"=>"endpoint contains invalid characters"));
	}
	unset($_GET['endpoint']);
} else {
	error_exit(array("Error"=>"you must specify an endpoint"));
}

if (isset($_GET['age'])) {
	$cache_age = filter_var($_GET['age'], FILTER_SANITIZE_NUMBER_INT);
	if ($cache_age === false) {
		error_exit(array("Error"=>"age must be an integer > 0"));
	} else if ($cache_age === 0) {
		$cache_age = 3600;
	}
	unset($_GET['age']);
} else {
	$cache_age = 3600;
}


$query_data = filter_var_array($_GET, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

if ($query_data === false) {
	error_exit(array("Error"=>"query is invalid"));
}

$result = $cache->get_cache($endpoint,$query_data,$cache_age);

//var_dump($result);
echo($result."\n");

?>
