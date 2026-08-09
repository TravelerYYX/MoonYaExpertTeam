<?php
session_start();
require_once dirname(__DIR__) . '/config.php';

header('Location: ../index.php?office_popout=1');
exit;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" sizes="32x32" href="/icon.png">
<title>MoonYa 办公室</title>
<style>
<?php include dirname(__DIR__) . '/script/MoonYa-index/styles/css-01-base.php'; ?>
<?php include dirname(__DIR__) . '/script/MoonYa-index/styles/css-06-main.php'; ?>
<?php include dirname(__DIR__) . '/script/MoonYa-index/styles/css-17-office.php'; ?>
</style>
</head>
<body data-office-standalone="1">
<?php include dirname(__DIR__) . '/script/MoonYa-index/layouts/office-panel.php'; ?>
<?php include dirname(__DIR__) . '/script/MoonYa-index/modules/script-5-office.php'; ?>
</body>
</html>
