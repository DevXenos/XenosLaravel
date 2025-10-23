<?php

namespace Xenos;

class Session {
	private static function Load(){
		if (session_status() == PHP_SESSION_NONE) {
			session_start();
		}
	}

	public static function Set($key, $value)
	{
		self::Load();
		$_SESSION[$key] = $value;
	}

	public static function Get($key)
	{
		self::Load();
		if (isset($_SESSION[$key])) {
			return $_SESSION[$key];
		}
		return null;
	}
}
