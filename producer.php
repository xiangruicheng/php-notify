<?php
/**
 * 生产者示例
 * 只需要在需要触发异步通知的地方加入如下代码即可
 * 当然在相应的环境中也可以namespace去引入notify.php
 */
include 'notify.php';
$type = '1';
$data = ['id'=>'1', 'name'=>'xxx'];
$notify_url = 'http://www.baidu.com';
$msg_id = Notify::getInstance()->producer($type,$data,$notify_url);
echo $msg_id;