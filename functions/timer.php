<?php
function timer(string $format) {
	$data = preg_split('#(:|\.|\s)#', $format);
	
	foreach ($data as &$item) {
		if (strlen($item) == 1) {
			$item = "0{$item}";
		}
		if (strlen($item) == 3) {
			$item = substr($item, 1, 2);
		}
	}
	
	switch(count($data)) {
		case 1:
			$day = date('d.m.Y');
			$date = strtotime("{$day} {$data[0]}:00:00");
			if ($date <= time()) {
				$date = strtotime('+ 1 day', $date);
			}
			break;
		case 2:
			$day = date('d.m.Y');
			$date = strtotime("{$day} {$data[0]}:{$data[1]}:00");
			if ($date <= time()) {
				$date = strtotime('+ 1 day', $date);
			}
			break;
		case 3:
			$day = date("{$data[2]}.m.Y");
			$date = strtotime("{$day} {$data[0]}:{$data[1]}:00");
			if ($date <= time()) {
				$date = strtotime('+ 1 month', $date);
			}
			break;
		case 4:
			$day = date("{$data[2]}.{$data[3]}.Y");
			$date = strtotime("{$day} {$data[0]}:{$data[1]}:00");
			if ($date <= time()) {
				$date = strtotime('+ 1 year', $date);
			}
			break;
		case 5:
			if (strlen($data[4]) == 2) {
				$data[4] = "20{$data[4]}";
			}
			$day = date("{$data[2]}.{$data[3]}.{$data[4]}");
			$date = strtotime("{$day} {$data[0]}:{$data[1]}:00");
			break;
	}
	return $date;
}
function fixed_timer(string $format) {
	$data = preg_split('#(:|\.|\s)#', $format);
	$data[1] = $data[1] ?? '00';
	
	foreach ($data as &$item) {
		if (strlen($item) == 1) {
			$item = "0{$item}";
		}
		if (strlen($item) > 2) {
			$item = substr($item, 1, 2);
		}
	}
	
	$day = date('d.m.Y');
	$date = strtotime("{$day} {$data[0]}:{$data[1]}:00");
	
	return $date;
}