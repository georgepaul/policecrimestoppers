<?php
session_start();
$_SESSION = array();

header("Location: ".$_SERVER["HTTP_REFERER"]);

echo "logout";