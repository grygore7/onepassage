<?php
require_once 'config.php';

// Distruggi sessione
session_destroy();

// Redirect alla homepage
header('Location: index.php');
exit;
?>