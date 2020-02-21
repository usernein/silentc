<?php
if (!file_exists('silentc.db')) {
	$db = new PDO('sqlite:silentc.db');
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
	$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
	$db->setAttribute(PDO::ATTR_TIMEOUT, 15);
	
	$db->query('
		CREATE TABLE channel (

			key INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,

			id INTEGER UNIQUE NOT NULL,

			date INTEGER NOT NULL,

			switch INTEGER DEFAULT 0,

			adm TEXT NOT NULL,

			timers TEXT NOT NULL,

			fixed_timers TEXT NOT NULL,

			block TEXT NOT NULL,

			patterns TEXT NOT NULL,

			last_editor INTEGER NULL,

			last_edit_time INTEGER NULL,

			is_channel TEXT null,

			blocklist TEXT NOT NULL,

			info TEXT NOT NULL,

			whitelist TEXT NOT NULL,

			mp_admins TEXT NOT NULL,

			twig TEXT NULL

		)
	');
	$db->query('
		CREATE TABLE user (

			key INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,

			id INTEGER UNIQUE NOT NULL,

			date INTEGER NOT NULL,

			waiting_param TEXT NULL,

			waiting_for TEXT NULL,

			waiting_back TEXT NULL,

			language TEXT NOT NULL,

			timezone TEXT NOT NULL

		)
	');
	$db->query('
	CREATE TABLE import (

		key INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,

		id INTEGER NOT NULL,

		date INTEGER NOT NULL,

		adm INTEGER NULL,

		imported INTEGER DEFAULT 0

	)
	');
	$db->query('
	CREATE TABLE twigs (
		`key` integer primary key autoincrement not null,
		info text not null,
		post text not null,
		twig text not null,
		date integer not null
		)
	');
	
}