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
			echo json_encode("Sorry, this website is experiencing problems.");
			return FALSE;
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

		$query_string = "SELECT id,result,unix_timestamp()-unix_timestamp(last_updated) as age from ".self::TABLENAME." ". 
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

	public function query_api($endpoint,$query) {
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
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		$result = curl_exec($ch);
		curl_close($ch);

		return $result;
	}

	public function update_cache($id,$newdata) {
		$this->escape($newdata);

		// timestamps only update when the data is different, kinda defeats the purpose of a cache
		$query_string = "UPDATE ".self::TABLENAME." set ".
				"last_updated = NOW(), ".
				"result = '".$newdata."' ".
				"WHERE id = ".$id;

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

	public function get_cache($endpoint,$query) {
		$this->escape($endpoint);
		$this->sanitize($query);
		$result = $this->lookup($endpoint,$query);

		if ($result === TRUE) {
			$newdata = $this->query_api($endpoint,$query);

			$this->insert_cache($endpoint,$query,$newdata);
			return $newdata;
		} else if ($result !== TRUE && $result->age > 3600) {
			$newdata = $this->query_api($endpoint,$query);

			$this->update_cache($result->id,$newdata);
			return $newdata;
		} else if ($result !== FALSE) {
			return $result->result;
		}
	}
}

$cache = new CacheDb;

$cache->init();

if (isset($_GET['endpoint'])) {
	$endpoint=$_GET['endpoint'];
	unset($_GET['endpoint']);
} else {
exit;
}

$result = $cache->get_cache($endpoint,$_GET);

//var_dump($result);
echo($result."\n");

?>
