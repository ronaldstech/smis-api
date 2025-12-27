<?php

function db_update($table, $cv, $where)
{
	global $db;

	$contentValues = [];
	foreach ($cv as $key => $value) {
		array_push($contentValues, "`$key` = '$value'");
	}

	$whereClause = [];
	foreach ($where as $key => $value) {
		if(count(explode(" ", trim($key))) > 1){
			array_push($whereClause, "$key '$value' ");
		}
		else{
			array_push($whereClause, "`$key` = '$value' ");
		}
	}

	return $db->query("UPDATE `$table` SET ".implode(", ", $contentValues)." WHERE ".implode(" AND ", $whereClause));
}

function getData($table, $array){
	global $db;

	$wheres = [];

	foreach ($array as $key => $value) {
		if(count(explode(" ", trim($key))) > 1){
			$chars = explode(" ", trim($key));
			if(in_array($chars[1], ["in", "not"])){
				$key = $chars[0];
				$phrase = implode(" ", array_slice($chars, 1));
				$value = implode(",", $value);
				array_push($wheres, "$key $phrase ($value) ");
				continue;
			}
			array_push($wheres, "$key '$value' ");
		}
		else{
			array_push($wheres, "`$key` = '$value' ");
		}
	}

	return $db->query("SELECT * FROM `$table` WHERE ".implode(" AND ", $wheres))->fetch_assoc();
}

function getAll($table, $ref=null, $extra=null){
	global $db;

	if ($ref == null) {
		$read = $db->query("SELECT * FROM `$table` ");
		$rows = [];
		while ($row = $read->fetch_assoc()) {
			array_push($rows, $row);
		}

		return $rows;
	}
	elseif (is_array($ref)) {
		$wheres = [];

		foreach ($ref as $key => $value) {
			if(is_array($value)){
				if(count($value) == 0){
					array_push($wheres, "`$key` = '0' ");
					continue;
				}
				$values = [];
				foreach ($value as $v) {
					array_push($values, "'".$db->real_escape_string($v)."'");
				}
				array_push($wheres, "`$key` IN (".implode(",", $values).")");
			}
			else{
				$value = $db->real_escape_string($value);
				if(count(explode(" ", trim($key))) > 1){
					array_push($wheres, "$key '$value' ");
				}
				else{
					array_push($wheres, "`$key` = '$value' ");
				}
			}
		}

		//echo "SELECT * FROM `$table` WHERE ".implode(" AND ", $wheres);

		$read = $db->query("SELECT * FROM `$table` WHERE ".implode(" AND ", $wheres));
		$rows = [];
		while ($row = $read->fetch_assoc()) {
			array_push($rows, $row);
		}

		return $rows;
	}
	else{
		if ($extra != null) {
			$wheres = [];

			foreach ($extra as $key => $value) {
				if(count(explode(" ", trim($key))) > 1){
					array_push($wheres, "$key '$value' ");
				}
				else{
					array_push($wheres, "`$key` = '$value' ");
				}
			}

			$read = $db->query("SELECT * FROM `$table` WHERE ".implode(" AND ", $wheres));
		}
		else{
			$read = $db->query("SELECT * FROM `$table` ");
		}

		$rows = [];
		while ($row = $read->fetch_assoc()) {
			$rows[$row[$ref]] = $row;
		}

		return $rows;
	}
}


function db_default($table, $extra=null){
	global $db;

	$read = $db->query("SHOW columns FROM `$table`");
	$row = [];
	while ($r = $read->fetch_assoc()) {
		$row[$r['Field']] = $r['Type'] == "int" ? 0 :"";
	}

	if ($extra != null) {
		foreach ($extra as $key => $value) {
			$row[$key] = $value;
		}
	}

	return $row;
}

function guidv4($data = null) {
    // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
    $data = $data ?? random_bytes(16);
    assert(strlen($data) == 16);

    // Set version to 0100
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    // Set bits 6-7 to 10
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    // Output the 36 character UUID.
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function db_insert($table, $array)
{
	global $db;

	$columns = [];
	$values = [];
	$read = $db->query("SHOW COLUMNS FROM `$table`");
	while ($row = $read->fetch_assoc()) {
		array_push($columns, "`{$row['Field']}`");
		if ($row['Extra'] == "auto_increment") {
			array_push($values, "NULL");
		}
		else{
			$value = isset($array[$row['Field']]) ? $db->real_escape_string($array[$row['Field']]) : "0";
			array_push($values, "'$value'");
		}
	}

	$sql = "INSERT INTO `$table` (".implode(",",$columns).") VALUES (".implode(",",$values).")";
	$db->query($sql);
	return $db->insert_id;
}

function db_delete($table, $where)
{
	global $db;

	$whereClause = [];
	foreach ($where as $key => $value) {
		$value = $db->real_escape_string($value);
		array_push($whereClause, "`$key` = '$value'");
	}

	return $db->query("DELETE FROM `$table` WHERE ".implode(" AND ", $whereClause));
}

function db_read($table, $where, $ref=null){
	global $db;

	$whereClause = [];
	foreach ($where as $key => $value) {
		$value = $db->real_escape_string($value);
		array_push($whereClause, "`$key` = '$value'");
	}

	$res = $db->query("SELECT * FROM `$table` WHERE ".implode(" AND ", $whereClause));

	$rows = [];
	if ($ref == null) {
		while ($row = $res->fetch_assoc()) {
			array_push($rows, $row);
		}
	}
	else{
		while ($row = $res->fetch_assoc()) {
			$rows[$row[$ref]] = $row;
		}
	}

	return $rows;
}

class Crypto{
	public static function uid($length)
	{
		$characters = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '-', '_', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z'];
		$str = "";
		for ($i=0; $i < $length; $i++) { 
			$str .= $characters[rand(0,count($characters)-1)];
		}
		return $str;
	}

	public static function letters_numbers($length)
	{
		$characters = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z'];
		$str = "";
		for ($i=0; $i < $length; $i++) { 
			$str .= $characters[rand(0,count($characters)-1)];
		}
		return $str;
	}

	public static function letters($length)
	{
		$characters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z'];
		$str = "";
		for ($i=0; $i < $length; $i++) { 
			$str .= $characters[rand(0,count($characters)-1)];
		}
		return $str;
	}
}

function time_ago($time){
    $time = (int)trim($time);
	$labels = [
		['s', 60],
		['min', 3600],
		['h', 3600 * 24],
		['d', 3600 * 24 * 7],
		['w', 3600 * 24 * 7 * 4],
		['mon', 3600 * 24 * 7 * 30],
		['y', 3600 * 24 * 7 * 30 * 12]
	];

	$dif = time() - $time;

	$can = true;
	$label = null;
	$div = 1;

	if ($dif == 0) {
		return "now";
	}

	for ($i=0; $i < count($labels); $i++) { 
		if ($dif < $labels[$i][1]) {
			if($can){
				$can = false;
				$label = $labels[$i][0];

				if($i != 0){
					$div = $labels[$i-1][1];
				}
			}
		}
	}

	if ($label == null) {
		return "Unknown";
	}
	else{
		return floor($dif/$div).$label;
	}
}

?>